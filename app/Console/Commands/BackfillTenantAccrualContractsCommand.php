<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\TenantAccruals\TenantAccrualContractResolver;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillTenantAccrualContractsCommand extends Command
{
    protected $signature = 'accruals:link-contracts
        {--market=1 : Market ID}
        {--period= : Only one period in YYYY-MM}
        {--limit=0 : Limit rows for testing}
        {--execute : Persist tenant_contract_id}';

    protected $description = 'Link tenant_accruals to tenant_contracts using tenant + place + period when a safe primary contract match exists.';

    public function handle(TenantAccrualContractResolver $resolver): int
    {
        $marketId = max(1, (int) $this->option('market'));
        $periodOption = trim((string) ($this->option('period') ?? ''));
        $limit = max(0, (int) $this->option('limit'));
        $execute = (bool) $this->option('execute');

        $query = DB::table('tenant_accruals')
            ->where('market_id', $marketId)
            ->whereNull('tenant_contract_id')
            ->whereNotNull('tenant_id')
            ->whereNotNull('market_space_id')
            ->orderBy('period')
            ->orderBy('id');

        if ($periodOption !== '') {
            $query->where('period', CarbonImmutable::createFromFormat('Y-m-d', $periodOption . '-01')->format('Y-m-d'));
        }

        if ($limit > 0) {
            $query->limit($limit);
        }

        $rows = $query->get(['id', 'tenant_id', 'market_space_id', 'period']);

        $stats = [
            'market_id' => $marketId,
            'mode' => $execute ? 'execute' : 'dry-run',
            'rows' => $rows->count(),
            'matched' => 0,
            'updated' => 0,
            'unresolved' => 0,
        ];

        $samples = [];

        foreach ($rows as $row) {
            $period = CarbonImmutable::parse((string) $row->period);
            $tenantContractId = $resolver->resolve(
                $marketId,
                (int) $row->tenant_id,
                (int) $row->market_space_id,
                $period,
            );

            if (! $tenantContractId) {
                $stats['unresolved']++;
                continue;
            }

            $stats['matched']++;
            $samples[] = [
                'tenant_accrual_id' => (int) $row->id,
                'tenant_contract_id' => $tenantContractId,
                'period' => (string) $row->period,
            ];

            if (! $execute) {
                continue;
            }

            DB::table('tenant_accruals')
                ->where('id', (int) $row->id)
                ->update([
                    'tenant_contract_id' => $tenantContractId,
                    'updated_at' => now(),
                ]);

            $stats['updated']++;
        }

        $this->line(json_encode([
            'stats' => $stats,
            'samples' => array_slice($samples, 0, 50),
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
