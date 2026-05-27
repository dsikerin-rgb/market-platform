<?php
# app/Observers/MarketSpaceTenantBindingSharedUseObserver.php

declare(strict_types=1);

namespace App\Observers;

use App\Models\MarketSpace;
use App\Models\MarketSpaceTenantBinding;
use Illuminate\Validation\ValidationException;

final class MarketSpaceTenantBindingSharedUseObserver
{
    public function saving(MarketSpaceTenantBinding $binding): void
    {
        if ((string) ($binding->binding_type ?? '') !== 'shared_use' || filled($binding->ended_at)) {
            return;
        }

        $spaceId = (int) ($binding->market_space_id ?? 0);
        if ($spaceId <= 0) {
            return;
        }

        $space = MarketSpace::query()->find($spaceId);
        if (! $space instanceof MarketSpace) {
            return;
        }

        $role = (string) ($space->space_group_role ?? MarketSpace::SPACE_GROUP_ROLE_NONE);
        if ($role === MarketSpace::SPACE_GROUP_ROLE_NONE) {
            return;
        }

        throw ValidationException::withMessages([
            'market_space_id' => 'Групповое место нельзя сделать местом совместного использования.',
        ]);
    }
}
