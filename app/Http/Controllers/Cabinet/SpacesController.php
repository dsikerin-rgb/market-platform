<?php

namespace App\Http\Controllers\Cabinet;

use App\Http\Controllers\Controller;
use App\Models\MarketSpace;
use App\Models\TenantContract;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SpacesController extends Controller
{
    public function __invoke(Request $request): View
    {
        $tenant = $request->user()->tenant;

        $spaces = MarketSpace::query()
            ->where('tenant_id', $tenant->id)
            ->when($tenant->market_id, fn ($query) => $query->where('market_id', $tenant->market_id))
            ->orderBy('number')
            ->get();

        $contract = TenantContract::query()
            ->where('tenant_id', $tenant->id)
            ->when($tenant->market_id, fn ($query) => $query->where('market_id', $tenant->market_id))
            ->orderByDesc('starts_at')
            ->first();

        return view('cabinet.spaces', [
            'tenant' => $tenant,
            'spaces' => $spaces,
            'contract' => $contract,
        ]);
    }
}
