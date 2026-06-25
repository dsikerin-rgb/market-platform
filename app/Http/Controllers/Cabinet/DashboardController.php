<?php

namespace App\Http\Controllers\Cabinet;

use App\Http\Controllers\Controller;
use App\Models\ContractDebt;
use App\Models\MarketSpace;
use App\Models\TenantAccrual;
use App\Models\TenantDocument;
use App\Models\Ticket;
use App\Support\CabinetAssistanceMode;
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
        $canViewFinance = CabinetAssistanceMode::canViewFinance($request);

        $accrualsQuery = $canViewFinance ? TenantAccrual::query()
            ->where('tenant_id', $tenant->id)
            ->when($tenant->market_id, fn ($query) => $query->where('market_id', $tenant->market_id))
            ->when($allowedSpaceIds !== [], fn ($query) => $query->where(function ($q) use ($allowedSpaceIds): void {
                $q->whereNull('market_space_id')->orWhereIn('market_space_id', $allowedSpaceIds);
            })) : null;

        $totalDebt = $accrualsQuery ? (float) $accrualsQuery->sum('total_with_vat') : 0.0;

        $latestPeriod = $canViewFinance ? (string) TenantAccrual::query()
            ->where('tenant_id', $tenant->id)
            ->when($tenant->market_id, fn ($query) => $query->where('market_id', $tenant->market_id))
            ->when($allowedSpaceIds !== [], fn ($query) => $query->where(function ($q) use ($allowedSpaceIds): void {
                $q->whereNull('market_space_id')->orWhereIn('market_space_id', $allowedSpaceIds);
            }))
            ->orderByDesc('period')
            ->value('period') : '';

        $monthAccruals = 0.0;

        if ($canViewFinance && $latestPeriod !== '') {
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

        $securityDepositAmount = $canViewFinance ? ContractDebt::securityDepositAmountForTenant(
            (int) $tenant->market_id,
            (int) $tenant->id,
        ) : 0.0;

        return view('cabinet.dashboard', [
            'tenant' => $tenant,
            'totalDebt' => $totalDebt,
            'monthAccruals' => $monthAccruals,
            'securityDepositAmount' => $securityDepositAmount,
            'latestPeriod' => $latestPeriod,
            'openRequestsCount' => $openRequestsCount,
            'documentsCount' => $documentsCount,
            'spacesCount' => $spacesCount,
            'canViewFinance' => $canViewFinance,
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
