<?php

namespace App\Http\Controllers\Cabinet;

use App\Http\Controllers\Controller;
use App\Models\MarketSpace;
use App\Models\TenantDocument;
use App\Models\TenantAccrual;
use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $tenant = $request->user()->tenant;
        $allowedSpaceIds = $request->user()->allowedTenantSpaceIds();
        $ticketHasSpaceColumn = $this->supportsTicketSpaceColumn();

        $accrualsQuery = TenantAccrual::query()
            ->where('tenant_id', $tenant->id)
            ->when($tenant->market_id, fn ($query) => $query->where('market_id', $tenant->market_id))
            ->when($allowedSpaceIds !== [], fn ($query) => $query->where(function ($q) use ($allowedSpaceIds): void {
                $q->whereNull('market_space_id')->orWhereIn('market_space_id', $allowedSpaceIds);
            }));

        $totalDebt = (float) $accrualsQuery->sum('total_with_vat');

        $latestPeriod = (string) TenantAccrual::query()
            ->where('tenant_id', $tenant->id)
            ->when($tenant->market_id, fn ($query) => $query->where('market_id', $tenant->market_id))
            ->when($allowedSpaceIds !== [], fn ($query) => $query->where(function ($q) use ($allowedSpaceIds): void {
                $q->whereNull('market_space_id')->orWhereIn('market_space_id', $allowedSpaceIds);
            }))
            ->orderByDesc('period')
            ->value('period');

        $monthAccruals = 0.0;

        if ($latestPeriod !== '') {
            $monthAccruals = (float) TenantAccrual::query()
                ->where('tenant_id', $tenant->id)
                ->when($tenant->market_id, fn ($query) => $query->where('market_id', $tenant->market_id))
                ->when($allowedSpaceIds !== [], fn ($query) => $query->where(function ($q) use ($allowedSpaceIds): void {
                    $q->whereNull('market_space_id')->orWhereIn('market_space_id', $allowedSpaceIds);
                }))
                ->where('period', $latestPeriod)
                ->sum('total_with_vat');
        }

        $openRequestsCount = Ticket::query()
            ->where('tenant_id', $tenant->id)
            ->when($tenant->market_id, fn ($query) => $query->where('market_id', $tenant->market_id))
            ->when($ticketHasSpaceColumn && $allowedSpaceIds !== [], fn ($query) => $query->where(function ($q) use ($allowedSpaceIds): void {
                $q->whereNull('market_space_id')->orWhereIn('market_space_id', $allowedSpaceIds);
            }))
            ->whereNotIn('status', ['resolved', 'closed'])
            ->count();

        $documentsCount = TenantDocument::query()
            ->where('tenant_id', $tenant->id)
            ->count();

        $spacesCount = MarketSpace::query()
            ->where('tenant_id', $tenant->id)
            ->when($tenant->market_id, fn ($query) => $query->where('market_id', $tenant->market_id))
            ->when($allowedSpaceIds !== [], fn ($query) => $query->whereIn('id', $allowedSpaceIds))
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

    private function supportsTicketSpaceColumn(): bool
    {
        try {
            if (! Schema::hasColumn('tickets', 'market_space_id')) {
                return false;
            }

            DB::table('tickets')
                ->select('id')
                ->whereNull('market_space_id')
                ->limit(1)
                ->get();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
