<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use App\Services\Auth\PortalAccessService;
use App\Services\Cabinet\TenantImpersonationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class CabinetTokenMismatchRecovery
{
    public function recover(Request $request): ?Response
    {
        if (! $request->hasSession()) {
            return null;
        }

        if ($this->isCabinetImpersonationExit($request)) {
            return $this->restoreAdminFromCabinetImpersonation($request);
        }

        if ($this->isCabinetLogout($request)) {
            Auth::guard('web')->logout();

            $request->session()->forget(PortalAccessService::SESSION_ACTIVE_MODE);
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('cabinet.login');
        }

        return null;
    }

    private function isCabinetImpersonationExit(Request $request): bool
    {
        return $request->isMethod('POST') && $request->is('cabinet/impersonation/exit');
    }

    private function restoreAdminFromCabinetImpersonation(Request $request): Response
    {
        $context = $request->session()->get(TenantImpersonationService::SESSION_KEY);
        if (! is_array($context)) {
            return redirect()->to(url('/admin'));
        }

        $service = app(TenantImpersonationService::class);

        $auditId = (int) ($context['audit_id'] ?? 0);
        $impersonatorId = (int) ($context['impersonator_user_id'] ?? 0);
        $tenantId = (int) ($context['tenant_id'] ?? 0);
        $adminReturnUrl = (string) ($context['admin_return_url'] ?? '');
        $marketId = $service->resolveMarketIdFromContext($context);

        if ($auditId > 0) {
            $service->markEnded($auditId, $request);
        }

        $request->session()->forget(TenantImpersonationService::SESSION_KEY);

        $impersonator = $impersonatorId > 0
            ? User::query()->find($impersonatorId)
            : null;

        if (
            ! $impersonator
            || ! AdminPanelImpersonation::hasAdminPanelRole($impersonator)
        ) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('cabinet.login');
        }

        Auth::login($impersonator);
        $request->session()->regenerate();
        app(MarketContext::class)->syncSelectedMarketIdInSession($marketId, 'admin');

        if ($adminReturnUrl !== '') {
            return redirect()->to($adminReturnUrl);
        }

        if ($tenantId > 0) {
            return redirect()->to(url('/admin/tenants/' . $tenantId . '/edit'));
        }

        return redirect()->to(url('/admin/tenants'));
    }

    private function isCabinetLogout(Request $request): bool
    {
        return $request->isMethod('POST') && $request->is('cabinet/logout');
    }
}
