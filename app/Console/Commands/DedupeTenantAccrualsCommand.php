<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\MarketContext;
use App\Support\MarketWriteGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DedupeTenantAccrualsCommand extends Command
{
    protected $signature = 'accruals:dedupe
        {--market= : Market ID (required)}
        {--period= : Period in YYYY-MM (required)}
        {--tenant= : Tenant ID filter}
        {--limit=0 : Limit duplicate groups in output}
        {--apply : Delete duplicate rows}
        {--backup= : Required with --apply; path or identifier of the DB backup}
        {--json : Output JSON}';

    protected $description = 'Find and optionally remove duplicate 1C tenant accrual rows for one market period.';

    public function handle(MarketWriteGuard $marketWriteGuard): int
    {
        $marketId = (int) $this->option('market');
        $period = trim((string) $this->option('period'));
        $tenantId = $this->option('tenant') ? (int) $this->option('tenant') : null;
        $limit = max(0, (int) $this->option('limit'));
        $apply = (bool) $this->option('apply');
        $backup = trim((string) ($this->option('backup') ?? ''));
        $json = (bool) $this->option('json');

        if ($marketId <= 0) {
            $this->error('Market ID is required. Use --market=1');

            return self::FAILURE;
        }

        if (! preg_match('/^\d{4}-\d{2}$/', $period)) {
            $this->error('Period is required in YYYY-MM format. Use --period=2026-06');

            return self::FAILURE;
        }

        if ($apply && $backup === '') {
            $this->error('A completed database backup is required with --apply. Pass --backup=/path/to/file.dump');

            return self::FAILURE;
        }

        return app(MarketContext::class)->withMarket(
            $marketId,
            function () use ($marketId, $period, $tenantId, $limit, $apply, $backup, $json, $marketWriteGuard): int {
                $periodDate = $period.'-01';
                $rows = $this->loadRows($marketId, $periodDate, $tenantId);
                $groups = $this->duplicateGroups($rows);

                if ($limit > 0) {
                    $groups = $groups->take($limit)->values();
                }

                $deleteIds = $groups
                    ->flatMap(fn (array $group): array => $group['delete_ids'])
                    ->values()
                    ->all();

                foreach ($groups as $group) {
                    $marketWriteGuard->assertSameMarketId(
                        $group['key']['market_id'] ?? null,
                        $marketId,
                        'market_id',
                        'Duplicate accrual group belongs to another market.',
                    );
                }

                $deleted = 0;

                if ($apply && $deleteIds !== []) {
                    DB::transaction(function () use ($marketId, $deleteIds, &$deleted): void {
                        $deleted = DB::table('tenant_accruals')
                            ->where('market_id', $marketId)
                            ->whereIn('id', $deleteIds)
                            ->delete();
                    });
                }

                $result = [
                    'settings' => [
                        'market_id' => $marketId,
                        'period' => $period,
                        'tenant_id' => $tenantId,
                        'mode' => $apply ? 'apply' : 'dry-run',
                        'backup' => $apply ? $backup : null,
                    ],
                    'stats' => [
                        'scanned_rows' => $rows->count(),
                        'duplicate_groups' => $groups->count(),
                        'duplicate_rows' => count($deleteIds),
                        'deleted_rows' => $deleted,
                    ],
                    'groups' => $groups->map(fn (array $group): array => [
                        'key' => $group['key'],
                        'keep_id' => $group['keep_id'],
                        'delete_ids' => $group['delete_ids'],
                        'row_count' => $group['row_count'],
                    ])->values()->all(),
                ];

                $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

                if (! $json && ! $apply) {
                    $this->warn('Dry-run only. Re-run with --apply and --backup=/path/to/file.dump to delete duplicate rows.');
                }

                return self::SUCCESS;
            },
        );
    }

    /**
     * @return Collection<int, object>
     */
    private function loadRows(int $marketId, string $periodDate, ?int $tenantId): Collection
    {
        $query = DB::table('tenant_accruals')
            ->where('market_id', $marketId)
            ->where('period', $periodDate)
            ->where('source', '1c')
            ->orderBy('tenant_id')
            ->orderBy('id');

        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }

        return $query->get([
            'id',
            'market_id',
            'tenant_id',
            'period',
            'contract_external_id',
            'tenant_contract_id',
            'market_space_id',
            'source_place_code',
            'source_place_name',
            'activity_type',
            'rent_amount',
            'management_fee',
            'utilities_amount',
            'electricity_amount',
            'total_no_vat',
            'total_with_vat',
            'organization_external_id',
            'organization_name',
            'account',
            'payload',
            'imported_at',
            'created_at',
            'updated_at',
        ]);
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function duplicateGroups(Collection $rows): Collection
    {
        return $rows
            ->groupBy(fn (object $row): string => $this->duplicateKey($row))
            ->filter(fn (Collection $group): bool => $group->count() > 1)
            ->map(function (Collection $group): array {
                $sorted = $group
                    ->sortByDesc(fn (object $row): array => [
                        $this->completenessScore($row),
                        (string) ($row->imported_at ?? ''),
                        (string) ($row->updated_at ?? ''),
                        (int) $row->id,
                    ])
                    ->values();

                $keep = $sorted->first();
                $deleteIds = $sorted
                    ->skip(1)
                    ->pluck('id')
                    ->map(fn ($id): int => (int) $id)
                    ->values()
                    ->all();

                return [
                    'key' => $this->duplicateKeyParts($keep),
                    'keep_id' => (int) $keep->id,
                    'delete_ids' => $deleteIds,
                    'row_count' => $group->count(),
                ];
            })
            ->sortBy(fn (array $group): array => [
                (int) ($group['key']['tenant_id'] ?? 0),
                (string) ($group['key']['contract_external_id'] ?? ''),
                (int) $group['keep_id'],
            ])
            ->values();
    }

    private function duplicateKey(object $row): string
    {
        return json_encode($this->duplicateKeyParts($row), JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION) ?: '';
    }

    /**
     * @return array<string, mixed>
     */
    private function duplicateKeyParts(object $row): array
    {
        return [
            'market_id' => (int) $row->market_id,
            'tenant_id' => (int) $row->tenant_id,
            'period' => (string) $row->period,
            'contract_external_id' => $row->contract_external_id,
            'tenant_contract_id' => $row->tenant_contract_id !== null ? (int) $row->tenant_contract_id : null,
            'market_space_id' => $row->market_space_id !== null ? (int) $row->market_space_id : null,
            'source_place_code' => $row->source_place_code,
            'source_place_name' => $row->source_place_name,
            'activity_type' => $row->activity_type,
            'rent_amount' => $this->moneyKey($row->rent_amount),
            'management_fee' => $this->moneyKey($row->management_fee),
            'utilities_amount' => $this->moneyKey($row->utilities_amount),
            'electricity_amount' => $this->moneyKey($row->electricity_amount),
            'total_no_vat' => $this->moneyKey($row->total_no_vat),
            'total_with_vat' => $this->moneyKey($row->total_with_vat),
        ];
    }

    private function moneyKey(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return number_format((float) $value, 2, '.', '');
    }

    private function completenessScore(object $row): int
    {
        $score = 0;

        foreach (['organization_external_id', 'organization_name', 'account'] as $column) {
            if (trim((string) ($row->{$column} ?? '')) !== '') {
                $score += 10;
            }
        }

        if (is_string($row->payload ?? null) && str_contains($row->payload, 'organization_external_id')) {
            $score += 5;
        }

        return $score;
    }
}
