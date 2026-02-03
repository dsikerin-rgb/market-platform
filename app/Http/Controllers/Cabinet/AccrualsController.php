<?php

namespace App\Http\Controllers\Cabinet;

use App\Http\Controllers\Controller;
use App\Models\TenantAccrual;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AccrualsController extends Controller
{
    public function index(Request $request): View
    {
        $tenant = $request->user()->tenant;

        $month = $request->string('month')->toString();
        $onlyDebt = $request->boolean('only_debt');

        $query = TenantAccrual::query()
            ->where('tenant_id', $tenant->id)
            ->when($tenant->market_id, fn ($builder) => $builder->where('market_id', $tenant->market_id))
            ->orderByDesc('period');

        if ($month !== '') {
            $query->where('period', $month . '-01');
        }

        if ($onlyDebt) {
            $query->where(function ($builder) {
                $builder->whereNotNull('total_with_vat')
                    ->where('total_with_vat', '>', 0);
            });
        }

        $accruals = $query->get();

        $availableMonths = TenantAccrual::query()
            ->where('tenant_id', $tenant->id)
            ->when($tenant->market_id, fn ($builder) => $builder->where('market_id', $tenant->market_id))
            ->orderByDesc('period')
            ->pluck('period')
            ->map(fn ($period) => $period->format('Y-m'))
            ->unique()
            ->values();

        return view('cabinet.accruals.index', [
            'tenant' => $tenant,
            'accruals' => $accruals,
            'availableMonths' => $availableMonths,
            'selectedMonth' => $month,
            'onlyDebt' => $onlyDebt,
        ]);
    }
}
