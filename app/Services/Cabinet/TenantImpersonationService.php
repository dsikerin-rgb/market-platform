<?php

declare(strict_types=1);

namespace App\Services\Cabinet;

use App\Models\CabinetImpersonationAudit;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class TenantImpersonationService
{
    public const TOKEN_TTL_SECONDS = 120;

    public const SESSION_KEY = 'cabinet_impersonation';

    private const CACHE_PREFIX = 'cabinet:impersonation:token:';

    private ?bool $hasAuditTable = null;

    public function canIssue(User $impersonator, Tenant $tenant): bool
    {
        if ($impersonator->isSuperAdmin()) {
            return true;
        }

        if (! $impersonator->hasRole('market-admin')) {
            return false;
        }

        return (int) ($impersonator->market_id ?? 0) === (int) $tenant->market_id;
    }

    public function isCrossMarketDenied(User $impersonator, Tenant $tenant): bool
    {
        return $impersonator->hasRole('market-admin')
            && (int) ($impersonator->market_id ?? 0) !== (int) $tenant->market_id;
    }

    public function resolveCabinetUser(Tenant $tenant): ?User
    {
        /** @var TenantCabinetUserService $cabinetUsers */
        $cabinetUsers = app(TenantCabinetUserService::class);

        try {
            return $cabinetUsers->ensurePrimaryUser($tenant);
        } catch (\Throwable) {
            return null;
        }
    }

    public function issue(User $impersonator, Tenant $tenant, User $cabinetUser, Request $request): string
    {
        $audit = $this->createAuditIfAvailable([
            'impersonator_user_id' => (int) $impersonator->id,
            'tenant_id' => (int) $tenant->id,
            'cabinet_user_id' => (int) $cabinetUser->id,
            'market_id' => (int) $tenant->market_id,
            'started_at' => null,
            'ended_at' => null,
            'ip' => $request->ip(),
            'user_agent' => $this->normalizeUserAgent($request->userAgent()),
            'status' => CabinetImpersonationAudit::STATUS_ISSUED,
            'reason' => null,
        ]);

        $token = Str::random(64);

        $payload = [
            'audit_id' => (int) ($audit?->id ?? 0),
            'impersonator_user_id' => (int) $impersonator->id,
            'tenant_id' => (int) $tenant->id,
            'cabinet_user_id' => (int) $cabinetUser->id,
            'market_id' => (int) $tenant->market_id,
            'admin_return_url' => url('/admin/tenants/' . (int) $tenant->id . '/edit'),
        ];

        Cache::put(
            $this->cacheKey($token),
            $payload,
            now()->addSeconds(self::TOKEN_TTL_SECONDS),
        );

        return URL::temporarySignedRoute(
            'cabinet.impersonate.consume',
            now()->addSeconds(self::TOKEN_TTL_SECONDS),
            ['token' => $token],
        );
    }

    /**
     * @return array<string, int|string>|null
     */
    public function consumeToken(string $token): ?array
    {
        $payload = Cache::pull($this->cacheKey($token));

        return is_array($payload) ? $payload : null;
    }

    public function recordDenied(
        User $impersonator,
        Tenant $tenant,
        Request $request,
        string $reason,
        ?int $cabinetUserId = null,
    ): ?CabinetImpersonationAudit {
        return $this->createAuditIfAvailable([
            'impersonator_user_id' => (int) $impersonator->id,
            'tenant_id' => (int) $tenant->id,
            'cabinet_user_id' => $cabinetUserId,
            'market_id' => (int) $tenant->market_id,
            'started_at' => null,
            'ended_at' => null,
            'ip' => $request->ip(),
            'user_agent' => $this->normalizeUserAgent($request->userAgent()),
            'status' => CabinetImpersonationAudit::STATUS_DENIED,
            'reason' => $reason,
        ]);
    }

    public function markActive(int $auditId, Request $request): void
    {
        if (! $this->hasAuditTable()) {
            return;
        }

        CabinetImpersonationAudit::query()
            ->whereKey($auditId)
            ->update([
                'status' => CabinetImpersonationAudit::STATUS_ACTIVE,
                'started_at' => now(),
                'ip' => $request->ip(),
                'user_agent' => $this->normalizeUserAgent($request->userAgent()),
                'updated_at' => now(),
            ]);
    }

    public function markEnded(int $auditId, Request $request): void
    {
        if (! $this->hasAuditTable()) {
            return;
        }

        CabinetImpersonationAudit::query()
            ->whereKey($auditId)
            ->update([
                'status' => CabinetImpersonationAudit::STATUS_ENDED,
                'ended_at' => now(),
                'updated_at' => now(),
                'ip' => $request->ip(),
                'user_agent' => $this->normalizeUserAgent($request->userAgent()),
            ]);
    }

    public function markFailed(int $auditId, Request $request, string $reason): void
    {
        if (! $this->hasAuditTable()) {
            return;
        }

        CabinetImpersonationAudit::query()
            ->whereKey($auditId)
            ->update([
                'status' => CabinetImpersonationAudit::STATUS_FAILED,
                'reason' => $reason,
                'updated_at' => now(),
                'ip' => $request->ip(),
                'user_agent' => $this->normalizeUserAgent($request->userAgent()),
            ]);
    }

    private function cacheKey(string $token): string
    {
        return self::CACHE_PREFIX . $token;
    }

    private function normalizeUserAgent(?string $userAgent): ?string
    {
        $value = trim((string) $userAgent);

        return $value === '' ? null : mb_substr($value, 0, 1000);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function createAuditIfAvailable(array $payload): ?CabinetImpersonationAudit
    {
        if (! $this->hasAuditTable()) {
            return null;
        }

        return CabinetImpersonationAudit::query()->create($payload);
    }

    private function hasAuditTable(): bool
    {
        if ($this->hasAuditTable !== null) {
            return $this->hasAuditTable;
        }

        try {
            $this->hasAuditTable = Schema::hasTable('cabinet_impersonation_audits');
        } catch (\Throwable) {
            $this->hasAuditTable = false;
        }

        return $this->hasAuditTable;
    }
}
