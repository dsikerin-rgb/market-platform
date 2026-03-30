<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Cabinet\TenantImpersonationService;
use App\Support\AdminPanelImpersonation;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TenantCabinetImpersonationController extends Controller
{
    public function issue(
        Request $request,
        Tenant $tenant,
        TenantImpersonationService $service,
    ): RedirectResponse {
        $impersonator = AdminPanelImpersonation::resolveAdminUser(Filament::auth()->user(), $request);
        abort_unless($impersonator instanceof User, 403);

        if (! $service->canIssue($impersonator, $tenant)) {
            $reason = $service->isCrossMarketDenied($impersonator, $tenant)
                ? 'cross_market_denied'
                : 'forbidden_role';

            $service->recordDenied($impersonator, $tenant, $request, $reason);
            abort(403, 'Недостаточно прав для входа в кабинет арендатора.');
        }

        $cabinetUser = $service->resolveCabinetUser($tenant);
        if (! $cabinetUser) {
            $service->recordDenied($impersonator, $tenant, $request, 'cabinet_user_missing');

            return back()->withErrors([
                'impersonation' => 'У арендатора не найден кабинетный пользователь (merchant/merchant-user).',
            ]);
        }

        $url = $service->issue($impersonator, $tenant, $cabinetUser, $request);

        return redirect()->to($url);
    }
}
