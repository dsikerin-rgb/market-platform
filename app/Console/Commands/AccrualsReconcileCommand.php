<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AccrualsReconcileCommand extends Command
{
    protected $signature = 'accruals:reconcile
        {--market= : Market ID (required)}
        {--period= : Period in YYYY-MM (e.g. 2026-01)}
        {--tenant= : Filter by tenant ID (optional)}
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
        $overlap = $this->buildOverlapReport($marketId, $periodDate, $tenantId, $overlapLimit, $withMatchedOverlap);

        if ($json) {
            $this->line(json_encode([
                'settings' => [
                    'market_id' => $marketId,
                    'period' => $period,
                    'tenant_id' => $tenantId,
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

        if ($overlap['rows'] !== []) {
            $this->newLine();
            $this->info('## Overlap Details');
            $this->table(
                ['Tenant', 'Basis', 'Bucket', 'Rows 1C', 'Rows CSV', 'Sum 1C', 'Sum CSV', 'Diff', 'Status'],
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
                ])->all()
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
     * @return array{stats: array<string, int|float>, rows: array<int, array<string, mixed>>}
     */
    private function buildOverlapReport(
        int $marketId,
        string $periodDate,
        ?int $tenantId,
        int $limit,
        bool $withMatchedOverlap,
    ): array {
        $rows = DB::table('tenant_accruals as ta')
            ->join('tenants as t', 't.id', '=', 'ta.tenant_id')
            ->where('ta.market_id', $marketId)
            ->where('ta.period', $periodDate)
            ->whereIn('ta.source', ['1c', 'excel', 'csv'])
            ->when($tenantId, fn ($query) => $query->where('ta.tenant_id', $tenantId))
            ->orderBy('ta.tenant_id')
            ->orderBy('ta.id')
            ->get([
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
            ]);

        $buckets = [];

        foreach ($rows as $row) {
            $bucket = $this->makeOverlapBucket((array) $row);
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
        }

        $prepared = collect(array_values($buckets))
            ->map(function (array $bucket): array {
                $bucket['diff_sum'] = (float) $bucket['sum_1c'] - (float) $bucket['sum_csv'];
                $bucket['diff_sum_no_vat'] = (float) $bucket['sum_no_vat_1c'] - (float) $bucket['sum_no_vat_csv'];
                $bucket['status'] = $this->overlapStatus($bucket);

                return $bucket;
            })
            ->values();

        $stats = [
            'bucket_count_total' => $prepared->count(),
            'bucket_count_in_both' => $prepared->filter(fn (array $row): bool => $row['rows_1c'] > 0 && $row['rows_csv'] > 0)->count(),
            'bucket_count_matched' => $prepared->where('status', 'matched')->count(),
            'bucket_count_mismatch' => $prepared->where('status', 'mismatch')->count(),
            'bucket_count_only_in_1c' => $prepared->where('status', 'only_1c')->count(),
            'bucket_count_only_in_csv' => $prepared->where('status', 'only_csv')->count(),
            'row_count_1c' => (int) $prepared->sum('rows_1c'),
            'row_count_csv' => (int) $prepared->sum('rows_csv'),
            'sum_1c' => round((float) $prepared->sum('sum_1c'), 2),
            'sum_csv' => round((float) $prepared->sum('sum_csv'), 2),
            'diff_sum' => round((float) $prepared->sum('diff_sum'), 2),
        ];

        $detailed = $prepared
            ->filter(fn (array $row): bool => $withMatchedOverlap || $row['status'] !== 'matched')
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

        $reported = $limit > 0
            ? $detailed->take($limit)->values()
            : $detailed;

        $stats['reported_detail_count'] = $reported->count();

        return [
            'stats' => $stats,
            'rows' => $reported->all(),
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
     * @param  array<string, mixed>  $row
     * @return array{bucket_key: string, comparison_basis: string, comparison_value: string, bucket_label: string, activity_type: ?string, currency: string}
     */
    private function makeOverlapBucket(array $row): array
    {
        $tenantId = (int) ($row['tenant_id'] ?? 0);
        $contractId = (int) ($row['tenant_contract_id'] ?? 0);
        $spaceId = (int) ($row['market_space_id'] ?? 0);
        $normalizedCode = $this->normalizePlaceKey((string) ($row['source_place_code'] ?? ''));
        $normalizedName = $this->normalizeTextKey((string) ($row['source_place_name'] ?? ''));
        $normalizedActivity = $this->normalizeTextKey((string) ($row['activity_type'] ?? ''));
        $currency = strtoupper(trim((string) ($row['currency'] ?? 'RUB')));
        $currency = $currency !== '' ? $currency : 'RUB';

        if ($contractId > 0) {
            $basis = 'contract';
            $value = (string) $contractId;
            $label = 'contract:' . $contractId;
        } elseif ($spaceId > 0) {
            $basis = 'market_space';
            $value = (string) $spaceId;
            $label = 'market_space:' . $spaceId;
        } elseif ($normalizedCode !== '') {
            $basis = 'place_code';
            $value = $normalizedCode;
            $label = 'place_code:' . $normalizedCode;
        } elseif ($normalizedName !== '') {
            $basis = 'place_name';
            $value = $normalizedName;
            $label = 'place_name:' . $normalizedName;
        } else {
            $basis = 'tenant';
            $value = (string) $tenantId;
            $label = 'tenant:' . $tenantId;
        }

        if ($normalizedActivity !== '') {
            $label .= ' | activity:' . $normalizedActivity;
        }

        return [
            'bucket_key' => implode('|', [$tenantId, $basis, $value, $normalizedActivity, $currency]),
            'comparison_basis' => $basis,
            'comparison_value' => $value,
            'bucket_label' => $label,
            'activity_type' => $normalizedActivity !== '' ? $normalizedActivity : null,
            'currency' => $currency,
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
            '- Overlap bucket precedence: tenant_contract_id -> market_space_id -> source_place_code -> source_place_name -> tenant_id.',
            '- Buckets are compared inside one period only; matched means same bucket, same row count, and diff < 0.01.',
        ];
    }
}
