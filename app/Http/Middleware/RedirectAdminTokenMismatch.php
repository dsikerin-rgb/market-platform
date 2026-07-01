<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Auth\PortalAccessService;
use App\Services\Cabinet\TenantImpersonationService;
use App\Support\AdminPanelImpersonation;
use App\Support\MarketContext;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Session\TokenMismatchException;

class RedirectAdminTokenMismatch
{
    public function handle(Request $request, Closure $next): mixed
    {
        try {
            return $next($request);
        } catch (TokenMismatchException $exception) {
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

            if (! $this->isAdminContext($request)) {
                throw $exception;
            }

            $loginUrl = Filament::getLoginUrl() ?? url('/admin/login');

            return redirect()
                ->to($loginUrl)
                ->with('status', 'Сессия истекла, войдите снова.');
        }
    }

    private function isCabinetImpersonationExit(Request $request): bool
    {
        return $request->isMethod('POST') && $request->is('cabinet/impersonation/exit');
    }

    private function restoreAdminFromCabinetImpersonation(Request $request): mixed
    {
        $context = $request->session()->get(TenantImpersonationService::SESSION_KEY);
        if (! is_array($context)) {
            return redirect()->to(url('/admin'));
        }

        $auditId = (int) ($context['audit_id'] ?? 0);
        $impersonatorId = (int) ($context['impersonator_user_id'] ?? 0);
        $tenantId = (int) ($context['tenant_id'] ?? 0);
        $adminReturnUrl = (string) ($context['admin_return_url'] ?? '');
        $marketId = app(TenantImpersonationService::class)->resolveMarketIdFromContext($context);

        if ($auditId > 0) {
            app(TenantImpersonationService::class)->markEnded($auditId, $request);
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

    private function isAdminContext(Request $request): bool
    {
        if ($request->is('admin') || $request->is('admin/*')) {
            return true;
        }

        if (! $request->is('livewire/update')) {
            return false;
        }

        $referer = (string) $request->headers->get('referer', '');

        return str_contains($referer, '/admin');
    }
}
