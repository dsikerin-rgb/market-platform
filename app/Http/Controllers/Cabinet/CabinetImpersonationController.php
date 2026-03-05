<?php

declare(strict_types=1);

namespace App\Http\Controllers\Cabinet;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Cabinet\TenantImpersonationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CabinetImpersonationController extends Controller
{
    public function consume(
        Request $request,
        string $token,
        TenantImpersonationService $service,
    ): RedirectResponse {
        $impersonator = Auth::user();
        abort_unless($impersonator instanceof User, 403, 'Для входа по ссылке требуется авторизация в админке.');

        if (! $request->hasValidSignature()) {
            abort(403, 'Ссылка для входа недействительна или устарела.');
        }

        $payload = $service->consumeToken($token);
        if (! is_array($payload)) {
            abort(403, 'Ссылка уже использована или срок действия истек.');
        }

        $auditId = (int) ($payload['audit_id'] ?? 0);
        $impersonatorId = (int) ($payload['impersonator_user_id'] ?? 0);
        $tenantId = (int) ($payload['tenant_id'] ?? 0);
        $cabinetUserId = (int) ($payload['cabinet_user_id'] ?? 0);

        if ($impersonatorId <= 0 || $impersonatorId !== (int) $impersonator->id) {
            if ($auditId > 0) {
                $service->markFailed($auditId, $request, 'impersonator_mismatch');
            }

            abort(403, 'Ссылка выдана для другого администратора.');
        }

        $cabinetUser = User::query()->find($cabinetUserId);
        if (! $cabinetUser || ! $cabinetUser->hasAnyRole(['merchant', 'merchant-user'])) {
            if ($auditId > 0) {
                $service->markFailed($auditId, $request, 'cabinet_user_missing');
            }

            abort(403, 'Кабинетный пользователь арендатора не найден.');
        }

        $tenant = Tenant::query()->find($tenantId);
        if (! $tenant || (int) $cabinetUser->tenant_id !== (int) $tenant->id) {
            if ($auditId > 0) {
                $service->markFailed($auditId, $request, 'tenant_mismatch');
            }

            abort(403, 'Некорректная связка арендатора и кабинетного пользователя.');
        }

        Auth::login($cabinetUser);
        $request->session()->regenerate();

        $request->session()->put(TenantImpersonationService::SESSION_KEY, [
            'impersonator_user_id' => (int) $impersonator->id,
            'impersonator_name' => (string) ($impersonator->name ?? ''),
            'tenant_id' => (int) $tenant->id,
            'tenant_name' => (string) ($tenant->display_name ?? $tenant->name ?? ''),
            'audit_id' => $auditId,
            'admin_return_url' => (string) ($payload['admin_return_url'] ?? url('/admin/tenants/' . (int) $tenant->id . '/edit')),
        ]);

        if ($auditId > 0) {
            $service->markActive($auditId, $request);
        }

        return redirect()->route('cabinet.dashboard');
    }

    public function exit(
        Request $request,
        TenantImpersonationService $service,
    ): RedirectResponse {
        $context = $request->session()->get(TenantImpersonationService::SESSION_KEY);
        if (! is_array($context)) {
            return redirect()->route('cabinet.dashboard');
        }

        $auditId = (int) ($context['audit_id'] ?? 0);
        $impersonatorId = (int) ($context['impersonator_user_id'] ?? 0);
        $tenantId = (int) ($context['tenant_id'] ?? 0);
        $adminReturnUrl = (string) ($context['admin_return_url'] ?? '');

        if ($auditId > 0) {
            $service->markEnded($auditId, $request);
        }

        $request->session()->forget(TenantImpersonationService::SESSION_KEY);

        $impersonator = $impersonatorId > 0
            ? User::query()->find($impersonatorId)
            : null;

        if (
            ! $impersonator
            || ! $impersonator->hasAnyRole(['super-admin', 'market-admin'])
        ) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('cabinet.login');
        }

        Auth::login($impersonator);
        $request->session()->regenerate();

        if ($adminReturnUrl !== '') {
            return redirect()->to($adminReturnUrl);
        }

        if ($tenantId > 0) {
            return redirect()->to(url('/admin/tenants/' . $tenantId . '/edit'));
        }

        return redirect()->to(url('/admin/tenants'));
    }
}

