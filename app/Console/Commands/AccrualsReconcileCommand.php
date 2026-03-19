<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AccrualsReconcileCommand extends Command
{
    protected $signature = 'accruals:reconcile
        {--market= : Market ID (required)}
        {--period= : Period in YYYY-MM (e.g. 2026-01)}
        {--tenant= : Filter by tenant ID (optional)}
        {--limit=0 : Limit breakdown rows (0 = no limit)}
        ';

    protected $description = 'Read-only reconciliation report: compare 1C accruals vs CSV/Excel accruals for the same period';

    public function handle(): int
    {
        $marketId = (int) $this->option('market');
        $period = trim((string) $this->option('period'));
        $tenantId = $this->option('tenant') ? (int) $this->option('tenant') : null;
        $limit = (int) ($this->option('limit') ?? 0);

        if (!$marketId) {
            $this->error('Market ID is required. Use --market=1');
            return self::FAILURE;
        }

        if (!$period) {
            $this->error('Period is required. Use --period=2026-01');
            return self::FAILURE;
        }

        // Validate period format
        if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
            $this->error('Period must be in YYYY-MM format (e.g. 2026-01)');
            return self::FAILURE;
        }

        $periodDate = $period . '-01';

        $this->info('=== ACCRUALS RECONCILIATION REPORT ===');
        $this->newLine();
        $this->info('Settings:');
        $this->line("  market_id: {$marketId}");
        $this->line("  period: {$period}");
        if ($tenantId) {
            $this->line("  tenant_id: {$tenantId}");
        }
        $this->newLine();

        // Overall summary
        $summary = $this->getSummary($marketId, $periodDate, $tenantId);

        $this->info('## Summary');
        $this->table(
            ['Metric', '1C', 'CSV/Excel', 'Diff', 'Status'],
            [
                ['Total Rows', $summary['total_rows_1c'], $summary['total_rows_csv'], $summary['total_rows_diff'], $this->rowStatus($summary['total_rows_diff'] === 0)],
                ['Total Sum (with VAT)', $this->formatMoney($summary['total_sum_1c']), $this->formatMoney($summary['total_sum_csv']), $this->formatMoney($summary['total_sum_diff']), $this->rowStatus(abs($summary['total_sum_diff']) < 0.01)],
                ['Matched Tenants', $summary['matched_tenants_count'], '-', '-', 'info'],
                ['Rows Only in 1C', $summary['rows_only_in_1c'], '-', '-', 'warning'],
                ['Rows Only in CSV', $summary['rows_only_in_csv'], '-', '-', 'warning'],
            ]
        );

        $this->newLine();

        // Breakdown by tenant
        $breakdown = $this->getBreakdown($marketId, $periodDate, $tenantId, $limit);

        if ($breakdown->isNotEmpty()) {
            $this->info('## Breakdown by Tenant');
            $this->table(
                ['Tenant', 'Rows 1C', 'Rows CSV', 'Sum 1C', 'Sum CSV', 'Diff', 'Status'],
                $breakdown->map(fn($row) => [
                    $row->tenant_name,
                    $row->rows_1c,
                    $row->rows_csv,
                    $this->formatMoney($row->sum_1c),
                    $this->formatMoney($row->sum_csv),
                    $this->formatMoney($row->diff_sum),
                    $this->tenantStatus($row),
                ])->toArray()
            );

            $this->newLine();
            $this->info('Status legend:');
            $this->line('  ok = sums match (diff < 0.01)');
            $this->line('  mismatch = sums differ');
            $this->line('  only_1c = tenant has accruals only in 1C');
            $this->line('  only_csv = tenant has accruals only in CSV');
        }

        $this->newLine();
        $this->info('## Notes');
        $this->line('- This is a READ-ONLY report. No data was modified.');
        $this->line('- Source field: tenant_accruals.source = "1c" or "excel"');
        $this->line('- Sum field: tenant_accruals.total_with_vat');

        return self::SUCCESS;
    }

    private function getSummary(int $marketId, string $periodDate, ?int $tenantId): array
    {
        $baseQuery = DB::table('tenant_accruals')
            ->where('market_id', $marketId)
            ->where('period', $periodDate);

        if ($tenantId) {
            $baseQuery->where('tenant_id', $tenantId);
        }

        // 1C totals
        $stats1c = (clone $baseQuery)
            ->where('source', '1c')
            ->selectRaw('COUNT(*) as rows, COALESCE(SUM(total_with_vat), 0) as sum')
            ->first();

        // CSV/Excel totals
        $statsCsv = (clone $baseQuery)
            ->whereIn('source', ['excel', 'csv'])
            ->selectRaw('COUNT(*) as rows, COALESCE(SUM(total_with_vat), 0) as sum')
            ->first();

        // Matched tenants (tenants that have accruals in both sources)
        $matchedTenants = DB::table('tenant_accruals as t1')
            ->join('tenant_accruals as t2', function($join) use ($marketId, $periodDate) {
                $join->on('t1.tenant_id', '=', 't2.tenant_id')
                     ->where('t1.market_id', '=', $marketId)
                     ->where('t2.market_id', '=', $marketId)
                     ->where('t1.period', '=', $periodDate)
                     ->where('t2.period', '=', $periodDate)
                     ->where('t1.source', '=', '1c')
                     ->whereIn('t2.source', ['excel', 'csv']);
            })
            ->select('t1.tenant_id')
            ->distinct();

        if ($tenantId) {
            $matchedTenants->where('t1.tenant_id', $tenantId);
        }

        $matchedCount = $matchedTenants->count();

        // Rows only in 1C (tenants that have 1C but no CSV)
        $only1c = DB::table('tenant_accruals as t1')
            ->where('t1.market_id', $marketId)
            ->where('t1.period', $periodDate)
            ->where('t1.source', '1c')
            ->whereNotIn('t1.tenant_id', function($q) use ($marketId, $periodDate) {
                $q->select('tenant_id')
                  ->from('tenant_accruals')
                  ->where('market_id', $marketId)
                  ->where('period', $periodDate)
                  ->whereIn('source', ['excel', 'csv']);
            });

        if ($tenantId) {
            $only1c->where('t1.tenant_id', $tenantId);
        }

        $rowsOnly1c = $only1c->count();

        // Rows only in CSV (tenants that have CSV but no 1C)
        $onlyCsv = DB::table('tenant_accruals as t1')
            ->where('t1.market_id', $marketId)
            ->where('t1.period', $periodDate)
            ->whereIn('t1.source', ['excel', 'csv'])
            ->whereNotIn('t1.tenant_id', function($q) use ($marketId, $periodDate) {
                $q->select('tenant_id')
                  ->from('tenant_accruals')
                  ->where('market_id', $marketId)
                  ->where('period', $periodDate)
                  ->where('source', '1c');
            });

        if ($tenantId) {
            $onlyCsv->where('t1.tenant_id', $tenantId);
        }

        $rowsOnlyCsv = $onlyCsv->count();

        $sum1c = (float) ($stats1c->sum ?? 0);
        $sumCsv = (float) ($statsCsv->sum ?? 0);

        return [
            'total_rows_1c' => (int) ($stats1c->rows ?? 0),
            'total_rows_csv' => (int) ($statsCsv->rows ?? 0),
            'total_rows_diff' => (int) ($stats1c->rows ?? 0) - (int) ($statsCsv->rows ?? 0),
            'total_sum_1c' => $sum1c,
            'total_sum_csv' => $sumCsv,
            'total_sum_diff' => $sum1c - $sumCsv,
            'matched_tenants_count' => $matchedCount,
            'rows_only_in_1c' => $rowsOnly1c,
            'rows_only_in_csv' => $rowsOnlyCsv,
        ];
    }

    private function getBreakdown(int $marketId, string $periodDate, ?int $tenantId, int $limit): \Illuminate\Support\Collection
    {
        $query = DB::table('tenant_accruals as ta')
            ->join('tenants as t', 't.id', '=', 'ta.tenant_id')
            ->where('ta.market_id', $marketId)
            ->where('ta.period', $periodDate)
            ->selectRaw('
                ta.tenant_id,
                t.name as tenant_name,
                SUM(CASE WHEN ta.source = "1c" THEN 1 ELSE 0 END) as rows_1c,
                SUM(CASE WHEN ta.source IN ("excel", "csv") THEN 1 ELSE 0 END) as rows_csv,
                COALESCE(SUM(CASE WHEN ta.source = "1c" THEN ta.total_with_vat ELSE 0 END), 0) as sum_1c,
                COALESCE(SUM(CASE WHEN ta.source IN ("excel", "csv") THEN ta.total_with_vat ELSE 0 END), 0) as sum_csv
            ')
            ->groupBy('ta.tenant_id', 't.name')
            ->orderByDesc('sum_1c');

        if ($tenantId) {
            $query->where('ta.tenant_id', $tenantId);
        }

        if ($limit > 0) {
            $query->limit($limit);
        }

        return $query->get()->map(function($row) {
            $row->diff_sum = (float) $row->sum_1c - (float) $row->sum_csv;
            return $row;
        });
    }

    private function formatMoney(float $amount): string
    {
        return number_format(abs($amount), 2, '.', ' ') . ($amount < 0 ? ' (-)' : '');
    }

    private function rowStatus(bool $ok): string
    {
        return $ok ? 'ok' : 'mismatch';
    }

    private function tenantStatus(object $row): string
    {
        $has1c = (int) $row->rows_1c > 0;
        $hasCsv = (int) $row->rows_csv > 0;
        $diffOk = abs((float) $row->diff_sum) < 0.01;

        if (!$has1c && $hasCsv) {
            return 'only_csv';
        }

        if ($has1c && !$hasCsv) {
            return 'only_1c';
        }

        if ($diffOk) {
            return 'ok';
        }

        return 'mismatch';
    }
}
