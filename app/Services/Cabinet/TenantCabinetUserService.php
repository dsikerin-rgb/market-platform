<?php

declare(strict_types=1);

namespace App\Services\Cabinet;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class TenantCabinetUserService
{
    public function resolvePrimaryUser(Tenant $tenant): ?User
    {
        $roleScoped = User::query()
            ->where('tenant_id', (int) $tenant->id)
            ->whereHas('roles', static function ($query): void {
                $query->whereIn('name', ['merchant', 'merchant-user']);
            })
            ->orderBy('id')
            ->first();

        if ($roleScoped) {
            return $roleScoped;
        }

        return User::query()
            ->where('tenant_id', (int) $tenant->id)
            ->orderBy('id')
            ->first();
    }

    public function ensurePrimaryUser(
        Tenant $tenant,
        ?string $name = null,
        ?string $email = null,
        ?string $plainPassword = null,
    ): User {
        $user = $this->resolvePrimaryUser($tenant);
        $user ??= new User();

        $user->market_id = (int) $tenant->market_id;
        $user->tenant_id = (int) $tenant->id;

        $normalizedEmail = $this->normalizeEmail($email);
        if ($normalizedEmail !== null) {
            $user->email = $normalizedEmail;
        } elseif (! $user->exists || ! filled($user->email)) {
            $user->email = $this->generateUniqueDefaultEmail($tenant, $user->exists ? (int) $user->id : null);
        }

        $normalizedName = trim((string) ($name ?? ''));
        if ($normalizedName !== '') {
            $user->name = $normalizedName;
        } elseif (! filled($user->name)) {
            $user->name = trim((string) ($tenant->name ?? '')) ?: 'Арендатор';
        }

        $password = trim((string) ($plainPassword ?? ''));
        if ($password !== '') {
            $user->password = Hash::make($password);
        } elseif (! $user->exists || ! filled($user->password)) {
            $user->password = Hash::make(Str::random(32));
        }

        $user->save();
        $this->ensureCabinetRole($user, 'merchant');

        return $user;
    }

    public function ensureCabinetRole(User $user, string $preferredRole = 'merchant'): void
    {
        if (! method_exists($user, 'hasAnyRole') || ! method_exists($user, 'assignRole')) {
            return;
        }

        if ($user->hasAnyRole(['merchant', 'merchant-user'])) {
            return;
        }

        $first = $preferredRole === 'merchant-user' ? 'merchant-user' : 'merchant';

        Role::findOrCreate($first, 'web');
        $user->assignRole($first);
    }

    private function normalizeEmail(?string $email): ?string
    {
        $value = Str::lower(trim((string) ($email ?? '')));

        return $value === '' ? null : $value;
    }

    private function generateUniqueDefaultEmail(Tenant $tenant, ?int $excludeUserId = null): string
    {
        $base = sprintf(
            'tenant-%d-market-%d@cabinet.local',
            (int) $tenant->id,
            (int) $tenant->market_id,
        );

        $candidate = $base;
        $seq = 1;

        while ($this->emailExists($candidate, $excludeUserId)) {
            $seq++;
            $candidate = sprintf(
                'tenant-%d-market-%d-%d@cabinet.local',
                (int) $tenant->id,
                (int) $tenant->market_id,
                $seq,
            );
        }

        return $candidate;
    }

    private function emailExists(string $email, ?int $excludeUserId = null): bool
    {
        return User::query()
            ->whereRaw('LOWER(email) = ?', [Str::lower($email)])
            ->when($excludeUserId, static fn ($query, int $id) => $query->whereKeyNot($id))
            ->exists();
    }
}

