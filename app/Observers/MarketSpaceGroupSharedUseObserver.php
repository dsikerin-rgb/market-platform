<?php
# app/Observers/MarketSpaceGroupSharedUseObserver.php

declare(strict_types=1);

namespace App\Observers;

use App\Models\MarketSpace;
use App\Services\MarketSpaces\MarketSpaceStateGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class MarketSpaceGroupSharedUseObserver
{
    public function __construct(
        private readonly MarketSpaceStateGuard $stateGuard,
    ) {
    }

    public function saving(MarketSpace $space): void
    {
        $role = (string) ($space->space_group_role ?? MarketSpace::SPACE_GROUP_ROLE_NONE);

        $this->stateGuard->assertCanPersist($space, []);

        if (
            $role === MarketSpace::SPACE_GROUP_ROLE_CHILD
            && blank($space->space_group_parent_id)
            && (! $space->exists || $space->isDirty('space_group_role') || $space->isDirty('space_group_parent_id'))
        ) {
            throw ValidationException::withMessages([
                'space_group_parent_id' => 'Для места в группе нужно выбрать родительскую группу.',
            ]);
        }

        if (
            $role === MarketSpace::SPACE_GROUP_ROLE_CHILD
            && filled($space->space_group_parent_id)
            && (! $space->exists || $space->isDirty('space_group_role') || $space->isDirty('space_group_parent_id'))
        ) {
            $parent = MarketSpace::query()->find((int) $space->space_group_parent_id);

            if (! $parent instanceof MarketSpace || (string) ($parent->space_group_role ?? '') !== MarketSpace::SPACE_GROUP_ROLE_PARENT) {
                throw ValidationException::withMessages([
                    'space_group_parent_id' => 'Родительская группа не найдена или больше не является группой.',
                ]);
            }
        }

        if ($role === MarketSpace::SPACE_GROUP_ROLE_NONE) {
            return;
        }

        if (! Schema::hasTable('market_space_tenant_bindings')) {
            return;
        }

        if ($space->exists && $this->hasActiveSharedUseBinding((int) $space->getKey())) {
            throw ValidationException::withMessages([
                'space_group_role' => 'Совместное место нельзя сделать группой или включить в группу мест.',
            ]);
        }

        if ($role === MarketSpace::SPACE_GROUP_ROLE_CHILD && filled($space->space_group_parent_id)) {
            $parentId = (int) $space->space_group_parent_id;

            if ($this->hasActiveSharedUseBinding($parentId)) {
                throw ValidationException::withMessages([
                    'space_group_parent_id' => 'Совместное место нельзя использовать как родительскую группу.',
                ]);
            }
        }
    }

    private function hasActiveSharedUseBinding(int $spaceId): bool
    {
        if ($spaceId <= 0) {
            return false;
        }

        return DB::table('market_space_tenant_bindings')
            ->where('market_space_id', $spaceId)
            ->where('binding_type', 'shared_use')
            ->whereNull('ended_at')
            ->exists();
    }
}
