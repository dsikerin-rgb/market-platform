<?php
# app/Console/Commands/AuditMarketSpaceDuplicatesCommand.php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Operations\OperationType;
use App\Models\MarketSpace;
use App\Services\MarketMap\DuplicateSpaceResolutionService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class AuditMarketSpaceDuplicatesCommand extends Command
{
    protected $signature = 'ops:audit-market-space-duplicates {spaceA : First market_space id} {spaceB : Second market_space id}';

    protected $description = 'Read-only audit for a pair of potentially duplicated market spaces.';

    public function handle(DuplicateSpaceResolutionService $duplicateResolver): int
    {
        $spaceAId = (int) $this->argument('spaceA');
        $spaceBId = (int) $this->argument('spaceB');

        if ($spaceAId <= 0 || $spaceBId <= 0) {
            $this->error('Both space ids must be positive integers.');

            return self::FAILURE;
        }

        if ($spaceAId === $spaceBId) {
            $this->error('Space ids must be different.');

            return self::FAILURE;
        }

        $spaces = MarketSpace::query()
            ->whereIn('id', [$spaceAId, $spaceBId])
            ->orderBy('id')
            ->get();

        if ($spaces->count() !== 2) {
            $this->error('One or both market_spaces were not found.');
            $this->writeJson('requested_ids', [$spaceAId, $spaceBId]);
            $this->writeJson('found_ids', $spaces->pluck('id')->values()->all());

            return self::FAILURE;
        }

        /** @var MarketSpace $spaceA */
        $spaceA = $spaces->firstWhere('id', $spaceAId);
        /** @var MarketSpace $spaceB */
        $spaceB = $spaces->firstWhere('id', $spaceBId);

        $marketId = (int) $spaceA->market_id;
        if ($marketId !== (int) $spaceB->market_id) {
            $this->error('Spaces belong to different markets.');
            $this->writeJson('space_a_market_id', $spaceA->market_id);
            $this->writeJson('space_b_market_id', $spaceB->market_id);

            return self::FAILURE;
        }

        $spaceIds = [$spaceAId, $spaceBId];
        $spaceIdLookup = array_fill_keys($spaceIds, true);

        $spacesSummary = $this->marketSpacesSummary($spaceIds);
        $shapeSummary = $this->mapShapesSummary($spaceIds);
        $groupSummary = $this->groupContextSummary($spaces);
        $accrualsSummary = $this->financialTailsSummary($spaceIds, $marketId);
        $tenantIds = $this->collectTenantIds($spacesSummary, $accrualsSummary);
        $contractsSummary = $this->contractsSummary($spaceIds, $tenantIds, $marketId);
        $tenantIds = array_values(array_unique(array_merge($tenantIds, $this->contractTenantIds($contractsSummary))));
        $tenantsSummary = $this->tenantsSummary($tenantIds, $marketId);
        $historySummary = $this->historySummary($spaceIds, $marketId);
        $mergeRiskSummary = $this->mergeRiskSummary(
            $spaceA,
            $spaceB,
            $spacesSummary,
            $shapeSummary,
            $accrualsSummary,
            $contractsSummary,
            $duplicateResolver
        );
        $recommendation = $this->recommendationSummary(
            $spaceA,
            $spaceB,
            $shapeSummary,
            $accrualsSummary,
            $contractsSummary,
            $mergeRiskSummary,
            $spaceIdLookup
        );

        $this->writeJson('environment', $this->environmentSummary());
        $this->writeJson('market_spaces_summary', $spacesSummary);
        $this->writeJson('map_shapes', $shapeSummary);
        $this->writeJson('group_context', $groupSummary);
        $this->writeJson('financial_tails', $accrualsSummary);
        $this->writeJson('contracts', $contractsSummary);
        $this->writeJson('tenants', $tenantsSummary);
        $this->writeJson('history', $historySummary);
        $this->writeJson('merge_risk_summary', $mergeRiskSummary);
        $this->writeJson('recommendation', $recommendation);

        return self::SUCCESS;
    }

    /**
     * @param  list<int>  $spaceIds
     * @return array<int, array<string, mixed>>
     */
    private function marketSpacesSummary(array $spaceIds): array
    {
        $fields = [
            'id',
            'market_id',
            'number',
            'code',
            'display_name',
            'tenant_id',
            'status',
            'is_active',
            'space_group_role',
            'space_group_parent_id',
            'space_group_slot',
            'space_group_token',
            'updated_at',
        ];

        return MarketSpace::query()
            ->whereIn('id', $spaceIds)
            ->orderBy('id')
            ->get($fields)
            ->map(fn (MarketSpace $space): array => $space->toArray())
            ->all();
    }

    /**
     * @param  list<int>  $spaceIds
     * @return array<string, mixed>
     */
    private function mapShapesSummary(array $spaceIds): array
    {
        if (! Schema::hasTable('market_space_map_shapes')) {
            return ['available' => false, 'rows' => []];
        }

        $columns = [
            'id',
            'market_space_id',
            'is_active',
            'page',
            'version',
            'sort_order',
        ];

        if (Schema::hasColumn('market_space_map_shapes', 'label')) {
            $columns[] = 'label';
        }

        if (Schema::hasColumn('market_space_map_shapes', 'type')) {
            $columns[] = 'type';
        }

        $rows = DB::table('market_space_map_shapes')
            ->select($columns)
            ->whereIn('market_space_id', $spaceIds)
            ->orderBy('market_space_id')
            ->orderBy('id')
            ->get()
            ->map(fn (object $row): array => (array) $row)
            ->all();

        $counts = [];
        foreach ($spaceIds as $spaceId) {
            $counts[(string) $spaceId] = count(array_filter(
                $rows,
                static fn (array $row): bool => (int) ($row['market_space_id'] ?? 0) === $spaceId
            ));
        }

        return [
            'available' => true,
            'counts' => $counts,
            'rows' => $rows,
        ];
    }

    /**
     * @param  Collection<int, MarketSpace>  $spaces
     * @return array<string, mixed>
     */
    private function groupContextSummary(Collection $spaces): array
    {
        $spaceIds = $spaces->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $parentIds = $spaces->pluck('space_group_parent_id')
            ->filter(fn ($id): bool => (int) $id > 0)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        $tokens = $spaces->pluck('space_group_token')
            ->map(fn ($token): string => trim((string) $token))
            ->filter(fn (string $token): bool => $token !== '')
            ->unique()
            ->values()
            ->all();

        $parents = ! empty($parentIds)
            ? MarketSpace::query()
                ->whereIn('id', $parentIds)
                ->orderBy('id')
                ->get(['id', 'market_id', 'number', 'code', 'display_name', 'space_group_role', 'space_group_parent_id', 'space_group_slot', 'space_group_token'])
                ->map(fn (MarketSpace $space): array => $space->toArray())
                ->all()
            : [];

        $siblings = ! empty($parentIds)
            ? MarketSpace::query()
                ->whereIn('space_group_parent_id', $parentIds)
                ->whereNotIn('id', $spaceIds)
                ->orderBy('space_group_parent_id')
                ->orderBy('id')
                ->get(['id', 'market_id', 'number', 'code', 'display_name', 'space_group_role', 'space_group_parent_id', 'space_group_slot', 'space_group_token'])
                ->map(fn (MarketSpace $space): array => $space->toArray())
                ->all()
            : [];

        $sameToken = ! empty($tokens)
            ? MarketSpace::query()
                ->whereIn('space_group_token', $tokens)
                ->whereNotIn('id', $spaceIds)
                ->orderBy('space_group_token')
                ->orderBy('id')
                ->get(['id', 'market_id', 'number', 'code', 'display_name', 'space_group_role', 'space_group_parent_id', 'space_group_slot', 'space_group_token'])
                ->map(fn (MarketSpace $space): array => $space->toArray())
                ->all()
            : [];

        $spaceArray = $spaces->values()->all();
        $flags = [
            'same_number' => trim((string) ($spaceArray[0]->number ?? '')) !== ''
                && trim((string) ($spaceArray[0]->number ?? '')) === trim((string) ($spaceArray[1]->number ?? '')),
            'same_code' => trim((string) ($spaceArray[0]->code ?? '')) !== ''
                && trim((string) ($spaceArray[0]->code ?? '')) === trim((string) ($spaceArray[1]->code ?? '')),
            'different_slots' => trim((string) ($spaceArray[0]->space_group_slot ?? '')) !== trim((string) ($spaceArray[1]->space_group_slot ?? '')),
            'same_group_token' => trim((string) ($spaceArray[0]->space_group_token ?? '')) !== ''
                && trim((string) ($spaceArray[0]->space_group_token ?? '')) === trim((string) ($spaceArray[1]->space_group_token ?? '')),
            'child_without_parent' => collect($spaceArray)->contains(
                fn (MarketSpace $space): bool => (string) $space->space_group_role === MarketSpace::SPACE_GROUP_ROLE_CHILD
                    && (int) ($space->space_group_parent_id ?? 0) <= 0
            ),
        ];

        return [
            'parents' => $parents,
            'siblings' => $siblings,
            'same_group_token_spaces' => $sameToken,
            'flags' => $flags,
        ];
    }

    /**
     * @param  list<int>  $spaceIds
     * @return array<string, mixed>
     */
    private function financialTailsSummary(array $spaceIds, int $marketId): array
    {
        if (! Schema::hasTable('tenant_accruals') || ! Schema::hasColumn('tenant_accruals', 'market_space_id')) {
            return ['available' => false, 'rows' => []];
        }

        $columns = ['ta.id', 'ta.market_space_id'];
        $optionalColumns = [
            'period',
            'tenant_id',
            'tenant_contract_id',
            'contract_external_id',
            'rent_amount',
            'management_fee',
            'utilities_amount',
            'electricity_amount',
            'total_no_vat',
            'total_with_vat',
            'cash_amount',
            'status',
            'source',
            'source_place_code',
            'source_place_name',
            'activity_type',
            'contract_link_status',
            'contract_link_source',
            'contract_link_note',
            'imported_at',
            'source_file',
            'source_row_number',
        ];

        foreach ($optionalColumns as $column) {
            if (Schema::hasColumn('tenant_accruals', $column)) {
                $columns[] = 'ta.' . $column;
            }
        }

        $tenantNameExpression = Schema::hasTable('tenants')
            ? "COALESCE(NULLIF(TRIM(t.short_name), ''), NULLIF(TRIM(t.name), '')) as tenant_name"
            : "NULL as tenant_name";

        $rows = DB::table('tenant_accruals as ta')
            ->when(Schema::hasTable('tenants'), function ($query): void {
                $query->leftJoin('tenants as t', 't.id', '=', 'ta.tenant_id');
            })
            ->selectRaw(implode(', ', $columns) . ', ' . $tenantNameExpression)
            ->where('ta.market_id', $marketId)
            ->whereIn('ta.market_space_id', $spaceIds)
            ->orderByDesc(Schema::hasColumn('tenant_accruals', 'period') ? 'ta.period' : 'ta.id')
            ->orderByDesc('ta.id')
            ->get()
            ->map(fn (object $row): array => (array) $row)
            ->all();

        return [
            'available' => true,
            'rows' => $rows,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $spacesSummary
     * @param  array<string, mixed>  $accrualsSummary
     * @return list<int>
     */
    private function collectTenantIds(array $spacesSummary, array $accrualsSummary): array
    {
        $tenantIds = [];

        foreach ($spacesSummary as $row) {
            $tenantId = (int) ($row['tenant_id'] ?? 0);
            if ($tenantId > 0) {
                $tenantIds[] = $tenantId;
            }
        }

        foreach (($accrualsSummary['rows'] ?? []) as $row) {
            $tenantId = (int) ($row['tenant_id'] ?? 0);
            if ($tenantId > 0) {
                $tenantIds[] = $tenantId;
            }
        }

        $tenantIds = array_values(array_unique($tenantIds));
        sort($tenantIds);

        return $tenantIds;
    }

    /**
     * @param  list<int>  $spaceIds
     * @param  list<int>  $tenantIds
     * @return array<string, mixed>
     */
    private function contractsSummary(array $spaceIds, array $tenantIds, int $marketId): array
    {
        if (! Schema::hasTable('tenant_contracts')) {
            return ['available' => false, 'rows' => [], 'contract_debts' => []];
        }

        $query = DB::table('tenant_contracts')
            ->where('market_id', $marketId)
            ->where(function ($inner) use ($spaceIds, $tenantIds): void {
                $inner->whereIn('market_space_id', $spaceIds);

                if (! empty($tenantIds)) {
                    $inner->orWhereIn('tenant_id', $tenantIds);
                }
            });

        $columns = [
            'id',
            'market_space_id',
            'tenant_id',
            'external_id',
            'number',
            'status',
            'starts_at',
            'ends_at',
            'signed_at',
            'is_active',
        ];

        if (Schema::hasColumn('tenant_contracts', 'space_mapping_mode')) {
            $columns[] = 'space_mapping_mode';
        }

        $contracts = $query
            ->orderByDesc(Schema::hasColumn('tenant_contracts', 'starts_at') ? 'starts_at' : 'id')
            ->orderByDesc('id')
            ->get($columns)
            ->map(fn (object $row): array => (array) $row)
            ->all();

        $contractIds = array_values(array_filter(array_map(
            static fn (array $row): int => (int) ($row['id'] ?? 0),
            $contracts
        )));

        $contractDebts = [];
        if (! empty($contractIds) && Schema::hasTable('contract_debts')) {
            $debtColumns = ['id', 'tenant_contract_id'];
            foreach (['period', 'status', 'amount', 'debt_amount', 'paid_amount', 'source'] as $column) {
                if (Schema::hasColumn('contract_debts', $column)) {
                    $debtColumns[] = $column;
                }
            }

            $contractDebts = DB::table('contract_debts')
                ->whereIn('tenant_contract_id', $contractIds)
                ->orderByDesc(Schema::hasColumn('contract_debts', 'period') ? 'period' : 'id')
                ->orderByDesc('id')
                ->get($debtColumns)
                ->map(fn (object $row): array => (array) $row)
                ->all();
        }

        return [
            'available' => true,
            'rows' => $contracts,
            'contract_debts' => $contractDebts,
        ];
    }

    /**
     * @param  array<string, mixed>  $contractsSummary
     * @return list<int>
     */
    private function contractTenantIds(array $contractsSummary): array
    {
        $tenantIds = [];

        foreach (($contractsSummary['rows'] ?? []) as $row) {
            $tenantId = (int) ($row['tenant_id'] ?? 0);
            if ($tenantId > 0) {
                $tenantIds[] = $tenantId;
            }
        }

        $tenantIds = array_values(array_unique($tenantIds));
        sort($tenantIds);

        return $tenantIds;
    }

    /**
     * @param  list<int>  $tenantIds
     * @return array<string, mixed>
     */
    private function tenantsSummary(array $tenantIds, int $marketId): array
    {
        if (empty($tenantIds) || ! Schema::hasTable('tenants')) {
            return ['available' => Schema::hasTable('tenants'), 'rows' => []];
        }

        $columns = ['id', 'market_id', 'name'];
        foreach (['short_name', 'external_id', 'one_c_uid', 'status', 'is_active', 'updated_at'] as $column) {
            if (Schema::hasColumn('tenants', $column)) {
                $columns[] = $column;
            }
        }

        return [
            'available' => true,
            'rows' => DB::table('tenants')
                ->where('market_id', $marketId)
                ->whereIn('id', $tenantIds)
                ->orderBy('id')
                ->get($columns)
                ->map(fn (object $row): array => (array) $row)
                ->all(),
        ];
    }

    /**
     * @param  list<int>  $spaceIds
     * @return array<string, mixed>
     */
    private function historySummary(array $spaceIds, int $marketId): array
    {
        return [
            'tenant_histories' => $this->tableRowsIfAvailable(
                'market_space_tenant_histories',
                ['id', 'market_space_id', 'old_tenant_id', 'new_tenant_id', 'changed_at', 'changed_by_user_id', 'created_at'],
                fn ($query) => $query->whereIn('market_space_id', $spaceIds)->orderByDesc('id')
            ),
            'tenant_bindings' => $this->tableRowsIfAvailable(
                'market_space_tenant_bindings',
                ['id', 'market_space_id', 'tenant_id', 'tenant_contract_id', 'binding_source', 'starts_at', 'ends_at', 'is_active', 'created_at'],
                fn ($query) => $query->whereIn('market_space_id', $spaceIds)->orderByDesc('id')
            ),
            'operations' => $this->operationsSummary($spaceIds, $marketId),
        ];
    }

    /**
     * @param  list<int>  $spaceIds
     * @return array<string, mixed>
     */
    private function operationsSummary(array $spaceIds, int $marketId): array
    {
        if (! Schema::hasTable('operations')) {
            return ['available' => false, 'rows' => []];
        }

        $payloadSelect = DB::getDriverName() === 'pgsql'
            ? 'CAST(payload AS TEXT) as payload_json'
            : 'payload as payload_json';

        $rows = DB::table('operations')
            ->selectRaw(
                'id, market_id, entity_type, entity_id, type, status, effective_at, created_by, comment, ' . $payloadSelect
            )
            ->where('market_id', $marketId)
            ->whereIn('entity_id', $spaceIds)
            ->whereIn('type', [
                OperationType::SPACE_REVIEW,
                OperationType::TENANT_SWITCH,
                OperationType::SPACE_ATTRS_CHANGE,
                OperationType::GROUP_MEMBERSHIP,
            ])
            ->orderByDesc('effective_at')
            ->orderByDesc('id')
            ->get()
            ->map(function (object $row): array {
                $data = (array) $row;
                $payload = $data['payload_json'] ?? null;
                if (is_string($payload) && $payload !== '') {
                    $decoded = json_decode($payload, true);
                    $data['payload_summary'] = is_array($decoded)
                        ? array_intersect_key($decoded, array_flip([
                            'market_space_id',
                            'decision',
                            'reason',
                            'to_tenant_id',
                            'from_group_parent_id',
                            'candidate_market_space_id',
                            'detach_from_group',
                        ]))
                        : $payload;
                } else {
                    $data['payload_summary'] = null;
                }

                unset($data['payload_json']);

                return $data;
            })
            ->all();

        return [
            'available' => true,
            'rows' => $rows,
        ];
    }

    /**
     * @param  list<int>  $spaceIds
     * @param  list<string>  $candidateColumns
     * @return array<string, mixed>
     */
    private function tableRowsIfAvailable(string $table, array $candidateColumns, callable $scope): array
    {
        if (! Schema::hasTable($table)) {
            return ['available' => false, 'rows' => []];
        }

        $columns = array_values(array_filter(
            $candidateColumns,
            fn (string $column): bool => Schema::hasColumn($table, $column)
        ));

        if (empty($columns)) {
            return ['available' => true, 'rows' => []];
        }

        $query = DB::table($table)->select($columns);
        $scope($query);

        return [
            'available' => true,
            'rows' => $query->get()->map(fn (object $row): array => (array) $row)->all(),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $spacesSummary
     * @param  array<string, mixed>  $shapeSummary
     * @param  array<string, mixed>  $accrualsSummary
     * @param  array<string, mixed>  $contractsSummary
     * @return array<string, mixed>
     */
    private function mergeRiskSummary(
        MarketSpace $spaceA,
        MarketSpace $spaceB,
        array $spacesSummary,
        array $shapeSummary,
        array $accrualsSummary,
        array $contractsSummary,
        DuplicateSpaceResolutionService $duplicateResolver
    ): array {
        $spaceAId = (int) $spaceA->id;
        $spaceBId = (int) $spaceB->id;
        $accrualRows = $accrualsSummary['rows'] ?? [];
        $contractRows = $contractsSummary['rows'] ?? [];

        $accrualsA = array_values(array_filter($accrualRows, fn (array $row): bool => (int) ($row['market_space_id'] ?? 0) === $spaceAId));
        $accrualsB = array_values(array_filter($accrualRows, fn (array $row): bool => (int) ($row['market_space_id'] ?? 0) === $spaceBId));
        $contractsA = array_values(array_filter($contractRows, fn (array $row): bool => (int) ($row['market_space_id'] ?? 0) === $spaceAId));
        $contractsB = array_values(array_filter($contractRows, fn (array $row): bool => (int) ($row['market_space_id'] ?? 0) === $spaceBId));

        $tenantIdsA = $this->uniqueInts(array_merge(
            [(int) ($spaceA->tenant_id ?? 0)],
            array_map(fn (array $row): int => (int) ($row['tenant_id'] ?? 0), $accrualsA),
            array_map(fn (array $row): int => (int) ($row['tenant_id'] ?? 0), $contractsA)
        ));
        $tenantIdsB = $this->uniqueInts(array_merge(
            [(int) ($spaceB->tenant_id ?? 0)],
            array_map(fn (array $row): int => (int) ($row['tenant_id'] ?? 0), $accrualsB),
            array_map(fn (array $row): int => (int) ($row['tenant_id'] ?? 0), $contractsB)
        ));

        $codesA = $this->uniqueStrings(array_map(fn (array $row): string => (string) ($row['source_place_code'] ?? ''), $accrualsA));
        $codesB = $this->uniqueStrings(array_map(fn (array $row): string => (string) ($row['source_place_code'] ?? ''), $accrualsB));

        return [
            'facts' => [
                'both_have_financial_tails' => ! empty($accrualsA) && ! empty($accrualsB),
                'tenant_ids_match' => ! empty($tenantIdsA) && ! empty($tenantIdsB) && $tenantIdsA === $tenantIdsB,
                'source_place_codes_match' => ! empty($codesA) && ! empty($codesB) && $codesA === $codesB,
                'contracts_exist_on_any_space' => ! empty($contractsA) || ! empty($contractsB),
                'shape_counts' => $shapeSummary['counts'] ?? [],
                'space_a_can_be_considered_empty' => $this->spaceCanBeConsideredEmpty($spaceAId, $shapeSummary, $accrualsA, $contractsA, $spacesSummary),
                'space_b_can_be_considered_empty' => $this->spaceCanBeConsideredEmpty($spaceBId, $shapeSummary, $accrualsB, $contractsB, $spacesSummary),
            ],
            'preview_a_to_b' => $this->previewSummary($duplicateResolver, (int) $spaceA->market_id, $spaceAId, $spaceBId),
            'preview_b_to_a' => $this->previewSummary($duplicateResolver, (int) $spaceA->market_id, $spaceBId, $spaceAId),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $spacesSummary
     * @param  array<int, array<string, mixed>>  $contractsA
     * @param  array<int, array<string, mixed>>  $accrualsA
     */
    private function spaceCanBeConsideredEmpty(
        int $spaceId,
        array $shapeSummary,
        array $accruals,
        array $contracts,
        array $spacesSummary
    ): bool {
        $shapeCount = (int) (($shapeSummary['counts'] ?? [])[(string) $spaceId] ?? 0);
        $space = collect($spacesSummary)->firstWhere('id', $spaceId);

        return $shapeCount === 0
            && empty($accruals)
            && empty($contracts)
            && (int) ($space['tenant_id'] ?? 0) <= 0;
    }

    /**
     * @return array<string, mixed>
     */
    private function previewSummary(
        DuplicateSpaceResolutionService $duplicateResolver,
        int $marketId,
        int $duplicateSpaceId,
        int $canonicalSpaceId
    ): array {
        try {
            return [
                'ok' => true,
                'result' => $duplicateResolver->preview($marketId, $duplicateSpaceId, $canonicalSpaceId),
            ];
        } catch (ValidationException $exception) {
            return [
                'ok' => false,
                'message' => collect($exception->errors())->flatten()->implode(' | '),
                'errors' => $exception->errors(),
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $shapeSummary
     * @param  array<string, mixed>  $accrualsSummary
     * @param  array<string, mixed>  $contractsSummary
     * @param  array<string, mixed>  $mergeRiskSummary
     * @param  array<int, true>  $spaceIdLookup
     * @return array<string, mixed>
     */
    private function recommendationSummary(
        MarketSpace $spaceA,
        MarketSpace $spaceB,
        array $shapeSummary,
        array $accrualsSummary,
        array $contractsSummary,
        array $mergeRiskSummary,
        array $spaceIdLookup
    ): array {
        $candidate = 'manual confirmation required';
        $reasons = [];
        $shapeCounts = $shapeSummary['counts'] ?? [];
        $accrualRows = $accrualsSummary['rows'] ?? [];
        $contractRows = $contractsSummary['rows'] ?? [];

        $spaceAId = (int) $spaceA->id;
        $spaceBId = (int) $spaceB->id;

        $spaceAFacts = [
            'shape_count' => (int) ($shapeCounts[(string) $spaceAId] ?? 0),
            'accrual_count' => count(array_filter($accrualRows, fn (array $row): bool => (int) ($row['market_space_id'] ?? 0) === $spaceAId)),
            'contract_count' => count(array_filter($contractRows, fn (array $row): bool => (int) ($row['market_space_id'] ?? 0) === $spaceAId)),
        ];
        $spaceBFacts = [
            'shape_count' => (int) ($shapeCounts[(string) $spaceBId] ?? 0),
            'accrual_count' => count(array_filter($accrualRows, fn (array $row): bool => (int) ($row['market_space_id'] ?? 0) === $spaceBId)),
            'contract_count' => count(array_filter($contractRows, fn (array $row): bool => (int) ($row['market_space_id'] ?? 0) === $spaceBId)),
        ];

        $previewAToB = $mergeRiskSummary['preview_a_to_b'] ?? [];
        $previewBToA = $mergeRiskSummary['preview_b_to_a'] ?? [];

        if (($previewAToB['ok'] ?? false) === true && ($previewBToA['ok'] ?? false) !== true) {
            $candidate = (string) $spaceBId;
            $reasons[] = 'Existing preview says ' . $spaceAId . ' can retire into ' . $spaceBId . ' but reverse preview is blocked.';
        } elseif (($previewBToA['ok'] ?? false) === true && ($previewAToB['ok'] ?? false) !== true) {
            $candidate = (string) $spaceAId;
            $reasons[] = 'Existing preview says ' . $spaceBId . ' can retire into ' . $spaceAId . ' but reverse preview is blocked.';
        } elseif ($spaceAFacts['shape_count'] > 0 && $spaceBFacts['shape_count'] === 0 && $spaceBFacts['accrual_count'] === 0 && $spaceBFacts['contract_count'] === 0) {
            $candidate = (string) $spaceAId;
            $reasons[] = 'Space ' . $spaceAId . ' has map evidence while ' . $spaceBId . ' looks operationally empty.';
        } elseif ($spaceBFacts['shape_count'] > 0 && $spaceAFacts['shape_count'] === 0 && $spaceAFacts['accrual_count'] === 0 && $spaceAFacts['contract_count'] === 0) {
            $candidate = (string) $spaceBId;
            $reasons[] = 'Space ' . $spaceBId . ' has map evidence while ' . $spaceAId . ' looks operationally empty.';
        }

        if ($candidate === 'manual confirmation required') {
            $reasons[] = 'Evidence is mixed or merge preview is blocked in both directions.';
        }

        return [
            'canonical_candidate' => $candidate,
            'reasons' => $reasons,
            'manual_confirmation_required' => $candidate === 'manual confirmation required',
            'confirm_with_accountant_or_market' => [
                'Which space number/code is the real current identity.',
                'Which financial tail belongs to the real place.',
                'Whether any unmatched accruals must be moved or retired before merge.',
            ],
            'space_fact_summary' => [
                (string) $spaceAId => $spaceAFacts,
                (string) $spaceBId => $spaceBFacts,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function environmentSummary(): array
    {
        $connection = (string) config('database.default');
        $config = (array) config('database.connections.' . $connection, []);

        return [
            'app_env' => (string) config('app.env'),
            'connection' => $connection,
            'driver' => (string) ($config['driver'] ?? ''),
            'database' => (string) ($config['database'] ?? ''),
            'username' => (string) ($config['username'] ?? ''),
            'git_head' => $this->gitHead(),
            'cwd' => base_path(),
        ];
    }

    private function gitHead(): ?string
    {
        $output = @shell_exec('git rev-parse --short HEAD 2>NUL');
        if (! is_string($output)) {
            return null;
        }

        $head = trim($output);

        return $head !== '' ? $head : null;
    }

    /**
     * @param  list<int>  $values
     * @return list<int>
     */
    private function uniqueInts(array $values): array
    {
        $values = array_values(array_filter(array_map('intval', $values), fn (int $value): bool => $value > 0));
        $values = array_values(array_unique($values));
        sort($values);

        return $values;
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function uniqueStrings(array $values): array
    {
        $values = array_values(array_filter(array_map(
            static fn (string $value): string => trim($value),
            $values
        ), fn (string $value): bool => $value !== ''));
        $values = array_values(array_unique($values));
        sort($values);

        return $values;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeJson(string $label, array $payload): void
    {
        $this->line('');
        $this->line('[' . $label . ']');
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->line($json === false ? '{}' : $json);
    }
}
