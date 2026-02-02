<?php

# app/Filament/Widgets/TaskCalendarWidget.php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Resources\TaskResource;
use App\Models\MarketHoliday;
use App\Models\Task;
use App\Support\TaskCalendarFilters;
use Filament\Facades\Filament;
use Illuminate\Support\CarbonImmutable;
use Saade\FilamentFullCalendar\Widgets\FullCalendarWidget;

class TaskCalendarWidget extends FullCalendarWidget
{
    protected int|string|array $columnSpan = 'full';

    public function fetchEvents(array $fetchInfo): array
    {
        $user = Filament::auth()->user();

        if (! $user || $user->hasAnyRole(['merchant', 'merchant-user'])) {
            return [];
        }

        $filters = TaskCalendarFilters::fromRequest();
        $range = $this->resolveRange($fetchInfo);

        if (! $range) {
            return [];
        }

        [$rangeStart, $rangeEnd] = $range;

        $tasks = TaskCalendarFilters::applyToTaskQuery(TaskResource::getEloquentQuery(), $filters, $user)
            ->whereNotNull('due_at')
            ->whereBetween('due_at', [$rangeStart, $rangeEnd])
            ->get();

        $events = [];

        foreach ($tasks as $task) {
            $dueAt = $task->due_at;

            if (! $dueAt) {
                continue;
            }

            $events[] = [
                'id' => 'task-' . $task->id,
                'title' => $task->title,
                'start' => $dueAt->toIso8601String(),
                'end' => $dueAt->toIso8601String(),
                'allDay' => $dueAt->format('H:i:s') === '00:00:00',
                'url' => TaskResource::getUrl(
                    TaskResource::canEdit($task) ? 'edit' : 'view',
                    ['record' => $task],
                ),
                'color' => $this->statusColor($task->status),
            ];
        }

        if (! empty($filters['holidays'])) {
            $holidayQuery = MarketHoliday::query();
            $marketId = TaskCalendarFilters::resolveMarketIdForUser($user);

            if ($marketId) {
                $holidayQuery->where('market_id', $marketId);
            } else {
                $holidayQuery->whereRaw('1 = 0');
            }

            $holidayQuery
                ->whereDate('starts_at', '<=', $rangeEnd->toDateString())
                ->where(function ($query) use ($rangeStart): void {
                    $query
                        ->whereNull('ends_at')
                        ->whereDate('starts_at', '>=', $rangeStart->toDateString())
                        ->orWhereDate('ends_at', '>=', $rangeStart->toDateString());
                });

            $holidays = $holidayQuery->get();

            foreach ($holidays as $holiday) {
                $start = $holiday->starts_at?->toDateString();

                if (! $start) {
                    continue;
                }

                $end = $holiday->ends_at
                    ? $holiday->ends_at->copy()->addDay()->toDateString()
                    : $holiday->starts_at->copy()->addDay()->toDateString();

                $events[] = [
                    'id' => 'holiday-' . $holiday->id,
                    'title' => '🎉 ' . $holiday->title,
                    'start' => $start,
                    'end' => $end,
                    'allDay' => true,
                    'url' => request()->fullUrlWithQuery(['holiday_id' => $holiday->id]),
                    'color' => '#7c3aed',
                ];
            }
        }

        return $events;
    }

    protected function getOptions(): array
    {
        $initialDate = TaskCalendarFilters::normalizeDate(request()->query(TaskCalendarFilters::PARAM_DATE));

        return [
            'firstDay' => 1,
            'initialView' => 'dayGridMonth',
            'initialDate' => $initialDate?->toDateString(),
            'headerToolbar' => [
                'left' => 'prev,next today',
                'center' => 'title',
                'right' => 'dayGridMonth,timeGridWeek,timeGridDay,listWeek',
            ],
        ];
    }

    private function resolveRange(array $fetchInfo): ?array
    {
        $start = $fetchInfo['start'] ?? null;
        $end = $fetchInfo['end'] ?? null;

        if (! $start || ! $end) {
            return null;
        }

        try {
            $startDate = CarbonImmutable::parse($start)->startOfDay();
            $endDate = CarbonImmutable::parse($end)->endOfDay();
        } catch (\Throwable) {
            return null;
        }

        return [$startDate, $endDate];
    }

    private function statusColor(?string $status): string
    {
        return match ($status) {
            Task::STATUS_NEW => '#9ca3af',
            Task::STATUS_IN_PROGRESS => '#f59e0b',
            Task::STATUS_COMPLETED => '#16a34a',
            Task::STATUS_CANCELLED => '#dc2626',
            Task::STATUS_ON_HOLD => '#eab308',
            default => '#6b7280',
        };
    }
}
