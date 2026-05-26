<?php
# app/Observers/MarketSpaceGroupSharedUseObserver.php

declare(strict_types=1);

namespace App\Observers;

use App\Models\MarketSpace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class MarketSpaceGroupSharedUseObserver
{
    public function saving(MarketSpace $space): void
    {
        $role = (string) ($space->space_group_role ?? MarketSpace::SPACE_GROUP_ROLE_NONE);

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
