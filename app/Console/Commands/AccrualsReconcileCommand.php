<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AccrualsReconcileCommand extends Command
{
    protected $signature = 'accruals:reconcile
        {--market= : Market ID (required)}
        {--period= : Period in YYYY-MM (e.g. 2026-01)}
        {--tenant= : Filter by tenant ID (optional)}
        {--status= : Filter overlap detail rows by status (comma-separated)}
        {--diagnostic= : Filter overlap detail rows by primary diagnostic (comma-separated)}
        {--subdiagnostic= : Filter overlap detail rows by secondary diagnostic (comma-separated)}
        {--limit=0 : Limit breakdown rows (0 = no limit)}
        {--overlap-limit=20 : Limit overlap detail rows (0 = no limit)}
        {--with-matched-overlap : Include matched overlap buckets in detailed overlap output}
        {--json : Output machine-readable JSON}
        ';

    protected $description = 'Read-only reconciliation report: compare 1C accruals vs CSV/Excel for the same period, including overlap buckets';

    public function handle(): int
    {
        $marketId = (int) $this->option('market');
        $period = trim((string) $this->option('period'));
        $tenantId = $this->option('tenant') ? (int) $this->option('tenant') : null;
        $statusFilters = $this->parseCsvOption((string) ($this->option('status') ?? ''));
        $diagnosticFilters = $this->parseCsvOption((string) ($this->option('diagnostic') ?? ''));
        $subdiagnosticFilters = $this->parseCsvOption((string) ($this->option('subdiagnostic') ?? ''));
        $limit = (int) ($this->option('limit') ?? 0);
        $overlapLimit = (int) ($this->option('overlap-limit') ?? 20);
        $withMatchedOverlap = (bool) $this->option('with-matched-overlap');
        $json = (bool) $this->option('json');

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

        // Overall summary
        $summary = $this->getSummary($marketId, $periodDate, $tenantId);

        // Breakdown by tenant
        $breakdown = $this->getBreakdown($marketId, $periodDate, $tenantId, $limit);
        $overlap = $this->buildOverlapReport(
            $marketId,
            $periodDate,
            $tenantId,
            $overlapLimit,
            $withMatchedOverlap,
            $statusFilters,
            $diagnosticFilters,
            $subdiagnosticFilters,
        );

        if ($json) {
            $this->line(json_encode([
                'settings' => [
                    'market_id' => $marketId,
                    'period' => $period,
                    'tenant_id' => $tenantId,
                    'status_filters' => $statusFilters,
                    'diagnostic_filters' => $diagnosticFilters,
                    'subdiagnostic_filters' => $subdiagnosticFilters,
                    'breakdown_limit' => $limit,
                    'overlap_limit' => $overlapLimit,
                    'with_matched_overlap' => $withMatchedOverlap,
                ],
                'summary' => $summary,
                'breakdown' => $breakdown->map(fn ($row) => [
                    'tenant_id' => (int) $row->tenant_id,
                    'tenant_name' => (string) $row->tenant_name,
                    'rows_1c' => (int) $row->rows_1c,
                    'rows_csv' => (int) $row->rows_csv,
                    'sum_1c' => (float) $row->sum_1c,
                    'sum_csv' => (float) $row->sum_csv,
                    'diff_sum' => (float) $row->diff_sum,
                    'status' => $this->tenantStatus($row),
                ])->values()->all(),
                'overlap' => $overlap,
                'diagnostics' => $overlap['diagnostics'],
                'notes' => $this->notes(),
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info('=== ACCRUALS RECONCILIATION REPORT ===');
        $this->newLine();
        $this->info('Settings:');
        $this->line("  market_id: {$marketId}");
        $this->line("  period: {$period}");
        if ($tenantId) {
            $this->line("  tenant_id: {$tenantId}");
        }
        if ($statusFilters !== []) {
            $this->line('  status_filters: ' . implode(', ', $statusFilters));
        }
        if ($diagnosticFilters !== []) {
            $this->line('  diagnostic_filters: ' . implode(', ', $diagnosticFilters));
        }
        if ($subdiagnosticFilters !== []) {
            $this->line('  subdiagnostic_filters: ' . implode(', ', $subdiagnosticFilters));
        }
        $this->line("  breakdown_limit: {$limit}");
        $this->line("  overlap_limit: {$overlapLimit}");
        $this->line('  with_matched_overlap: ' . ($withMatchedOverlap ? 'yes' : 'no'));
        $this->newLine();

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
        $this->info('## Overlap Summary');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Buckets Total', $overlap['stats']['bucket_count_total']],
                ['Buckets in Both Sources', $overlap['stats']['bucket_count_in_both']],
                ['Buckets Matched', $overlap['stats']['bucket_count_matched']],
                ['Buckets Mismatched', $overlap['stats']['bucket_count_mismatch']],
                ['Buckets Only in 1C', $overlap['stats']['bucket_count_only_in_1c']],
                ['Buckets Only in CSV', $overlap['stats']['bucket_count_only_in_csv']],
                ['Rows 1C in Buckets', $overlap['stats']['row_count_1c']],
                ['Rows CSV in Buckets', $overlap['stats']['row_count_csv']],
                ['Sum 1C in Buckets', $this->formatMoney((float) $overlap['stats']['sum_1c'])],
                ['Sum CSV in Buckets', $this->formatMoney((float) $overlap['stats']['sum_csv'])],
                ['Diff in Buckets', $this->formatMoney((float) $overlap['stats']['diff_sum'])],
                ['Detailed Rows Reported', $overlap['stats']['reported_detail_count']],
            ]
        );

        if ($overlap['has_active_filters']) {
            $this->newLine();
            $this->info('## Filtered Overlap Summary');
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Filtered Buckets Total', $overlap['filtered_stats']['bucket_count_total']],
                    ['Filtered Buckets in Both Sources', $overlap['filtered_stats']['bucket_count_in_both']],
                    ['Filtered Buckets Matched', $overlap['filtered_stats']['bucket_count_matched']],
                    ['Filtered Buckets Mismatched', $overlap['filtered_stats']['bucket_count_mismatch']],
                    ['Filtered Buckets Only in 1C', $overlap['filtered_stats']['bucket_count_only_in_1c']],
                    ['Filtered Buckets Only in CSV', $overlap['filtered_stats']['bucket_count_only_in_csv']],
                    ['Filtered Rows 1C in Buckets', $overlap['filtered_stats']['row_count_1c']],
                    ['Filtered Rows CSV in Buckets', $overlap['filtered_stats']['row_count_csv']],
                    ['Filtered Sum 1C in Buckets', $this->formatMoney((float) $overlap['filtered_stats']['sum_1c'])],
                    ['Filtered Sum CSV in Buckets', $this->formatMoney((float) $overlap['filtered_stats']['sum_csv'])],
                    ['Filtered Diff in Buckets', $this->formatMoney((float) $overlap['filtered_stats']['diff_sum'])],
                    ['Filtered Detailed Rows', $overlap['filtered_stats']['filtered_detail_count']],
                ]
            );
        }

        if ($overlap['rows'] !== []) {
            $this->newLine();
            $this->info('## Overlap Details');
            $this->table(
                ['Tenant', 'Basis', 'Bucket', 'Rows 1C', 'Rows CSV', 'Sum 1C', 'Sum CSV', 'Diff', 'Status', 'Diagnostic', 'Subdiagnostic'],
                collect($overlap['rows'])->map(fn (array $row) => [
                    $row['tenant_name'],
                    $row['comparison_basis'],
                    $row['bucket_label'],
                    $row['rows_1c'],
                    $row['rows_csv'],
                    $this->formatMoney((float) $row['sum_1c']),
                    $this->formatMoney((float) $row['sum_csv']),
                    $this->formatMoney((float) $row['diff_sum']),
                    $row['status'],
                    $row['primary_diagnostic'],
                    $row['secondary_diagnostic'] ?? '-',
                ])->all()
            );
        }

        if (($overlap['diagnostics']['reason_counts'] ?? []) !== []) {
            $this->newLine();
            $this->info('## Diagnostic Summary');
            $this->table(
                ['Diagnostic', 'Count'],
                collect($overlap['has_active_filters']
                    ? ($overlap['diagnostics']['filtered_reason_counts'] ?? [])
                    : ($overlap['diagnostics']['reason_counts'] ?? []))
                    ->map(fn (array $row): array => [$row['diagnostic'], $row['count']])
                    ->all()
            );
        }

        $secondarySummary = $overlap['has_active_filters']
            ? ($overlap['diagnostics']['filtered_secondary_counts'] ?? [])
            : ($overlap['diagnostics']['secondary_counts'] ?? []);

        if ($secondarySummary !== []) {
            $this->newLine();
            $this->info('## Subdiagnostic Summary');
            $this->table(
                ['Subdiagnostic', 'Count'],
                collect($secondarySummary)
                    ->map(fn (array $row): array => [$row['secondary_diagnostic'], $row['count']])
                    ->all()
            );
        }

        $this->newLine();
        $this->info('## Notes');
        foreach ($this->notes() as $note) {
            $this->line($note);
        }

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

    private function getBreakdown(int $marketId, string $periodDate, ?int $tenantId, int $limit): Collection
    {
        $query = DB::table('tenant_accruals as ta')
            ->join('tenants as t', 't.id', '=', 'ta.tenant_id')
            ->where('ta.market_id', $marketId)
            ->where('ta.period', $periodDate)
            ->whereIn('ta.source', ['1c', 'excel', 'csv'])
            ->selectRaw('
                ta.tenant_id,
                t.name as tenant_name,
                SUM(CASE WHEN ta.source = \'1c\' THEN 1 ELSE 0 END) as rows_1c,
                SUM(CASE WHEN ta.source IN (\'excel\', \'csv\') THEN 1 ELSE 0 END) as rows_csv,
                COALESCE(SUM(CASE WHEN ta.source = \'1c\' THEN ta.total_with_vat ELSE 0 END), 0) as sum_1c,
                COALESCE(SUM(CASE WHEN ta.source IN (\'excel\', \'csv\') THEN ta.total_with_vat ELSE 0 END), 0) as sum_csv
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

    /**
     * @param  array<int, string>  $statusFilters
     * @param  array<int, string>  $diagnosticFilters
     * @return array{
     *   stats: array<string, int|float>,
     *   filtered_stats: array<string, int|float>,
     *   rows: array<int, array<string, mixed>>,
     *   diagnostics: array<string, mixed>,
     *   has_active_filters: bool
     * }
     */
    private function buildOverlapReport(
        int $marketId,
        string $periodDate,
        ?int $tenantId,
        int $limit,
        bool $withMatchedOverlap,
        array $statusFilters,
        array $diagnosticFilters,
        array $subdiagnosticFilters,
    ): array {
        $rowsQuery = DB::table('tenant_accruals as ta')
            ->join('tenants as t', 't.id', '=', 'ta.tenant_id')
            ->where('ta.market_id', $marketId)
            ->where('ta.period', $periodDate)
            ->whereIn('ta.source', ['1c', 'excel', 'csv'])
            ->when($tenantId, fn ($query) => $query->where('ta.tenant_id', $tenantId))
            ->orderBy('ta.tenant_id')
            ->orderBy('ta.id');

        $selects = [
            'ta.id',
            'ta.tenant_id',
            't.name as tenant_name',
            'ta.source',
            'ta.tenant_contract_id',
            'ta.market_space_id',
            'ta.source_place_code',
            'ta.source_place_name',
            'ta.activity_type',
            'ta.currency',
            'ta.total_with_vat',
            'ta.total_no_vat',
        ];

        if (Schema::hasTable('market_space_tenant_bindings')) {
            $activeBindings = DB::table('market_space_tenant_bindings as mstb')
                ->selectRaw('MAX(mstb.id) as id, mstb.market_space_id')
                ->where('mstb.market_id', $marketId)
                ->where(function ($query): void {
                    $query->whereNull('mstb.started_at')
                        ->orWhere('mstb.started_at', '<=', now());
                })
                ->where(function ($query): void {
                    $query->whereNull('mstb.ended_at')
                        ->orWhere('mstb.ended_at', '>', now());
                })
                ->groupBy('mstb.market_space_id');

            $rowsQuery
                ->leftJoinSub($activeBindings, 'active_mstb', function ($join): void {
                    $join->on('active_mstb.market_space_id', '=', 'ta.market_space_id');
                })
                ->leftJoin('market_space_tenant_bindings as mstb', 'mstb.id', '=', 'active_mstb.id')
                ->leftJoin('tenants as binding_t', 'binding_t.id', '=', 'mstb.tenant_id')
                ->leftJoin('tenant_contracts as binding_tc', 'binding_tc.id', '=', 'mstb.tenant_contract_id');
            $selects = array_merge($selects, [
                'mstb.tenant_id as binding_tenant_id',
                'binding_t.name as binding_tenant_name',
                'mstb.tenant_contract_id as binding_contract_id',
                'binding_tc.number as binding_contract_number',
            ]);
        } else {
            $selects = array_merge($selects, [
                DB::raw('NULL as binding_tenant_id'),
                DB::raw('NULL as binding_tenant_name'),
                DB::raw('NULL as binding_contract_id'),
                DB::raw('NULL as binding_contract_number'),
            ]);
        }

        $rows = $rowsQuery->get($selects);

        $prepared = $rows
            ->groupBy('tenant_id')
            ->flatMap(function (Collection $tenantRows): array {
                return $this->selectBestOverlapBucketsForTenant($tenantRows->values());
            })
            ->map(function (array $bucket): array {
                $bucket['diff_sum'] = (float) $bucket['sum_1c'] - (float) $bucket['sum_csv'];
                $bucket['diff_sum_no_vat'] = (float) $bucket['sum_no_vat_1c'] - (float) $bucket['sum_no_vat_csv'];
                $bucket['status'] = $this->overlapStatus($bucket);
                $bucket['diagnostic_flags'] = $this->diagnosticFlags($bucket);
                $bucket['primary_diagnostic'] = $this->primaryDiagnostic($bucket);
                $bucket['secondary_diagnostic'] = $this->secondaryDiagnostic($bucket);

                return $bucket;
            })
            ->values();

        $stats = $this->buildOverlapStats($prepared);

        $detailed = $prepared
            ->filter(fn (array $row): bool => $withMatchedOverlap || $row['status'] !== 'matched')
            ->filter(fn (array $row): bool => $this->matchesOverlapFilters($row, $statusFilters, $diagnosticFilters, $subdiagnosticFilters))
            ->sort(function (array $left, array $right): int {
                $statusCompare = $this->overlapStatusWeight($left['status']) <=> $this->overlapStatusWeight($right['status']);
                if ($statusCompare !== 0) {
                    return $statusCompare;
                }

                $diffCompare = abs((float) $right['diff_sum']) <=> abs((float) $left['diff_sum']);
                if ($diffCompare !== 0) {
                    return $diffCompare;
                }

                $tenantCompare = mb_strtolower((string) $left['tenant_name']) <=> mb_strtolower((string) $right['tenant_name']);
                if ($tenantCompare !== 0) {
                    return $tenantCompare;
                }

                return (string) $left['bucket_label'] <=> (string) $right['bucket_label'];
            })
            ->values();

        $filteredStats = $this->buildOverlapStats($detailed);

        $reported = $limit > 0
            ? $detailed->take($limit)->values()
            : $detailed;

        $stats['reported_detail_count'] = $reported->count();
        $filteredStats['filtered_detail_count'] = $detailed->count();
        $filteredStats['reported_detail_count'] = $reported->count();
        $hasActiveFilters = $statusFilters !== [] || $diagnosticFilters !== [] || $subdiagnosticFilters !== [];

        return [
            'stats' => $stats,
            'filtered_stats' => $filteredStats,
            'rows' => $reported->all(),
            'diagnostics' => $this->buildOverlapDiagnostics($prepared, $detailed, $reported),
            'has_active_filters' => $hasActiveFilters,
        ];
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

    /**
     * @return array<int, array<string, mixed>>
     */
    private function selectBestOverlapBucketsForTenant(Collection $tenantRows): array
    {
        $candidates = collect([
            'contract' => $this->buildOverlapBucketsByBasis($tenantRows, 'contract'),
            'market_space' => $this->buildOverlapBucketsByBasis($tenantRows, 'market_space'),
            'place_code' => $this->buildOverlapBucketsByBasis($tenantRows, 'place_code'),
            'place_name' => $this->buildOverlapBucketsByBasis($tenantRows, 'place_name'),
            'tenant' => $this->buildOverlapBucketsByBasis($tenantRows, 'tenant'),
        ]);

        $best = $candidates
            ->map(fn (array $candidate, string $basis): array => [
                'basis' => $basis,
                'buckets' => $candidate,
                'score' => $this->scoreOverlapCandidate($candidate, $basis),
            ])
            ->sort(function (array $left, array $right): int {
                $coverageCompare = $right['score']['coverage_ratio'] <=> $left['score']['coverage_ratio'];
                if ($coverageCompare !== 0) {
                    return $coverageCompare;
                }

                $sharedCompare = $right['score']['shared_bucket_count'] <=> $left['score']['shared_bucket_count'];
                if ($sharedCompare !== 0) {
                    return $sharedCompare;
                }

                $mappedCompare = $right['score']['mapped_row_ratio'] <=> $left['score']['mapped_row_ratio'];
                if ($mappedCompare !== 0) {
                    return $mappedCompare;
                }

                $penaltyCompare = $left['score']['penalty'] <=> $right['score']['penalty'];
                if ($penaltyCompare !== 0) {
                    return $penaltyCompare;
                }

                return $right['score']['basis_weight'] <=> $left['score']['basis_weight'];
            })
            ->first();

        return $best['buckets'] ?? [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildOverlapBucketsByBasis(Collection $tenantRows, string $basis): array
    {
        $buckets = [];

        foreach ($tenantRows as $row) {
            $bucket = $this->makeOverlapBucketForBasis((array) $row, $basis);
            $bucketKey = $bucket['bucket_key'];
            $sourceGroup = $this->sourceGroup((string) $row->source);

            if (! isset($buckets[$bucketKey])) {
                $buckets[$bucketKey] = [
                    'tenant_id' => (int) $row->tenant_id,
                    'tenant_name' => (string) $row->tenant_name,
                    'comparison_basis' => $bucket['comparison_basis'],
                    'comparison_value' => $bucket['comparison_value'],
                    'bucket_label' => $bucket['bucket_label'],
                    'activity_type' => $bucket['activity_type'],
                    'currency' => $bucket['currency'],
                    'rows_1c' => 0,
                    'rows_csv' => 0,
                    'sum_1c' => 0.0,
                    'sum_csv' => 0.0,
                    'sum_no_vat_1c' => 0.0,
                    'sum_no_vat_csv' => 0.0,
                    'source_place_codes' => [],
                    'source_place_names' => [],
                    'sources' => [],
                    'binding_tenant_ids' => [],
                    'binding_tenant_names' => [],
                    'binding_contract_ids' => [],
                    'binding_contract_numbers' => [],
                    'mapped_row_count' => 0,
                ];
            }

            $sumWithVat = (float) ($row->total_with_vat ?? 0);
            $sumNoVat = (float) ($row->total_no_vat ?? 0);

            if ($sourceGroup === '1c') {
                $buckets[$bucketKey]['rows_1c']++;
                $buckets[$bucketKey]['sum_1c'] += $sumWithVat;
                $buckets[$bucketKey]['sum_no_vat_1c'] += $sumNoVat;
            } else {
                $buckets[$bucketKey]['rows_csv']++;
                $buckets[$bucketKey]['sum_csv'] += $sumWithVat;
                $buckets[$bucketKey]['sum_no_vat_csv'] += $sumNoVat;
            }

            $code = trim((string) ($row->source_place_code ?? ''));
            if ($code !== '' && ! in_array($code, $buckets[$bucketKey]['source_place_codes'], true)) {
                $buckets[$bucketKey]['source_place_codes'][] = $code;
            }

            $name = trim((string) ($row->source_place_name ?? ''));
            if ($name !== '' && ! in_array($name, $buckets[$bucketKey]['source_place_names'], true)) {
                $buckets[$bucketKey]['source_place_names'][] = $name;
            }

            $source = (string) $row->source;
            if (! in_array($source, $buckets[$bucketKey]['sources'], true)) {
                $buckets[$bucketKey]['sources'][] = $source;
            }

            $bindingTenantId = (int) ($row->binding_tenant_id ?? 0);
            if ($bindingTenantId > 0 && ! in_array($bindingTenantId, $buckets[$bucketKey]['binding_tenant_ids'], true)) {
                $buckets[$bucketKey]['binding_tenant_ids'][] = $bindingTenantId;
            }

            $bindingTenantName = trim((string) ($row->binding_tenant_name ?? ''));
            if ($bindingTenantName !== '' && ! in_array($bindingTenantName, $buckets[$bucketKey]['binding_tenant_names'], true)) {
                $buckets[$bucketKey]['binding_tenant_names'][] = $bindingTenantName;
            }

            $bindingContractId = (int) ($row->binding_contract_id ?? 0);
            if ($bindingContractId > 0 && ! in_array($bindingContractId, $buckets[$bucketKey]['binding_contract_ids'], true)) {
                $buckets[$bucketKey]['binding_contract_ids'][] = $bindingContractId;
            }

            $bindingContractNumber = trim((string) ($row->binding_contract_number ?? ''));
            if ($bindingContractNumber !== '' && ! in_array($bindingContractNumber, $buckets[$bucketKey]['binding_contract_numbers'], true)) {
                $buckets[$bucketKey]['binding_contract_numbers'][] = $bindingContractNumber;
            }

            if ($bucket['mapped']) {
                $buckets[$bucketKey]['mapped_row_count']++;
            }
        }

        return array_values($buckets);
    }

    /**
     * @param  array<int, array<string, mixed>>  $buckets
     * @return array{coverage_ratio: float, shared_bucket_count: int, penalty: float, basis_weight: int, mapped_row_ratio: float}
     */
    private function scoreOverlapCandidate(array $buckets, string $basis): array
    {
        $sharedBucketCount = 0;
        $sourceBuckets1c = 0;
        $sourceBucketsCsv = 0;
        $penalty = 0.0;
        $mappedRows = 0;
        $totalRows = 0;

        foreach ($buckets as $bucket) {
            $has1c = (int) ($bucket['rows_1c'] ?? 0) > 0;
            $hasCsv = (int) ($bucket['rows_csv'] ?? 0) > 0;
            $mappedRows += (int) ($bucket['mapped_row_count'] ?? 0);
            $totalRows += (int) ($bucket['rows_1c'] ?? 0) + (int) ($bucket['rows_csv'] ?? 0);

            if ($has1c) {
                $sourceBuckets1c++;
            }

            if ($hasCsv) {
                $sourceBucketsCsv++;
            }

            if ($has1c && $hasCsv) {
                $sharedBucketCount++;
                $penalty += abs((float) $bucket['sum_1c'] - (float) $bucket['sum_csv']);
            } else {
                $penalty += abs((float) $bucket['sum_1c']) + abs((float) $bucket['sum_csv']);
            }
        }

        $coverageRatio = 0.0;
        $denominator = max($sourceBuckets1c, $sourceBucketsCsv);
        if ($denominator > 0) {
            $coverageRatio = $sharedBucketCount / $denominator;
        }

        return [
            'coverage_ratio' => $coverageRatio,
            'shared_bucket_count' => $sharedBucketCount,
            'penalty' => round($penalty, 2),
            'basis_weight' => $this->overlapBasisWeight($basis),
            'mapped_row_ratio' => $totalRows > 0 ? $mappedRows / $totalRows : 0.0,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{bucket_key: string, comparison_basis: string, comparison_value: string, bucket_label: string, activity_type: ?string, currency: string, mapped: bool}
     */
    private function makeOverlapBucketForBasis(array $row, string $basis): array
    {
        $tenantId = (int) ($row['tenant_id'] ?? 0);
        $rowId = (int) ($row['id'] ?? 0);
        $contractId = (int) ($row['tenant_contract_id'] ?? 0);
        $spaceId = (int) ($row['market_space_id'] ?? 0);
        $normalizedCode = $this->normalizePlaceKey((string) ($row['source_place_code'] ?? ''));
        $normalizedName = $this->normalizeTextKey((string) ($row['source_place_name'] ?? ''));
        $normalizedActivity = $this->normalizeTextKey((string) ($row['activity_type'] ?? ''));
        $currency = strtoupper(trim((string) ($row['currency'] ?? 'RUB')));
        $currency = $currency !== '' ? $currency : 'RUB';

        [$comparisonValue, $bucketLabel, $mapped] = match ($basis) {
            'contract' => $contractId > 0
                ? [(string) $contractId, 'contract:' . $contractId, true]
                : ['row:' . $rowId, 'contract:unmapped-row:' . $rowId, false],
            'market_space' => $spaceId > 0
                ? [(string) $spaceId, 'market_space:' . $spaceId, true]
                : ['row:' . $rowId, 'market_space:unmapped-row:' . $rowId, false],
            'place_code' => $normalizedCode !== ''
                ? [$normalizedCode, 'place_code:' . $normalizedCode, true]
                : ['row:' . $rowId, 'place_code:unmapped-row:' . $rowId, false],
            'place_name' => $normalizedName !== ''
                ? [$normalizedName, 'place_name:' . $normalizedName, true]
                : ['row:' . $rowId, 'place_name:unmapped-row:' . $rowId, false],
            'tenant' => [(string) $tenantId, 'tenant:' . $tenantId, true],
            default => [(string) $tenantId, 'tenant:' . $tenantId, true],
        };

        return [
            'bucket_key' => implode('|', [$tenantId, $basis, $comparisonValue, $currency]),
            'comparison_basis' => $basis,
            'comparison_value' => $comparisonValue,
            'bucket_label' => $bucketLabel,
            'activity_type' => $normalizedActivity !== '' ? $normalizedActivity : null,
            'currency' => $currency,
            'mapped' => $mapped,
        ];
    }

    /**
     * @param  array<string, mixed>  $bucket
     */
    private function overlapStatus(array $bucket): string
    {
        $has1c = (int) ($bucket['rows_1c'] ?? 0) > 0;
        $hasCsv = (int) ($bucket['rows_csv'] ?? 0) > 0;
        $diffOk = abs((float) ($bucket['diff_sum'] ?? 0.0)) < 0.01;
        $rowsEqual = (int) ($bucket['rows_1c'] ?? 0) === (int) ($bucket['rows_csv'] ?? 0);

        if ($has1c && ! $hasCsv) {
            return 'only_1c';
        }

        if (! $has1c && $hasCsv) {
            return 'only_csv';
        }

        if ($diffOk && $rowsEqual) {
            return 'matched';
        }

        return 'mismatch';
    }

    private function overlapStatusWeight(string $status): int
    {
        return match ($status) {
            'mismatch' => 0,
            'only_1c' => 1,
            'only_csv' => 2,
            'matched' => 3,
            default => 4,
        };
    }

    private function overlapBasisWeight(string $basis): int
    {
        return match ($basis) {
            'contract' => 5,
            'market_space' => 4,
            'place_code' => 3,
            'place_name' => 2,
            'tenant' => 1,
            default => 0,
        };
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, int|float>
     */
    private function buildOverlapStats(Collection $rows): array
    {
        return [
            'bucket_count_total' => $rows->count(),
            'bucket_count_in_both' => $rows->filter(fn (array $row): bool => $row['rows_1c'] > 0 && $row['rows_csv'] > 0)->count(),
            'bucket_count_matched' => $rows->where('status', 'matched')->count(),
            'bucket_count_mismatch' => $rows->where('status', 'mismatch')->count(),
            'bucket_count_only_in_1c' => $rows->where('status', 'only_1c')->count(),
            'bucket_count_only_in_csv' => $rows->where('status', 'only_csv')->count(),
            'row_count_1c' => (int) $rows->sum('rows_1c'),
            'row_count_csv' => (int) $rows->sum('rows_csv'),
            'sum_1c' => round((float) $rows->sum('sum_1c'), 2),
            'sum_csv' => round((float) $rows->sum('sum_csv'), 2),
            'diff_sum' => round((float) $rows->sum('diff_sum'), 2),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<int, string>  $statusFilters
     * @param  array<int, string>  $diagnosticFilters
     * @param  array<int, string>  $subdiagnosticFilters
     */
    private function matchesOverlapFilters(array $row, array $statusFilters, array $diagnosticFilters, array $subdiagnosticFilters): bool
    {
        $status = (string) ($row['status'] ?? '');
        $diagnostic = (string) ($row['primary_diagnostic'] ?? '');
        $subdiagnostic = (string) ($row['secondary_diagnostic'] ?? '');

        if ($statusFilters !== [] && ! in_array($status, $statusFilters, true)) {
            return false;
        }

        if ($diagnosticFilters !== [] && ! in_array($diagnostic, $diagnosticFilters, true)) {
            return false;
        }

        if ($subdiagnosticFilters !== [] && ! in_array($subdiagnostic, $subdiagnosticFilters, true)) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $bucket
     * @return array<int, string>
     */
    private function diagnosticFlags(array $bucket): array
    {
        $status = (string) ($bucket['status'] ?? '');
        $basis = (string) ($bucket['comparison_basis'] ?? '');
        $rows1c = (int) ($bucket['rows_1c'] ?? 0);
        $rowsCsv = (int) ($bucket['rows_csv'] ?? 0);
        $diff = abs((float) ($bucket['diff_sum'] ?? 0.0));
        $flags = [];

        if ($status === 'mismatch') {
            if ($diff < 0.01 && $rows1c !== $rowsCsv) {
                $flags[] = 'same_total_different_row_count';
            }

            if ($diff > 0.0 && $diff <= 1.10) {
                $flags[] = 'near_zero_amount_delta';
            }

            if ($this->isApproxMultipleOf($diff, 525.0, 0.15)) {
                $flags[] = 'fixed_step_delta_525';
            }

            if ($basis === 'tenant' && $rows1c !== $rowsCsv) {
                $flags[] = 'tenant_level_aggregation_gap';
            }

            if ($basis === 'tenant' && $rows1c === $rowsCsv && $diff >= 0.01) {
                $flags[] = 'tenant_level_amount_mismatch';
            }

            if ($basis === 'contract' && $rows1c === $rowsCsv && $diff >= 0.01) {
                $flags[] = 'contract_amount_mismatch';
            }
        }

        if ($status === 'only_1c') {
            if ($basis === 'contract') {
                $flags[] = 'only_1c_contract_bucket';
            }

            if (($bucket['source_place_codes'] ?? []) === []) {
                $flags[] = 'missing_place_code_on_1c_side';
            }
        }

        if ($status === 'only_csv') {
            if ($basis === 'market_space') {
                $flags[] = 'only_csv_market_space_bucket';
            } elseif ($basis === 'contract') {
                $flags[] = 'only_csv_contract_bucket';
            }
        }

        return array_values(array_unique($flags));
    }

    /**
     * @param  array<string, mixed>  $bucket
     */
    private function primaryDiagnostic(array $bucket): string
    {
        $status = (string) ($bucket['status'] ?? '');
        $flags = $bucket['diagnostic_flags'] ?? [];

        foreach ([
            'same_total_different_row_count',
            'near_zero_amount_delta',
            'fixed_step_delta_525',
            'tenant_level_aggregation_gap',
            'tenant_level_amount_mismatch',
            'contract_amount_mismatch',
            'only_1c_contract_bucket',
            'missing_place_code_on_1c_side',
            'only_csv_market_space_bucket',
            'only_csv_contract_bucket',
        ] as $preferredFlag) {
            if (in_array($preferredFlag, $flags, true)) {
                return $preferredFlag;
            }
        }

        return match ($status) {
            'matched' => 'matched',
            'mismatch' => 'mismatch_other',
            'only_1c' => 'only_1c_other',
            'only_csv' => 'only_csv_other',
            default => 'unknown',
        };
    }

    private function secondaryDiagnostic(array $bucket): ?string
    {
        if (($bucket['primary_diagnostic'] ?? null) !== 'only_csv_market_space_bucket') {
            return null;
        }

        $bucketTenantId = (int) ($bucket['tenant_id'] ?? 0);
        $bindingTenantIds = collect($bucket['binding_tenant_ids'] ?? [])
            ->map(fn ($value): int => (int) $value)
            ->filter(fn (int $value): bool => $value > 0)
            ->values()
            ->all();
        $bindingContractIds = collect($bucket['binding_contract_ids'] ?? [])
            ->map(fn ($value): int => (int) $value)
            ->filter(fn (int $value): bool => $value > 0)
            ->values()
            ->all();

        if ($bindingTenantIds === [] && $bindingContractIds === []) {
            return 'space_without_active_binding';
        }

        if ($bindingTenantIds !== [] && in_array($bucketTenantId, $bindingTenantIds, true)) {
            return $bindingContractIds !== []
                ? 'space_bound_to_same_tenant'
                : 'space_bound_to_same_tenant_without_contract';
        }

        if ($bindingTenantIds !== []) {
            return 'space_bound_to_other_tenant';
        }

        if ($bindingContractIds !== []) {
            return 'space_with_contract_without_tenant';
        }

        return 'space_binding_context_unknown';
    }

    private function isApproxMultipleOf(float $value, float $step, float $tolerance): bool
    {
        if ($value < ($step - $tolerance) || $step <= 0.0) {
            return false;
        }

        $quotient = $value / $step;

        return abs($quotient - round($quotient)) <= ($tolerance / $step);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $prepared
     * @param  Collection<int, array<string, mixed>>  $filtered
     * @param  Collection<int, array<string, mixed>>  $reported
     * @return array<string, mixed>
     */
    private function buildOverlapDiagnostics(Collection $prepared, Collection $filtered, Collection $reported): array
    {
        $reasonCounts = $prepared
            ->groupBy('primary_diagnostic')
            ->map(fn (Collection $group, string $diagnostic): array => [
                'diagnostic' => $diagnostic,
                'count' => $group->count(),
            ])
            ->sort(function (array $left, array $right): int {
                $countCompare = $right['count'] <=> $left['count'];
                if ($countCompare !== 0) {
                    return $countCompare;
                }

                return $left['diagnostic'] <=> $right['diagnostic'];
            })
            ->values()
            ->all();

        $filteredReasonCounts = $filtered
            ->groupBy('primary_diagnostic')
            ->map(fn (Collection $group, string $diagnostic): array => [
                'diagnostic' => $diagnostic,
                'count' => $group->count(),
            ])
            ->sort(function (array $left, array $right): int {
                $countCompare = $right['count'] <=> $left['count'];
                if ($countCompare !== 0) {
                    return $countCompare;
                }

                return $left['diagnostic'] <=> $right['diagnostic'];
            })
            ->values()
            ->all();

        $secondaryCounts = $prepared
            ->filter(fn (array $row): bool => ($row['secondary_diagnostic'] ?? null) !== null)
            ->groupBy('secondary_diagnostic')
            ->map(fn (Collection $group, string $secondaryDiagnostic): array => [
                'secondary_diagnostic' => $secondaryDiagnostic,
                'count' => $group->count(),
            ])
            ->sort(function (array $left, array $right): int {
                $countCompare = $right['count'] <=> $left['count'];
                if ($countCompare !== 0) {
                    return $countCompare;
                }

                return $left['secondary_diagnostic'] <=> $right['secondary_diagnostic'];
            })
            ->values()
            ->all();

        $filteredSecondaryCounts = $filtered
            ->filter(fn (array $row): bool => ($row['secondary_diagnostic'] ?? null) !== null)
            ->groupBy('secondary_diagnostic')
            ->map(fn (Collection $group, string $secondaryDiagnostic): array => [
                'secondary_diagnostic' => $secondaryDiagnostic,
                'count' => $group->count(),
            ])
            ->sort(function (array $left, array $right): int {
                $countCompare = $right['count'] <=> $left['count'];
                if ($countCompare !== 0) {
                    return $countCompare;
                }

                return $left['secondary_diagnostic'] <=> $right['secondary_diagnostic'];
            })
            ->values()
            ->all();

        $reportedExamples = $reported
            ->map(fn (array $row): array => [
                'tenant_id' => (int) $row['tenant_id'],
                'tenant_name' => (string) $row['tenant_name'],
                'bucket_label' => (string) $row['bucket_label'],
                'status' => (string) $row['status'],
                'primary_diagnostic' => (string) $row['primary_diagnostic'],
                'secondary_diagnostic' => $row['secondary_diagnostic'],
            ])
            ->values()
            ->all();

        return [
            'reason_counts' => $reasonCounts,
            'filtered_reason_counts' => $filteredReasonCounts,
            'secondary_counts' => $secondaryCounts,
            'filtered_secondary_counts' => $filteredSecondaryCounts,
            'reported_examples' => $reportedExamples,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function parseCsvOption(string $value): array
    {
        if (trim($value) === '') {
            return [];
        }

        return collect(explode(',', $value))
            ->map(fn (string $item): string => trim($item))
            ->filter(fn (string $item): bool => $item !== '')
            ->values()
            ->all();
    }

    private function sourceGroup(string $source): string
    {
        return $source === '1c' ? '1c' : 'csv';
    }

    private function normalizePlaceKey(string $value): string
    {
        $normalized = $this->normalizeTextKey($value);

        if ($normalized === '') {
            return '';
        }

        $normalized = str_replace('\\', '/', $normalized);
        $normalized = preg_replace('#/+#', '/', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/u', '', $normalized) ?? $normalized;

        return $normalized;
    }

    private function normalizeTextKey(string $value): string
    {
        $normalized = trim($value);
        if ($normalized === '') {
            return '';
        }

        $normalized = mb_strtoupper($normalized);
        $normalized = str_replace(["\xC2\xA0", "\t"], ' ', $normalized);
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;

        return trim($normalized);
    }

    /**
     * @return array<int, string>
     */
    private function notes(): array
    {
        return [
            '- This is a READ-ONLY report. No data was modified.',
            '- Source field: tenant_accruals.source = "1c" or "excel"/"csv".',
            '- Sum field: tenant_accruals.total_with_vat.',
            '- Overlap basis is selected per tenant from: contract, market_space, source_place_code, source_place_name, tenant.',
            '- Candidate basis selection prefers higher cross-source coverage, then lower unmatched penalty, then finer granularity.',
            '- Buckets are compared inside one period only; matched means same bucket, same row count, and diff < 0.01.',
            '- Diagnostic labels are read-only heuristics to separate aggregation gaps, small deltas, fixed-step deltas, and source-only buckets.',
            '- Secondary diagnostics add active binding context for selected source-only classes without changing reconciliation results.',
            '- Use --status=..., --diagnostic=..., or --subdiagnostic=... to focus the detailed overlap output on one class without changing the underlying data.',
        ];
    }
}
