<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Support\MarketContext;
use Illuminate\Console\Command;

class TenantsResetDebtStatus extends Command
{
    protected $signature = 'tenants:reset-debt-status 
                            {--market= : Market ID (optional)}
                            {--dry-run : Run in dry-run mode}
                            {--execute : Apply changes (default: dry-run)}';

    protected $description = 'Сбросить ручной статус задолженности у арендаторов (переключить на авто из 1С)';

    public function handle(): int
    {
        $rawMarketId = $this->option('market');
        $marketId = filled($rawMarketId) ? (int) $rawMarketId : null;
        $execute = (bool) $this->option('execute');
        $dryRun = ! $execute || (bool) $this->option('dry-run');

        if ($execute && (bool) $this->option('dry-run')) {
            $this->error('Use either --execute or --dry-run, not both.');

            return self::FAILURE;
        }

        if ($marketId !== null) {
            return app(MarketContext::class)->withMarket(
                $marketId,
                fn (): int => $this->resetDebtStatus($marketId, $dryRun),
            );
        }

        return $this->resetDebtStatus(null, $dryRun);
    }

    private function resetDebtStatus(?int $marketId, bool $dryRun): int
    {
        $query = Tenant::query()
            ->whereNotNull('debt_status');

        if ($marketId !== null) {
            $query->where('market_id', $marketId);
        }

        $count = $query->count();

        if ($count === 0) {
            $this->info('Нет арендаторов с ручным статусом задолженности.');

            return self::SUCCESS;
        }

        $this->info("Найдено арендаторов с ручным статусом: {$count}");

        if ($dryRun) {
            $this->warn('[DRY-RUN] Обновление не выполняется');
            $query->limit(10)->get(['id', 'name', 'debt_status'])->each(function ($t) {
                $this->line("  - ID:{$t->id} | {$t->name} | текущий: {$t->debt_status}");
            });
            if ($count > 10) {
                $this->line('  ... и ещё '.($count - 10).' арендаторов');
            }

            return self::SUCCESS;
        }

        $this->warn('Обновление арендаторов...');

        $updated = $query->update([
            'debt_status' => null,
            'debt_status_updated_at' => null,
        ]);

        $this->info("✅ Обновлено: {$updated} арендаторов");
        $this->info('Теперь статус задолженности рассчитывается автоматически из данных 1С (contract_debts)');

        return self::SUCCESS;
    }
}
