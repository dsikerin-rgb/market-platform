<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Task;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class TaskCalendarFilters
{
    public const PARAM_ASSIGNED = 'assigned';
    public const PARAM_OBSERVING = 'observing';
    public const PARAM_COEXECUTING = 'coexecuting';
    public const PARAM_HOLIDAYS = 'holidays';
    public const PARAM_STATUSES = 'status';
    public const PARAM_PRIORITIES = 'priority';
    public const PARAM_SEARCH = 'search';
    public const PARAM_OVERDUE = 'overdue';
    public const PARAM_DATE = 'date';

    /**
     * @return array{
     *   assigned: bool,
     *   observing: bool,
     *   coexecuting: bool,
     *   holidays: bool,
     *   statuses: list<string>,
     *   priorities: list<string>,
     *   search: string,
     *   overdue: bool,
     *   date: ?string
     * }
     */
    public static function fromRequest(): array
    {
        $statuses = array_values(array_filter((array) request()->query(self::PARAM_STATUSES, [])));
        $priorities = array_values(array_filter((array) request()->query(self::PARAM_PRIORITIES, [])));

        $statuses = array_values(array_intersect($statuses, array_keys(Task::STATUS_LABELS)));
        $priorities = array_values(array_intersect($priorities, array_keys(Task::PRIORITY_LABELS)));

        $date = request()->query(self::PARAM_DATE);
        $date = is_string($date) && $date !== '' ? $date : null;

        return [
            'assigned' => static::booleanFromQuery(self::PARAM_ASSIGNED, true),
            'observing' => static::booleanFromQuery(self::PARAM_OBSERVING, true),
            'coexecuting' => static::booleanFromQuery(self::PARAM_COEXECUTING, true),
            'holidays' => static::booleanFromQuery(self::PARAM_HOLIDAYS, true),
            'statuses' => $statuses,
            'priorities' => $priorities,
            'search' => trim((string) request()->query(self::PARAM_SEARCH, '')),
            'overdue' => static::booleanFromQuery(self::PARAM_OVERDUE, false),
            'date' => $date,
        ];
    }

    /**
     * Синхронизация календарных фильтров с табами страницы /admin/tasks?tab=...
     * Табы: all|in_progress|my|coexecuting|observing|overdue|unassigned|urgent
     */
    public static function normalizeForTab(array $filters, ?string $tab): array
    {
        $tab = is_string($tab) && $tab !== '' ? $tab : 'all';

        return match ($tab) {
            'my' => array_merge($filters, [
                'assigned' => true,
                'observing' => false,
                'coexecuting' => false,
            ]),
            'observing' => array_merge($filters, [
                'assigned' => false,
                'observing' => true,
                'coexecuting' => false,
            ]),
            'coexecuting' => array_merge($filters, [
                'assigned' => false,
                'observing' => false,
                'coexecuting' => true,
            ]),
            'overdue' => array_merge($filters, [
                'overdue' => true,
            ]),
            default => $filters,
        };
    }

    /**
     * Применяем табы, которые не выражаются текущими фильтрами (in_progress/unassigned/urgent).
     */
    public static function applyTabToTaskQuery(Builder $query, ?string $tab, User $user): Builder
    {
        $tab = is_string($tab) && $tab !== '' ? $tab : 'all';

        return match ($tab) {
            'in_progress' => method_exists($query, 'inWork') ? $query->inWork() : $query,
            'unassigned' => method_exists($query, 'unassigned') ? $query->unassigned() : $query->whereNull('assignee_id'),
            'urgent' => method_exists($query, 'urgent') ? $query->urgent() : $query,
            default => $query,
        };
    }

    public static function applyToTaskQuery(Builder $query, array $filters, User $user): Builder
    {
        $assigned = (bool) ($filters['assigned'] ?? false);
        $observing = (bool) ($filters['observing'] ?? false);
        $coexecuting = (bool) ($filters['coexecuting'] ?? false);

        // Если ничего не выбрано — пусто (как и было)
        if (! $assigned && ! $observing && ! $coexecuting) {
            return $query->whereRaw('1 = 0');
        }

        // ✅ ВАЖНОЕ изменение:
        // если все три включены (дефолт), то НЕ режем выборку по “типу участия”,
        // оставляем все задачи, которые уже даёт TaskResource::getEloquentQuery().
        $allInvolvementSelected = $assigned && $observing && $coexecuting;

        if (! $allInvolvementSelected) {
            $userId = (int) $user->id;

            $query->where(function (Builder $builder) use ($assigned, $observing, $coexecuting, $userId): void {
                if ($assigned) {
                    $builder->orWhere('assignee_id', $userId);
                }

                if ($observing) {
                    $builder->orWhere(function (Builder $inner) use ($userId): void {
                        $inner->whereHas('observers', fn (Builder $q) => $q->whereKey($userId));

                        if (Task::supportsWatchers()) {
                            $inner->orWhereHas('watchers', fn (Builder $q) => $q->whereKey($userId));
                        }
                    });
                }

                if ($coexecuting) {
                    $builder->orWhereHas('coexecutors', fn (Builder $q) => $q->whereKey($userId));
                }
            });
        }

        $statuses = $filters['statuses'] ?? [];
        if (is_array($statuses) && ! empty($statuses)) {
            $query->whereIn('status', $statuses);
        }

        $priorities = $filters['priorities'] ?? [];
        if (is_array($priorities) && ! empty($priorities)) {
            $query->whereIn('priority', $priorities);
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where('title', 'like', '%' . $search . '%');
        }

        if (! empty($filters['overdue'])) {
            $query
                ->whereNotNull('due_at')
                ->where('due_at', '<', now())
                ->whereNotIn('status', Task::CLOSED_STATUSES);
        }

        return $query;
    }

    public static function resolveMarketIdForUser(User $user): ?int
    {
        if ($user->isSuperAdmin()) {
            $panelId = Filament::getCurrentPanel()?->getId() ?? 'admin';

            $value = session("filament.{$panelId}.selected_market_id");

            if (! filled($value)) {
                $value = session('filament.admin.selected_market_id');
            }

            return filled($value) ? (int) $value : null;
        }

        return $user->market_id ? (int) $user->market_id : null;
    }

    public static function normalizeDate(?string $date): ?Carbon
    {
        if (! $date) {
            return null;
        }

        try {
            return Carbon::parse($date);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function booleanFromQuery(string $key, bool $default): bool
    {
        $value = request()->query($key);

        if ($value === null) {
            return $default;
        }

        if (is_array($value)) {
            return $default;
        }

        $value = strtolower(trim((string) $value));

        if (in_array($value, ['1', 'true', 'yes', 'y', 'on'], true)) {
            return true;
        }

        if (in_array($value, ['0', 'false', 'no', 'n', 'off'], true)) {
            return false;
        }

        return $default;
    }
}
