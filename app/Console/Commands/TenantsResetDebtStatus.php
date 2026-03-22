<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class TenantsResetDebtStatus extends Command
{
    protected $signature = 'tenants:reset-debt-status 
                            {--market= : Market ID (optional)}
                            {--dry-run : Run in dry-run mode}';

    protected $description = 'Сбросить ручной статус задолженности у арендаторов (переключить на авто из 1С)';

    public function handle(): int
    {
        $marketId = $this->option('market');
        $dryRun = $this->option('dry-run');

        $query = Tenant::query()
            ->whereNotNull('debt_status');

        if ($marketId) {
            $query->where('market_id', (int) $marketId);
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
                $this->line("  ... и ещё " . ($count - 10) . " арендаторов");
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
