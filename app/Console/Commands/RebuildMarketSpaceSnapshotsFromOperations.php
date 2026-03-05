<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Operations\OperationType;
use App\Models\Operation;
use Illuminate\Console\Command;

class RebuildMarketSpaceSnapshotsFromOperations extends Command
{
    protected $signature = 'operations:rebuild-space-snapshots
        {--market-id= : Ограничить пересчёт одним market_id}
        {--dry-run : Только показать количество затронутых мест без изменений}';

    protected $description = 'Пересчитать snapshot market_spaces по applied операциям (tenant_switch/rent_rate_change/space_attrs_change)';

    public function handle(): int
    {
        $marketId = $this->option('market-id');
        $marketId = is_numeric($marketId) ? (int) $marketId : null;
        $dryRun = (bool) $this->option('dry-run');

        $query = Operation::query()
            ->where('entity_type', 'market_space')
            ->whereIn('type', [
                OperationType::TENANT_SWITCH,
                OperationType::RENT_RATE_CHANGE,
                OperationType::SPACE_ATTRS_CHANGE,
            ]);

        if ($marketId !== null && $marketId > 0) {
            $query->where('market_id', $marketId);
        }

        $pairs = []; // [ "marketId:spaceId" => [market_id, space_id] ]

        $query
            ->select(['id', 'market_id', 'entity_id', 'payload'])
            ->orderBy('id')
            ->chunkById(1000, function ($rows) use (&$pairs): void {
                foreach ($rows as $row) {
                    $payload = is_array($row->payload) ? $row->payload : [];
                    $spaceId = (int) ($payload['market_space_id'] ?? $row->entity_id ?? 0);
                    $mId = (int) ($row->market_id ?? 0);

                    if ($mId <= 0 || $spaceId <= 0) {
                        continue;
                    }

                    $pairs["{$mId}:{$spaceId}"] = [
                        'market_id' => $mId,
                        'space_id' => $spaceId,
                    ];
                }
            });

        $total = count($pairs);

        if ($total === 0) {
            $this->info('Нечего пересчитывать: подходящие операции не найдены.');
            return self::SUCCESS;
        }

        $this->info("Найдено торговых мест для пересчёта: {$total}");

        if ($dryRun) {
            $this->warn('DRY-RUN: изменения не применялись.');
            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        foreach ($pairs as $pair) {
            Operation::rebuildMarketSpaceSnapshot(
                (int) $pair['market_id'],
                (int) $pair['space_id'],
            );
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info('Готово: snapshot торговых мест пересчитан.');

        return self::SUCCESS;
    }
}

