<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

class AuditTenants extends Command
{
    protected $signature = 'tenants:audit
        {--market= : Market ID (по умолчанию: все рынки)}
        {--limit=20 : Максимум групп дублей для каждого правила}';

    protected $description = 'Audit tenants data for duplicates and data quality issues';

    public function handle(): int
    {
        $this->info('--- Tenants Audit ---');

        $marketId = $this->resolveMarketId();
        $limit = max(1, (int) $this->option('limit'));

        $total = Tenant::query()
            ->when($marketId !== null, fn ($q) => $q->where('market_id', $marketId))
            ->count();

        $scope = $marketId === null ? 'all markets' : "market_id={$marketId}";
        $this->line("Scope: {$scope}");
        $this->line("Total tenants: {$total}");

        $this->reportGroups(
            title: 'Duplicate by external_id',
            keyExpression: 'external_id',
            whereSql: "coalesce(external_id, '') <> ''",
            limit: $limit,
            marketId: $marketId,
        );

        $this->reportGroups(
            title: 'Duplicate by one_c_uid',
            keyExpression: 'one_c_uid::text',
            whereSql: 'one_c_uid is not null',
            limit: $limit,
            marketId: $marketId,
        );

        $this->reportGroups(
            title: 'Duplicate by INN',
            keyExpression: 'inn',
            whereSql: "coalesce(inn, '') <> ''",
            limit: $limit,
            marketId: $marketId,
        );

        $this->reportGroups(
            title: 'Duplicate by normalized name',
            keyExpression: "lower(regexp_replace(name, '\\s+', ' ', 'g'))",
            whereSql: "coalesce(name, '') <> ''",
            limit: $limit,
            marketId: $marketId,
        );

        return self::SUCCESS;
    }

    private function resolveMarketId(): ?int
    {
        $opt = $this->option('market');

        if (is_numeric($opt) && (int) $opt > 0) {
            return (int) $opt;
        }

        return null;
    }

    private function reportGroups(
        string $title,
        string $keyExpression,
        string $whereSql,
        int $limit,
        ?int $marketId
    ): void {
        $marketFilterSql = $marketId !== null ? ' AND market_id = :market_id' : '';

        $sql = "
            SELECT
                market_id,
                {$keyExpression} AS dup_key,
                COUNT(*)::int AS cnt,
                string_agg(id::text, ',' ORDER BY id) AS ids
            FROM tenants
            WHERE {$whereSql}{$marketFilterSql}
            GROUP BY market_id, {$keyExpression}
            HAVING COUNT(*) > 1
            ORDER BY cnt DESC, market_id, dup_key
            LIMIT {$limit}
        ";

        $bindings = $marketId !== null ? ['market_id' => $marketId] : [];
        $groups = DB::select($sql, $bindings);

        $this->line('');
        $this->info($title . ': ' . count($groups));

        if ($groups === []) {
            return;
        }

        foreach ($groups as $idx => $group) {
            $ids = array_values(array_filter(array_map('intval', explode(',', (string) $group->ids))));
            if ($ids === []) {
                continue;
            }

            $tenants = Tenant::query()
                ->whereIn('id', $ids)
                ->orderByDesc(
                    DB::raw('(SELECT COUNT(*) FROM market_spaces ms WHERE ms.tenant_id = tenants.id)')
                )
                ->orderByDesc(
                    DB::raw('(SELECT COUNT(*) FROM tenant_contracts tc WHERE tc.tenant_id = tenants.id)')
                )
                ->orderBy('id')
                ->get([
                    'id',
                    'market_id',
                    'name',
                    'short_name',
                    'external_id',
                    'one_c_uid',
                    'inn',
                    'kpp',
                    'is_active',
                ]);

            $this->line(str_repeat('-', 90));
            $this->line(sprintf(
                'Group #%d | market_id=%d | key=%s | tenants=%d',
                $idx + 1,
                (int) $group->market_id,
                (string) $group->dup_key,
                count($ids)
            ));

            foreach ($tenants as $tenant) {
                $spacesCount = DB::table('market_spaces')->where('tenant_id', $tenant->id)->count();
                $contractsCount = DB::table('tenant_contracts')->where('tenant_id', $tenant->id)->count();
                $accrualsCount = DB::table('tenant_accruals')->where('tenant_id', $tenant->id)->count();
                $requestsCount = DB::table('tenant_requests')->where('tenant_id', $tenant->id)->count();

                $this->line(sprintf(
                    '  - id=%d | active=%s | name="%s" | ext="%s" | inn="%s" | spaces=%d | contracts=%d | accruals=%d | requests=%d',
                    (int) $tenant->id,
                    $tenant->is_active ? 'yes' : 'no',
                    (string) ($tenant->name ?? ''),
                    (string) ($tenant->external_id ?? ''),
                    (string) ($tenant->inn ?? ''),
                    $spacesCount,
                    $contractsCount,
                    $accrualsCount,
                    $requestsCount
                ));
            }
        }
    }
}
