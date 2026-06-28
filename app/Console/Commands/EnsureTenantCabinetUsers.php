<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Cabinet\TenantCabinetUserService;
use App\Support\MarketContext;
use Illuminate\Console\Command;

class EnsureTenantCabinetUsers extends Command
{
    protected $signature = 'tenants:ensure-cabinet-users
        {--market= : Ограничить конкретным market_id}
        {--limit=0 : Максимум арендаторов для обработки}
        {--execute : Выполнить изменения (по умолчанию dry-run)}';

    protected $description = 'Проверить/создать primary кабинетных пользователей арендаторов.';

    public function handle(TenantCabinetUserService $cabinetUsers): int
    {
        $rawMarketId = $this->option('market');
        $marketId = filled($rawMarketId) ? (int) $rawMarketId : null;
        $limit = max(0, (int) ($this->option('limit') ?? 0));
        $execute = (bool) $this->option('execute');

        if ($marketId !== null) {
            return app(MarketContext::class)->withMarket(
                $marketId,
                fn (): int => $this->ensureCabinetUsers($cabinetUsers, $marketId, $limit, $execute),
            );
        }

        return $this->ensureCabinetUsers($cabinetUsers, null, $limit, $execute);
    }

    private function ensureCabinetUsers(
        TenantCabinetUserService $cabinetUsers,
        ?int $marketId,
        int $limit,
        bool $execute,
    ): int {
        $query = Tenant::query()->orderBy('id');
        if ($marketId !== null) {
            $query->where('market_id', $marketId);
        }
        if ($limit > 0) {
            $query->limit($limit);
        }

        $tenants = $query->get(['id', 'market_id', 'name']);

        $missing = 0;
        $fixedRole = 0;
        $created = 0;
        $total = $tenants->count();

        $this->line('mode='.($execute ? 'EXECUTE' : 'DRY-RUN'));
        $this->line('scope='.($marketId !== null ? ('market_id='.$marketId) : 'all'));
        $this->line('tenants='.$total);

        foreach ($tenants as $tenant) {
            $primary = $cabinetUsers->resolvePrimaryUser($tenant);

            if (! $primary) {
                $missing++;

                if ($execute) {
                    $createdUser = $cabinetUsers->ensurePrimaryUser($tenant);
                    $created++;
                    $this->line(sprintf(
                        ' + tenant_id=%d created user_id=%d email=%s',
                        (int) $tenant->id,
                        (int) $createdUser->id,
                        (string) $createdUser->email,
                    ));
                } else {
                    $this->line(sprintf(
                        ' - tenant_id=%d missing primary cabinet user',
                        (int) $tenant->id,
                    ));
                }

                continue;
            }

            $hadCabinetRole = $primary->hasAnyRole(['merchant', 'merchant-user']);
            if (! $hadCabinetRole) {
                $fixedRole++;
                if ($execute) {
                    $cabinetUsers->ensureCabinetRole($primary, 'merchant');
                    $this->line(sprintf(
                        ' + tenant_id=%d user_id=%d role assigned=merchant',
                        (int) $tenant->id,
                        (int) $primary->id,
                    ));
                } else {
                    $this->line(sprintf(
                        ' - tenant_id=%d user_id=%d without cabinet role',
                        (int) $tenant->id,
                        (int) $primary->id,
                    ));
                }
            }
        }

        $this->newLine();
        $this->info('Summary:');
        $this->line(' missing_primary='.$missing);
        $this->line(' role_issues='.$fixedRole);
        $this->line(' created='.$created);

        if (! $execute) {
            $this->warn('DRY RUN: no changes applied. Use --execute to apply.');
        }

        return self::SUCCESS;
    }
}
