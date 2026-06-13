<?php

declare(strict_types=1);

namespace App\Services\MarketSpaces;

use App\Models\MarketSpace;
use App\Models\MarketSpaceTenantBinding;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class MarketSpaceStateGuard
{
    public function assertCanCreate(array $data): void
    {
        $status = $this->normalizeStatus($data['status'] ?? 'vacant');
        $role = $this->normalizeGroupRole($data['space_group_role'] ?? MarketSpace::SPACE_GROUP_ROLE_NONE);

        if ($status === 'maintenance' && $role !== MarketSpace::SPACE_GROUP_ROLE_NONE) {
            throw ValidationException::withMessages([
                'space_group_role' => 'Служебное место не может входить в группу и не может быть parent-группой.',
            ]);
        }
    }

    public function assertCanPersist(MarketSpace $space, array $data): void
    {
        $status = $this->normalizeStatus($data['status'] ?? $space->status ?? 'vacant');
        $role = $this->normalizeGroupRole($data['space_group_role'] ?? $space->space_group_role ?? MarketSpace::SPACE_GROUP_ROLE_NONE);
        $hasSharedUse = $this->hasActiveSharedUse($space);

        if ($status === 'maintenance' && $role !== MarketSpace::SPACE_GROUP_ROLE_NONE) {
            throw ValidationException::withMessages([
                'space_group_role' => 'Служебное место не может входить в группу и не может быть parent-группой.',
            ]);
        }

        if ($status === 'maintenance' && $hasSharedUse) {
            throw ValidationException::withMessages([
                'status' => 'Служебное место не может быть совместным местом.',
            ]);
        }

        if ($hasSharedUse && $role !== MarketSpace::SPACE_GROUP_ROLE_NONE) {
            throw ValidationException::withMessages([
                'space_group_role' => 'Совместное место не может входить в группу и не может быть parent-группой.',
            ]);
        }
    }

    public function assertCanStartSharedUse(MarketSpace $space): void
    {
        if ($this->isMaintenance($space)) {
            throw ValidationException::withMessages([
                'market_space_id' => 'Служебные места не могут быть совместными.',
            ]);
        }

        if ($this->isGrouped($space)) {
            throw ValidationException::withMessages([
                'market_space_id' => 'Групповые места и места внутри группы не могут быть совместными.',
            ]);
        }

        if ($this->hasActiveSharedUse($space)) {
            throw ValidationException::withMessages([
                'market_space_id' => 'Совместное использование уже включено для этого места.',
            ]);
        }
    }

    public function assertCanMarkAsService(MarketSpace $space, bool $allowParentGroupDissolve = false): void
    {
        if ($this->hasActiveSharedUse($space)) {
            throw ValidationException::withMessages([
                'status' => 'Сначала завершите совместное использование, затем переводите место в служебное.',
            ]);
        }

        if (! $this->isGrouped($space)) {
            return;
        }

        $isParentGroup = (string) ($space->space_group_role ?? '') === MarketSpace::SPACE_GROUP_ROLE_PARENT;
        if ($allowParentGroupDissolve && $isParentGroup) {
            return;
        }

        throw ValidationException::withMessages([
            'space_group_role' => 'Сначала уберите место из группы, затем переводите его в служебное.',
        ]);
    }

    public function assertCanAddToGroup(MarketSpace $space, MarketSpace $targetParent): void
    {
        if ($this->isMaintenance($space)) {
            throw ValidationException::withMessages([
                'market_space_id' => 'Служебное место нельзя добавить в группу.',
            ]);
        }

        if ($this->hasActiveSharedUse($space)) {
            throw ValidationException::withMessages([
                'market_space_id' => 'Совместное место нельзя добавить в группу.',
            ]);
        }

        if ($this->isMaintenance($targetParent)) {
            throw ValidationException::withMessages([
                'target_parent_id' => 'Служебное место не может быть родительской группой.',
            ]);
        }

        if ($this->hasActiveSharedUse($targetParent)) {
            throw ValidationException::withMessages([
                'target_parent_id' => 'Совместное место не может быть родительской группой.',
            ]);
        }
    }

    public function assertCanSwitchTenant(MarketSpace $space): void
    {
        if ($this->isMaintenance($space)) {
            throw ValidationException::withMessages([
                'target_tenant_id' => 'Служебному месту нельзя назначить арендатора.',
            ]);
        }

        if ($this->hasActiveSharedUse($space)) {
            throw ValidationException::withMessages([
                'target_tenant_id' => 'Для совместного места арендаторы меняются через участников совместного использования.',
            ]);
        }
    }

    public function hasActiveSharedUse(MarketSpace $space): bool
    {
        if (! filled($space->id) || ! Schema::hasTable('market_space_tenant_bindings')) {
            return false;
        }

        $spaceId = (int) $space->id;

        $hasCanonicalBindings = MarketSpaceTenantBinding::query()
            ->where('market_space_id', $spaceId)
            ->where('binding_type', 'shared_use')
            ->whereNull('ended_at')
            ->exists();

        if ($hasCanonicalBindings) {
            return true;
        }

        return $this->isSharedUseSourceSpace($space);
    }

    private function isMaintenance(MarketSpace $space): bool
    {
        return $this->normalizeStatus($space->status ?? 'vacant') === 'maintenance';
    }

    private function isGrouped(MarketSpace $space): bool
    {
        return $this->normalizeGroupRole($space->space_group_role ?? MarketSpace::SPACE_GROUP_ROLE_NONE) !== MarketSpace::SPACE_GROUP_ROLE_NONE
            || filled($space->space_group_parent_id);
    }

    private function isSharedUseSourceSpace(MarketSpace $space): bool
    {
        if (! filled($space->id) || ! filled($space->market_id)) {
            return false;
        }

        $sourceSpaceId = (int) $space->id;

        $bindings = DB::table('market_space_tenant_bindings as b')
            ->where('b.market_id', (int) $space->market_id)
            ->where('b.binding_type', 'shared_use')
            ->whereNull('b.ended_at')
            ->orderBy('b.id')
            ->get(['b.market_space_id', 'b.meta']);

        foreach ($bindings as $binding) {
            $canonicalSpaceId = (int) ($binding->market_space_id ?? 0);
            if ($canonicalSpaceId <= 0 || $canonicalSpaceId === $sourceSpaceId) {
                continue;
            }

            if (in_array($sourceSpaceId, $this->sharedUseSourceSpaceIdsFromMeta($binding->meta ?? null), true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<int>
     */
    private function sharedUseSourceSpaceIdsFromMeta(mixed $meta): array
    {
        $decoded = $this->decodeMeta($meta);
        $ids = [];

        foreach ([
            $decoded['source_space_ids'] ?? [],
            data_get($decoded, 'sklad21_shared_use.source_space_ids', []),
        ] as $value) {
            if (! is_array($value)) {
                continue;
            }

            foreach ($value as $sourceSpaceId) {
                if (is_numeric($sourceSpaceId)) {
                    $ids[(int) $sourceSpaceId] = true;
                }
            }
        }

        return array_map('intval', array_keys($ids));
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeMeta(mixed $meta): array
    {
        if (is_array($meta)) {
            return $meta;
        }

        if (! is_string($meta) || trim($meta) === '') {
            return [];
        }

        $decoded = json_decode($meta, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function normalizeStatus(mixed $status): string
    {
        $status = trim((string) $status);

        return $status === 'free' ? 'vacant' : $status;
    }

    private function normalizeGroupRole(mixed $role): string
    {
        $role = trim((string) $role);

        return $role !== '' ? $role : MarketSpace::SPACE_GROUP_ROLE_NONE;
    }
}
