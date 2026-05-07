<?php

declare(strict_types=1);

namespace App\Services\MarketMap;

use App\Models\MarketSpace;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class MergedSpaceRetirementService
{
    /**
     * @return array<string, mixed>
     */
    public function retire(
        int $marketId,
        int $retiredSpaceId,
        int $canonicalSpaceId,
        CarbonInterface $effectiveAt,
        ?int $userId = null,
        ?string $reason = null,
    ): array {
        return DB::transaction(function () use ($marketId, $retiredSpaceId, $canonicalSpaceId, $effectiveAt, $userId, $reason): array {
            [$retired, $canonical] = $this->loadSpaces($marketId, $retiredSpaceId, $canonicalSpaceId, true);
            $this->validatePair($retired, $canonical, $retiredSpaceId, $canonicalSpaceId);

            $now = now();
            $relationCounts = $this->relationCounts($marketId, $retiredSpaceId);
            $shapeCount = $this->deactivateMapShapes($marketId, $retiredSpaceId, $now);
            $closedBindings = $this->closeSnapshotBindings($marketId, $retiredSpaceId, $now);

            $note = $this->buildNote($canonical, $effectiveAt, $reason);
            $existingNotes = trim((string) ($retired?->notes ?? ''));

            $update = [
                'is_active' => false,
                'status' => 'maintenance',
                'notes' => trim($existingNotes . ($existingNotes !== '' ? "\n" : '') . $note),
                'updated_at' => $now,
            ];

            if (Schema::hasColumn('market_spaces', 'map_review_status')) {
                $update['map_review_status'] = 'changed';
            }

            if (Schema::hasColumn('market_spaces', 'map_reviewed_at')) {
                $update['map_reviewed_at'] = $now;
            }

            if (Schema::hasColumn('market_spaces', 'map_reviewed_by')) {
                $update['map_reviewed_by'] = $userId;
            }

            DB::table('market_spaces')
                ->where('market_id', $marketId)
                ->where('id', $retiredSpaceId)
                ->update($update);

            return [
                'retired_market_space_id' => $retiredSpaceId,
                'canonical_market_space_id' => $canonicalSpaceId,
                'effective_date' => $effectiveAt->toDateString(),
                'deactivated_map_shapes' => $shapeCount,
                'closed_snapshot_bindings' => $closedBindings,
                'historical_relation_counts' => $relationCounts,
            ];
        });
    }

    /**
     * @return array{0: ?MarketSpace, 1: ?MarketSpace}
     */
    private function loadSpaces(int $marketId, int $retiredSpaceId, int $canonicalSpaceId, bool $lock): array
    {
        $query = MarketSpace::query()
            ->where('market_id', $marketId)
            ->whereIn('id', [$retiredSpaceId, $canonicalSpaceId]);

        if ($lock) {
            $query->lockForUpdate();
        }

        $spaces = $query
            ->get(['id', 'market_id', 'number', 'display_name', 'is_active', 'notes'])
            ->keyBy('id');

        return [
            $spaces->get($retiredSpaceId),
            $spaces->get($canonicalSpaceId),
        ];
    }

    private function validatePair(?MarketSpace $retired, ?MarketSpace $canonical, int $retiredSpaceId, int $canonicalSpaceId): void
    {
        if ($retiredSpaceId === $canonicalSpaceId) {
            throw ValidationException::withMessages([
                'candidate_market_space_id' => 'Основное место должно отличаться от упраздняемого.',
            ]);
        }

        if (! $retired) {
            throw ValidationException::withMessages([
                'market_space_id' => 'Упраздняемое место не найдено в текущем рынке.',
            ]);
        }

        if (! $canonical) {
            throw ValidationException::withMessages([
                'candidate_market_space_id' => 'Основное место не найдено в текущем рынке.',
            ]);
        }

        if (! (bool) ($canonical->is_active ?? true)) {
            throw ValidationException::withMessages([
                'candidate_market_space_id' => 'Основное место должно быть активным.',
            ]);
        }
    }

    /**
     * @return array<string, int>
     */
    private function relationCounts(int $marketId, int $spaceId): array
    {
        $definitions = [
            'map_shapes' => ['market_space_map_shapes', 'market_space_id'],
            'contracts' => ['tenant_contracts', 'market_space_id'],
            'accruals' => ['tenant_accruals', 'market_space_id'],
            'cabinet_users' => ['tenant_user_market_spaces', 'market_space_id'],
            'tenant_bindings' => ['market_space_tenant_bindings', 'market_space_id'],
            'products' => ['marketplace_products', 'market_space_id'],
            'requests' => ['tenant_requests', 'market_space_id'],
            'tickets' => ['tickets', 'market_space_id'],
            'reviews' => ['tenant_reviews', 'market_space_id'],
            'showcases' => ['tenant_space_showcases', 'market_space_id'],
            'chats' => ['marketplace_chats', 'market_space_id'],
        ];

        $counts = [];

        foreach ($definitions as $key => [$table, $column]) {
            $counts[$key] = $this->countRows($table, $column, $spaceId, $marketId);
        }

        return $counts;
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

    private function deactivateMapShapes(int $marketId, int $spaceId, mixed $now): int
    {
        if (! Schema::hasTable('market_space_map_shapes')) {
            return 0;
        }

        $update = [
            'market_space_id' => null,
            'updated_at' => $now,
        ];

        if (Schema::hasColumn('market_space_map_shapes', 'is_active')) {
            $update['is_active'] = false;
        }

        return DB::table('market_space_map_shapes')
            ->where('market_id', $marketId)
            ->where('market_space_id', $spaceId)
            ->update($update);
    }

    private function closeSnapshotBindings(int $marketId, int $spaceId, mixed $now): int
    {
        if (! Schema::hasTable('market_space_tenant_bindings')) {
            return 0;
        }

        $query = DB::table('market_space_tenant_bindings')
            ->where('market_id', $marketId)
            ->where('market_space_id', $spaceId);

        if (Schema::hasColumn('market_space_tenant_bindings', 'tenant_contract_id')) {
            $query->whereNull('tenant_contract_id');
        }

        if (Schema::hasColumn('market_space_tenant_bindings', 'binding_type')) {
            $query->where('binding_type', 'space_snapshot');
        }

        if (Schema::hasColumn('market_space_tenant_bindings', 'ended_at')) {
            $query->whereNull('ended_at');
        }

        $update = ['updated_at' => $now];

        if (Schema::hasColumn('market_space_tenant_bindings', 'ended_at')) {
            $update['ended_at'] = $now;
        }

        if (Schema::hasColumn('market_space_tenant_bindings', 'resolution_reason')) {
            $update['resolution_reason'] = 'space_merged_into_canonical';
        }

        return $query->update($update);
    }

    private function buildNote(MarketSpace $canonical, CarbonInterface $effectiveAt, ?string $reason): string
    {
        $label = trim((string) ($canonical->number ?: $canonical->display_name ?: ('#' . $canonical->id)));
        $note = sprintf(
            '[%s] Место упразднено и объединено с основным местом %s с %s.',
            now()->format('Y-m-d H:i'),
            $label,
            $effectiveAt->toDateString(),
        );

        $reason = trim((string) $reason);

        return $reason !== '' ? $note . ' Причина: ' . $reason : $note;
    }
}
