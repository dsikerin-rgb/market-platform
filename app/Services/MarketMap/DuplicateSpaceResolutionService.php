<?php

declare(strict_types=1);

namespace App\Services\MarketMap;

use App\Models\MarketSpace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class DuplicateSpaceResolutionService
{
    /**
     * @var list<array{table: string, column: string, label: string}>
     */
    private const BLOCKING_LINKS = [
        ['table' => 'tenant_contracts', 'column' => 'market_space_id', 'label' => 'contracts'],
        ['table' => 'tenant_accruals', 'column' => 'market_space_id', 'label' => 'accruals'],
        ['table' => 'market_space_tenant_bindings', 'column' => 'market_space_id', 'label' => 'tenant_bindings'],
        ['table' => 'tenant_requests', 'column' => 'market_space_id', 'label' => 'requests'],
        ['table' => 'tickets', 'column' => 'market_space_id', 'label' => 'tickets'],
        ['table' => 'tenant_reviews', 'column' => 'market_space_id', 'label' => 'reviews'],
        ['table' => 'tenant_space_showcases', 'column' => 'market_space_id', 'label' => 'showcases'],
        ['table' => 'marketplace_chats', 'column' => 'market_space_id', 'label' => 'marketplace_chats'],
    ];

    /**
     * @return array{
     *     duplicate_market_space_id: int,
     *     canonical_market_space_id: int,
     *     transfer_counts: array<string, int>,
     *     blocking_counts: array<string, int>
     * }
     */
    public function preview(int $marketId, int $duplicateSpaceId, int $canonicalSpaceId): array
    {
        [$duplicate, $canonical] = $this->loadSpaces($marketId, $duplicateSpaceId, $canonicalSpaceId, false);
        $this->validatePair($duplicate, $canonical, $duplicateSpaceId, $canonicalSpaceId);

        $blockingCounts = $this->blockingCounts($marketId, $duplicateSpaceId);
        $this->throwIfBlocked($blockingCounts);

        return [
            'duplicate_market_space_id' => $duplicateSpaceId,
            'canonical_market_space_id' => $canonicalSpaceId,
            'transfer_counts' => $this->transferPreviewCounts($marketId, $duplicateSpaceId),
            'blocking_counts' => $blockingCounts,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function resolve(int $marketId, int $duplicateSpaceId, int $canonicalSpaceId, ?int $userId = null): array
    {
        return DB::transaction(function () use ($marketId, $duplicateSpaceId, $canonicalSpaceId, $userId): array {
            [$duplicate, $canonical] = $this->loadSpaces($marketId, $duplicateSpaceId, $canonicalSpaceId, true);
            $this->validatePair($duplicate, $canonical, $duplicateSpaceId, $canonicalSpaceId);

            $blockingCounts = $this->blockingCounts($marketId, $duplicateSpaceId);
            $this->throwIfBlocked($blockingCounts);

            $now = now();
            $shapeSummary = $this->transferMapShapes($marketId, $duplicateSpaceId, $canonicalSpaceId, $now);
            $cabinetSummary = $this->transferCabinetLinks($duplicateSpaceId, $canonicalSpaceId, $now);
            $productsMoved = $this->transferMarketplaceProducts($marketId, $duplicateSpaceId, $canonicalSpaceId, $now);

            $spaceUpdate = [
                'is_active' => false,
                'updated_at' => $now,
            ];

            if (Schema::hasColumn('market_spaces', 'map_review_status')) {
                $spaceUpdate['map_review_status'] = 'changed';
            }

            if (Schema::hasColumn('market_spaces', 'map_reviewed_at')) {
                $spaceUpdate['map_reviewed_at'] = $now;
            }

            if (Schema::hasColumn('market_spaces', 'map_reviewed_by')) {
                $spaceUpdate['map_reviewed_by'] = $userId;
            }

            DB::table('market_spaces')
                ->where('market_id', $marketId)
                ->where('id', $duplicateSpaceId)
                ->update($spaceUpdate);

            return [
                'duplicate_market_space_id' => $duplicateSpaceId,
                'canonical_market_space_id' => $canonicalSpaceId,
                'transferred' => [
                    'map_shapes' => $shapeSummary['moved'],
                    'detached_conflicting_shapes' => $shapeSummary['detached_conflicts'],
                    'cabinet_links' => $cabinetSummary['moved'],
                    'merged_cabinet_links' => $cabinetSummary['merged'],
                    'marketplace_products' => $productsMoved,
                ],
                'blocking_counts' => $blockingCounts,
            ];
        });
    }

    /**
     * @return array{0: ?MarketSpace, 1: ?MarketSpace}
     */
    private function loadSpaces(int $marketId, int $duplicateSpaceId, int $canonicalSpaceId, bool $lock): array
    {
        $query = MarketSpace::query()
            ->where('market_id', $marketId)
            ->whereIn('id', [$duplicateSpaceId, $canonicalSpaceId]);

        if ($lock) {
            $query->lockForUpdate();
        }

        $spaces = $query
            ->get(['id', 'market_id', 'tenant_id', 'is_active'])
            ->keyBy('id');

        return [
            $spaces->get($duplicateSpaceId),
            $spaces->get($canonicalSpaceId),
        ];
    }

    private function validatePair(?MarketSpace $duplicate, ?MarketSpace $canonical, int $duplicateSpaceId, int $canonicalSpaceId): void
    {
        if ($duplicateSpaceId === $canonicalSpaceId) {
            throw ValidationException::withMessages([
                'candidate_market_space_id' => 'Canonical space must differ from duplicate space.',
            ]);
        }

        if (! $duplicate) {
            throw ValidationException::withMessages([
                'market_space_id' => 'Duplicate space was not found in the current market.',
            ]);
        }

        if (! $canonical) {
            throw ValidationException::withMessages([
                'candidate_market_space_id' => 'Canonical space was not found in the current market.',
            ]);
        }

        if (! (bool) ($canonical->is_active ?? true)) {
            throw ValidationException::withMessages([
                'candidate_market_space_id' => 'Canonical space must be active.',
            ]);
        }

        $duplicateTenantId = (int) ($duplicate->tenant_id ?? 0);
        $canonicalTenantId = (int) ($canonical->tenant_id ?? 0);

        if ($duplicateTenantId > 0 && $canonicalTenantId > 0 && $duplicateTenantId !== $canonicalTenantId) {
            throw ValidationException::withMessages([
                'candidate_market_space_id' => 'Duplicate and canonical spaces belong to different tenants.',
            ]);
        }
    }

    /**
     * @return array<string, int>
     */
    private function blockingCounts(int $marketId, int $duplicateSpaceId): array
    {
        $counts = [];

        foreach (self::BLOCKING_LINKS as $link) {
            $counts[$link['label']] = $this->countRows($link['table'], $link['column'], $duplicateSpaceId, $marketId);
        }

        return $counts;
    }

    /**
     * @param  array<string, int>  $blockingCounts
     */
    private function throwIfBlocked(array $blockingCounts): void
    {
        $nonZero = array_filter($blockingCounts, static fn (int $count): bool => $count > 0);

        if ($nonZero === []) {
            return;
        }

        throw ValidationException::withMessages([
            'market_space_id' => 'Duplicate space still has business links: ' . implode(', ', array_keys($nonZero)) . '.',
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function transferPreviewCounts(int $marketId, int $duplicateSpaceId): array
    {
        return [
            'map_shapes' => $this->countRows('market_space_map_shapes', 'market_space_id', $duplicateSpaceId, $marketId),
            'cabinet_links' => $this->countRows('tenant_user_market_spaces', 'market_space_id', $duplicateSpaceId, $marketId),
            'marketplace_products' => $this->countRows('marketplace_products', 'market_space_id', $duplicateSpaceId, $marketId),
        ];
    }

    private function countRows(string $table, string $column, int $spaceId, int $marketId): int
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return 0;
        }

        $query = DB::table($table)->where($column, $spaceId);

        if (Schema::hasColumn($table, 'market_id')) {
            $query->where('market_id', $marketId);
        }

        return (int) $query->count();
    }

    /**
     * @return array{moved: int, detached_conflicts: int}
     */
    private function transferMapShapes(int $marketId, int $duplicateSpaceId, int $canonicalSpaceId, mixed $now): array
    {
        if (! Schema::hasTable('market_space_map_shapes')) {
            return ['moved' => 0, 'detached_conflicts' => 0];
        }

        $sourceShapes = DB::table('market_space_map_shapes')
            ->where('market_id', $marketId)
            ->where('market_space_id', $duplicateSpaceId)
            ->get(['id', 'page', 'version']);

        $moved = 0;
        $detachedConflicts = 0;

        foreach ($sourceShapes as $shape) {
            $conflictIds = DB::table('market_space_map_shapes')
                ->where('market_id', $marketId)
                ->where('page', (int) $shape->page)
                ->where('version', (int) $shape->version)
                ->where('market_space_id', $canonicalSpaceId)
                ->where('id', '!=', (int) $shape->id)
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            if ($conflictIds !== []) {
                $detachedConflicts += DB::table('market_space_map_shapes')
                    ->whereIn('id', $conflictIds)
                    ->update([
                        'market_space_id' => null,
                        'is_active' => false,
                        'updated_at' => $now,
                    ]);
            }

            $moved += DB::table('market_space_map_shapes')
                ->where('id', (int) $shape->id)
                ->update([
                    'market_space_id' => $canonicalSpaceId,
                    'updated_at' => $now,
                ]);
        }

        return ['moved' => $moved, 'detached_conflicts' => $detachedConflicts];
    }

    /**
     * @return array{moved: int, merged: int}
     */
    private function transferCabinetLinks(int $duplicateSpaceId, int $canonicalSpaceId, mixed $now): array
    {
        if (! Schema::hasTable('tenant_user_market_spaces')) {
            return ['moved' => 0, 'merged' => 0];
        }

        $rows = DB::table('tenant_user_market_spaces')
            ->where('market_space_id', $duplicateSpaceId)
            ->get(['id', 'user_id']);

        $moved = 0;
        $merged = 0;

        foreach ($rows as $row) {
            $alreadyLinked = DB::table('tenant_user_market_spaces')
                ->where('user_id', (int) $row->user_id)
                ->where('market_space_id', $canonicalSpaceId)
                ->exists();

            if ($alreadyLinked) {
                $merged += DB::table('tenant_user_market_spaces')
                    ->where('id', (int) $row->id)
                    ->delete();

                continue;
            }

            $moved += DB::table('tenant_user_market_spaces')
                ->where('id', (int) $row->id)
                ->update([
                    'market_space_id' => $canonicalSpaceId,
                    'updated_at' => $now,
                ]);
        }

        return ['moved' => $moved, 'merged' => $merged];
    }

    private function transferMarketplaceProducts(int $marketId, int $duplicateSpaceId, int $canonicalSpaceId, mixed $now): int
    {
        if (! Schema::hasTable('marketplace_products')) {
            return 0;
        }

        return DB::table('marketplace_products')
            ->where('market_id', $marketId)
            ->where('market_space_id', $duplicateSpaceId)
            ->update([
                'market_space_id' => $canonicalSpaceId,
                'updated_at' => $now,
            ]);
    }
}
