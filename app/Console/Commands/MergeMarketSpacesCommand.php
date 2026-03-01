<?php
# app/Console/Commands/MergeMarketSpacesCommand.php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MarketSpace;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MergeMarketSpacesCommand extends Command
{
    protected $signature = 'market:spaces-merge
        {from : ID дубля (который гасим)}
        {to : ID канонического места (которое оставляем)}
        {--dry-run : Только показать, что будет изменено}';

    protected $description = 'Слияние дублей market_spaces: перенос ссылок и деактивация дубля';

    public function handle(): int
    {
        $fromId = (int) $this->argument('from');
        $toId = (int) $this->argument('to');
        $dryRun = (bool) $this->option('dry-run');

        if ($fromId <= 0 || $toId <= 0 || $fromId === $toId) {
            $this->error('Invalid ids');
            return self::FAILURE;
        }

        /** @var MarketSpace|null $from */
        $from = MarketSpace::query()->find($fromId);
        /** @var MarketSpace|null $to */
        $to = MarketSpace::query()->find($toId);

        if (! $from || ! $to) {
            $this->error('MarketSpace not found');
            return self::FAILURE;
        }

        if ((int) $from->market_id !== (int) $to->market_id) {
            $this->error('Different market_id: merge запрещён');
            return self::FAILURE;
        }

        $marketId = (int) $from->market_id;

        $this->info("market_id={$marketId}");
        $this->info("from={$fromId} number=" . (string) ($from->number ?? '') . " code=" . (string) ($from->code ?? ''));
        $this->info("to={$toId}   number=" . (string) ($to->number ?? '') . " code=" . (string) ($to->code ?? ''));

        $targets = [
            ['table' => 'tenant_contracts', 'column' => 'market_space_id'],
            ['table' => 'tenant_accruals', 'column' => 'market_space_id'],
            ['table' => 'market_space_tenant_histories', 'column' => 'market_space_id'],
            ['table' => 'market_space_rent_rate_histories', 'column' => 'market_space_id'],
        ];

        $counts = [];

        foreach ($targets as $t) {
            $table = $t['table'];
            $col = $t['column'];

            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $col)) {
                continue;
            }

            $counts["{$table}.{$col}"] = (int) DB::table($table)->where($col, $fromId)->count();
        }

        $this->line('References to move:');
        foreach ($counts as $k => $c) {
            $this->line(" - {$k}: {$c}");
        }

        if ($dryRun) {
            $this->warn('DRY RUN: nothing changed');
            return self::SUCCESS;
        }

        DB::beginTransaction();

        try {
            foreach ($targets as $t) {
                $table = $t['table'];
                $col = $t['column'];

                if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $col)) {
                    continue;
                }

                DB::table($table)->where($col, $fromId)->update([$col => $toId]);
            }

            // Если у "to" пусто, а у "from" заполнено — переносим “операционную” правду.
            if (Schema::hasColumn('market_spaces', 'tenant_id')) {
                if (blank($to->tenant_id) && filled($from->tenant_id)) {
                    $to->tenant_id = $from->tenant_id;
                }
            }

            if (Schema::hasColumn('market_spaces', 'status')) {
                if (blank($to->status) && filled($from->status)) {
                    $to->status = $from->status;
                }
            }

            $to->save();

            // Дубль гасим
            if (Schema::hasColumn('market_spaces', 'is_active')) {
                $from->is_active = false;
            }

            if (Schema::hasColumn('market_spaces', 'notes')) {
                $note = trim((string) ($from->notes ?? ''));
                $suffix = "duplicate_of={$toId}";
                $from->notes = $note === '' ? $suffix : ($note . "\n" . $suffix);
            }

            // tenant_id у дубля лучше обнулить, чтобы не светился как занятый
            if (Schema::hasColumn('market_spaces', 'tenant_id')) {
                $from->tenant_id = null;
            }

            $from->save();

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        $this->info('OK: merged');
        return self::SUCCESS;
    }
}