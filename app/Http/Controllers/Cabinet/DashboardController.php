<?php

namespace App\Http\Controllers\Cabinet;

use App\Http\Controllers\Controller;
use App\Models\MarketSpace;
use App\Models\TenantDocument;
use App\Models\TenantAccrual;
use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $tenant = $request->user()->tenant;

        $accrualsQuery = TenantAccrual::query()
            ->where('tenant_id', $tenant->id)
            ->when($tenant->market_id, fn ($query) => $query->where('market_id', $tenant->market_id));

        $totalDebt = (float) $accrualsQuery->sum('total_with_vat');

        $latestPeriod = (string) TenantAccrual::query()
            ->where('tenant_id', $tenant->id)
            ->when($tenant->market_id, fn ($query) => $query->where('market_id', $tenant->market_id))
            ->orderByDesc('period')
            ->value('period');

        $monthAccruals = 0.0;

        if ($latestPeriod !== '') {
            $monthAccruals = (float) TenantAccrual::query()
                ->where('tenant_id', $tenant->id)
                ->when($tenant->market_id, fn ($query) => $query->where('market_id', $tenant->market_id))
                ->where('period', $latestPeriod)
                ->sum('total_with_vat');
        }

        $openRequestsCount = Ticket::query()
            ->where('tenant_id', $tenant->id)
            ->when($tenant->market_id, fn ($query) => $query->where('market_id', $tenant->market_id))
            ->whereNotIn('status', ['resolved', 'closed'])
            ->count();

        $documentsCount = TenantDocument::query()
            ->where('tenant_id', $tenant->id)
            ->count();

        $spacesCount = MarketSpace::query()
            ->where('tenant_id', $tenant->id)
            ->when($tenant->market_id, fn ($query) => $query->where('market_id', $tenant->market_id))
            ->count();

        return view('cabinet.dashboard', [
            'tenant' => $tenant,
            'totalDebt' => $totalDebt,
            'monthAccruals' => $monthAccruals,
            'latestPeriod' => $latestPeriod,
            'openRequestsCount' => $openRequestsCount,
            'documentsCount' => $documentsCount,
            'spacesCount' => $spacesCount,
        ]);
    }
}
