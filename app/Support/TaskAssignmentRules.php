<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

class TaskAssignmentRules
{
    public const MIN_ORGANIZATION_LEVEL = 1;
    public const MAX_ORGANIZATION_LEVEL = 10;

    public function canAssignWork(?User $actor, ?User $target): bool
    {
        if (! $actor || ! $target) {
            return false;
        }

        if ((int) $actor->id === (int) $target->id) {
            return true;
        }

        if ($this->isPrivilegedAssigner($actor)) {
            return true;
        }

        if (! $this->sameMarket($actor, $target)) {
            return false;
        }

        if ($this->isAdminTarget($target)) {
            return false;
        }

        if ($this->isSuperior($target, $actor)) {
            return false;
        }

        return true;
    }

    public function canObserve(?User $actor, ?User $target): bool
    {
        if (! $actor || ! $target) {
            return false;
        }

        if ($this->isPrivilegedAssigner($actor)) {
            return true;
        }

        return $this->sameMarket($actor, $target);
    }

    public function applyAssignableScope(Builder $query, ?User $actor): Builder
    {
        $query = $this->applyInternalStaffScope($query);

        if (! $actor) {
            return $query->whereRaw('1 = 0');
        }

        if ($this->isPrivilegedAssigner($actor)) {
            return $query;
        }

        $query->whereDoesntHave('roles', function (Builder $roleQuery): void {
            $roleQuery->whereIn('name', ['super-admin', 'market-admin']);
        });

        $actorLevel = Schema::hasColumn('users', 'organization_level')
            ? $this->organizationLevel($actor)
            : null;

        if ($actorLevel !== null) {
            $query->where(function (Builder $levelQuery) use ($actorLevel): void {
                $levelQuery
                    ->whereNull('organization_level')
                    ->orWhere('organization_level', '>=', $actorLevel);
            });
        }

        $managerIds = $this->managerChainIds($actor);
        if ($managerIds !== []) {
            $query->whereNotIn('id', $managerIds);
        }

        return $query;
    }

    public function applyObserverScope(Builder $query, ?User $actor): Builder
    {
        $query = $this->applyInternalStaffScope($query);

        if (! $actor) {
            return $query->whereRaw('1 = 0');
        }

        if ($this->isPrivilegedAssigner($actor)) {
            return $query;
        }

        return $query->where('market_id', (int) $actor->market_id);
    }

    public function isSuperior(User $possibleSuperior, User $employee): bool
    {
        if ((int) $possibleSuperior->id === (int) $employee->id) {
            return false;
        }

        if (in_array((int) $possibleSuperior->id, $this->managerChainIds($employee), true)) {
            return true;
        }

        $superiorLevel = $this->organizationLevel($possibleSuperior);
        $employeeLevel = $this->organizationLevel($employee);

        return $superiorLevel !== null
            && $employeeLevel !== null
            && $superiorLevel < $employeeLevel;
    }

    /**
     * @return list<int>
     */
    public function managerChainIds(User $user): array
    {
        $ids = [];
        $current = $user;

        for ($i = 0; $i < 20; $i++) {
            $managerId = (int) ($current->manager_user_id ?? 0);
            if ($managerId <= 0 || in_array($managerId, $ids, true)) {
                break;
            }

            $ids[] = $managerId;

            $manager = User::query()->find($managerId);
            if (! $manager) {
                break;
            }

            $current = $manager;
        }

        return $ids;
    }

    private function applyInternalStaffScope(Builder $query): Builder
    {
        return $query
            ->where(function (Builder $staffOnly): void {
                $staffOnly
                    ->whereNull('tenant_id')
                    ->orWhere('tenant_id', 0);
            })
            ->where(function (Builder $systemAgentSafe): void {
                $systemAgentSafe
                    ->whereNull('email')
                    ->orWhereRaw('LOWER(email) NOT LIKE ?', ['%@' . SystemAgentService::EMAIL_DOMAIN]);
            })
            ->whereDoesntHave('roles', function (Builder $roleQuery): void {
                $roleQuery->whereIn('name', ['merchant', 'merchant-user', 'buyer']);
            });
    }

    private function isPrivilegedAssigner(User $user): bool
    {
        if ($user->isSuperAdmin() || $user->hasRole('market-admin')) {
            return true;
        }

        $email = mb_strtolower(trim((string) ($user->email ?? '')));

        return $email !== '' && str_ends_with($email, '@' . SystemAgentService::EMAIL_DOMAIN);
    }

    private function isAdminTarget(User $target): bool
    {
        return $target->isSuperAdmin() || $target->hasRole('market-admin');
    }

    private function sameMarket(User $actor, User $target): bool
    {
        return (int) ($actor->market_id ?? 0) > 0
            && (int) $actor->market_id === (int) ($target->market_id ?? 0);
    }

    private function organizationLevel(User $user): ?int
    {
        $level = $user->organization_level ?? null;

        if (! is_numeric($level)) {
            return null;
        }

        $level = (int) $level;

        return $level >= self::MIN_ORGANIZATION_LEVEL && $level <= self::MAX_ORGANIZATION_LEVEL
            ? $level
            : null;
    }
}
