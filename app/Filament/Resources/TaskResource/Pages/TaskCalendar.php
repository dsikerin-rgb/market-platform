<?php

# app/Filament/Resources/TaskResource/Pages/TaskCalendar.php

declare(strict_types=1);

namespace App\Filament\Resources\TaskResource\Pages;

use App\Filament\Resources\TaskResource;
use App\Models\MarketHoliday;
use App\Models\Task;
use App\Support\TaskCalendarFilters;
use Filament\Facades\Filament;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Arr;

class TaskCalendar extends Page
{
    protected static string $resource = TaskResource::class;

    protected static ?string $title = 'Календарь';

    protected static string $view = 'filament.resources.task-resource.pages.calendar';

    protected function getViewData(): array
    {
        $user = Filament::auth()->user();
        $filters = TaskCalendarFilters::fromRequest();

        $tasksWithoutDue = [];

        if ($user) {
            $query = TaskCalendarFilters::applyToTaskQuery(TaskResource::getEloquentQuery(), $filters, $user)
                ->whereNull('due_at')
                ->orderByDesc('created_at');

            if (! empty($filters['overdue'])) {
                $query->whereRaw('1 = 0');
            }

            $tasksWithoutDue = $query
                ->limit(50)
                ->get();
        }

        $selectedHoliday = null;
        $holidayId = request()->query('holiday_id');

        if ($holidayId && $user) {
            $selectedHoliday = MarketHoliday::query()
                ->whereKey($holidayId)
                ->when(! $user->isSuperAdmin(), fn ($query) => $query->where('market_id', $user->market_id))
                ->first();
        }

        $canEditHoliday = (bool) $user && ($user->isSuperAdmin() || $user->hasRole('market-admin'));

        return [
            'filters' => $filters,
            'statusOptions' => Task::STATUS_LABELS,
            'priorityOptions' => Task::PRIORITY_LABELS,
            'tasksWithoutDue' => $tasksWithoutDue,
            'selectedHoliday' => $selectedHoliday,
            'holidayCloseUrl' => url()->current() . (count(request()->except('holiday_id'))
                ? '?' . http_build_query(Arr::except(request()->query(), ['holiday_id']))
                : ''),
            'canEditHoliday' => $canEditHoliday,
        ];
    }
}
