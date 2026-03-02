<?php
# app/Console/Commands/ReportMarketSpaceKeyCollisions.php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MarketIntegration;
use App\Models\MarketSpace;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ReportMarketSpaceKeyCollisions extends Command
{
    protected $signature = 'market:spaces-collisions
        {--market= : Market ID (по умолчанию: market_id из активной 1C интеграции)}
        {--limit=15 : Сколько групп вывести (0 = все)}';

    protected $description = 'Отчёт по коллизиям ключей торговых мест (number/code) для интеграции с 1С';

    public function handle(): int
    {
        $marketId = $this->resolveMarketId();

        $spaces = MarketSpace::query()
            ->where('market_id', $marketId)
            ->get(['id', 'number', 'code', 'display_name', 'tenant_id', 'status', 'is_active', 'updated_at']);

        if ($spaces->isEmpty()) {
            $this->warn("No market_spaces for market_id={$marketId}");
            return self::SUCCESS;
        }

        // keyVariant => set(ids)
        $map = [];

        foreach ($spaces as $sp) {
            foreach ([(string) ($sp->number ?? ''), (string) ($sp->code ?? '')] as $raw) {
                $raw = trim($raw);
                if ($raw === '') {
                    continue;
                }

                foreach ($this->spaceKeyVariants($raw) as $v) {
                    $map[$v] ??= [];
                    $map[$v][$sp->id] = true;
                }
            }
        }

        // Сгруппируем коллизии по набору id (чтобы не было мусора вида P51/p51/П-5-1)
        $groups = []; // idsSignature => ['ids'=>[...], 'keys'=>[...]]
        foreach ($map as $key => $idsSet) {
            $ids = array_keys($idsSet);
            if (count($ids) <= 1) {
                continue;
            }

            sort($ids);
            $sig = implode('-', $ids);

            $groups[$sig] ??= ['ids' => $ids, 'keys' => []];
            $groups[$sig]['keys'][] = $key;
        }

        // Сортируем: сначала группы с большим числом id, потом по sig
        uasort($groups, static function (array $a, array $b): int {
            $c = count($b['ids']) <=> count($a['ids']);
            return $c !== 0 ? $c : (implode('-', $a['ids']) <=> implode('-', $b['ids']));
        });

        $totalGroups = count($groups);
        $this->info("market_id={$marketId}");
        $this->info("collision_groups={$totalGroups}");

        $limit = (int) $this->option('limit');
        if ($limit < 0) {
            $limit = 15;
        }

        $i = 0;
        foreach ($groups as $sig => $g) {
            $i++;
            if ($limit !== 0 && $i > $limit) {
                break;
            }

            $ids = $g['ids'];
            $keys = array_values(array_unique($g['keys']));
            sort($keys);

            $this->line(str_repeat('-', 80));
            $this->line("GROUP #{$i}: ids=[" . implode(', ', $ids) . ']');
            $this->line('Keys (examples): ' . implode(' | ', array_slice($keys, 0, 12)) . (count($keys) > 12 ? ' | ...' : ''));

            $rows = $spaces->whereIn('id', $ids)->values()->map(function ($sp) {
                return [
                    'id' => (int) $sp->id,
                    'number' => $sp->number,
                    'code' => $sp->code,
                    'display_name' => $sp->display_name,
                    'tenant_id' => $sp->tenant_id,
                    'status' => $sp->status,
                    'is_active' => $sp->is_active,
                    'updated_at' => (string) ($sp->updated_at ?? ''),
                ];
            })->all();

            // Простая “подсказка” канона (не автоматическое решение!)
            $winnerId = $this->suggestWinnerId($rows);
            $this->line("Suggested canonical id (heuristic): {$winnerId}");

            $this->line(json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        }

        return self::SUCCESS;
    }

    private function resolveMarketId(): int
    {
        $opt = $this->option('market');
        if (is_numeric($opt) && (int) $opt > 0) {
            return (int) $opt;
        }

        $mi = MarketIntegration::query()
            ->where('type', MarketIntegration::TYPE_1C)
            ->where('status', 'active')
            ->first();

        return (int) ($mi?->market_id ?? 1);
    }

    /**
     * @return list<string>
     */
    private function spaceKeyVariants(string $raw): array
    {
        $rawTrim = trim($raw);

        $upper = mb_strtoupper($rawTrim, 'UTF-8');

        $noSpaces = preg_replace('/\s+/u', '', $upper) ?? $upper;
        $normalizedSlashes = str_replace(['\\', '／'], '/', $noSpaces);
        $normalizedDashes = str_replace(['–', '—'], '-', $normalizedSlashes);
        $collapsedSlashes = preg_replace('#/+#', '/', $normalizedDashes) ?? $normalizedDashes;

        $compact = str_replace(['/', '-'], '', $collapsedSlashes);

        $slashToDash = str_replace('/', '-', $collapsedSlashes);
        $dashToSlash = str_replace('-', '/', $collapsedSlashes);

        $slug = Str::lower(Str::slug($rawTrim, '-'));

        $alnum = preg_replace('/[^0-9A-ZА-ЯЁ]/u', '', $collapsedSlashes) ?? $collapsedSlashes;

        $variants = [
            $rawTrim,
            $upper,
            $collapsedSlashes,
            $compact,
            $slashToDash,
            str_replace(['/', '-'], '', $slashToDash),
            $dashToSlash,
            str_replace(['/', '-'], '', $dashToSlash),
            $slug,
            $alnum,
        ];

        $variants = array_filter($variants, static fn ($v) => is_string($v) && trim($v) !== '');

        return array_values(array_unique($variants));
    }

    /**
     * Подсказка “какой id оставить” — только эвристика для ускорения ручной проверки.
     * Правило: активный > с tenant_id > статус не free > более новый updated_at > меньший id.
     *
     * @param array<int, array<string,mixed>> $rows
     */
    private function suggestWinnerId(array $rows): int
    {
        usort($rows, static function (array $a, array $b): int {
            $aActive = (bool) ($a['is_active'] ?? false);
            $bActive = (bool) ($b['is_active'] ?? false);
            if ($aActive !== $bActive) {
                return $bActive <=> $aActive;
            }

            $aHasTenant = ! empty($a['tenant_id']);
            $bHasTenant = ! empty($b['tenant_id']);
            if ($aHasTenant !== $bHasTenant) {
                return $bHasTenant <=> $aHasTenant;
            }

            $aStatus = (string) ($a['status'] ?? '');
            $bStatus = (string) ($b['status'] ?? '');
            $aGood = $aStatus !== '' && $aStatus !== 'free';
            $bGood = $bStatus !== '' && $bStatus !== 'free';
            if ($aGood !== $bGood) {
                return $bGood <=> $aGood;
            }

            $aUpd = (string) ($a['updated_at'] ?? '');
            $bUpd = (string) ($b['updated_at'] ?? '');
            if ($aUpd !== $bUpd) {
                return $bUpd <=> $aUpd;
            }

            return ((int) $a['id']) <=> ((int) $b['id']);
        });

        return (int) ($rows[0]['id'] ?? 0);
    }
}