<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class StaffHierarchy
{
    public static function canAssignTaskTo(?User $actor, ?User $candidate): bool
    {
        if (! $candidate) {
            return true;
        }

        if (! $actor) {
            return false;
        }

        if ((int) ($actor->id ?? 0) > 0 && (int) $actor->id === (int) ($candidate->id ?? 0)) {
            return true;
        }

        if (self::canAssignAcrossHierarchy($actor)) {
            return true;
        }

        $actorLevel = self::organizationLevel($actor);
        $candidateLevel = self::organizationLevel($candidate);

        if ($actorLevel === null || $candidateLevel === null) {
            return true;
        }

        return $actorLevel <= $candidateLevel;
    }

    public static function limitTaskAssignableUsers(Builder $query, ?User $actor): Builder
    {
        if (! $actor) {
            return $query->whereRaw('1 = 0');
        }

        if (self::canAssignAcrossHierarchy($actor)) {
            return $query;
        }

        $actorLevel = self::organizationLevel($actor);

        if ($actorLevel === null) {
            return $query;
        }

        return $query->where(function (Builder $scope) use ($actor, $actorLevel): void {
            $scope
                ->whereKey((int) $actor->id)
                ->orWhereNull('organization_level')
                ->orWhere('organization_level', '>=', $actorLevel);
        });
    }

    /**
     * @param  list<int>  $candidateIds
     */
    public static function assertCanAssignTaskToUserIds(?User $actor, array $candidateIds, string $field): void
    {
        if (! $actor || $candidateIds === []) {
            return;
        }

        $candidateIds = array_values(array_unique(array_filter(
            array_map(static fn ($id): int => (int) $id, $candidateIds),
            static fn (int $id): bool => $id > 0,
        )));

        if ($candidateIds === []) {
            return;
        }

        $candidates = User::query()
            ->whereIn('id', $candidateIds)
            ->get(['id', 'name', 'organization_level']);

        $blockedNames = [];

        foreach ($candidates as $candidate) {
            if (! self::canAssignTaskTo($actor, $candidate)) {
                $blockedNames[] = (string) ($candidate->name ?: ('#'.$candidate->id));
            }
        }

        if ($blockedNames === []) {
            return;
        }

        throw ValidationException::withMessages([
            $field => 'Этого сотрудника нельзя назначить исполнителем или соисполнителем, потому что он выше в структуре. Добавьте его в наблюдатели: '.implode(', ', $blockedNames).'.',
        ]);
    }

    private static function canAssignAcrossHierarchy(User $actor): bool
    {
        if (method_exists($actor, 'isSuperAdmin') && $actor->isSuperAdmin()) {
            return true;
        }

        if (method_exists($actor, 'isMarketAdmin') && $actor->isMarketAdmin()) {
            return true;
        }

        return false;
    }

    private static function organizationLevel(User $user): ?int
    {
        $value = $user->getAttribute('organization_level');

        if (! is_numeric($value)) {
            return null;
        }

        $level = (int) $value;

        return $level > 0 ? $level : null;
    }
}
