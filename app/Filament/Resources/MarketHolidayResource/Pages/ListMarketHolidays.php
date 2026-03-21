<?php

declare(strict_types=1);

namespace App\Filament\Resources\MarketHolidayResource\Pages;

use App\Filament\Resources\MarketHolidayResource;
use App\Filament\Widgets\MarketCalendarWorkspaceWidget;
use App\Models\MarketHoliday;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;

class ListMarketHolidays extends ListRecords
{
    protected static string $resource = MarketHolidayResource::class;

    protected static ?string $title = 'События';

    protected array $queryString = [
        'viewMode' => ['as' => 'view', 'except' => 'list'],
        'month' => ['except' => ''],
    ];

    public string $viewMode = 'list';

    public string $month = '';

    public function mount(): void
    {
        parent::mount();

        if (! in_array($this->viewMode, ['list', 'calendar'], true)) {
            $this->viewMode = 'list';
        }

        if ($this->month === '' || ! preg_match('/^\d{4}-\d{2}$/', $this->month)) {
            $this->month = now()->format('Y-m');
        }
    }

    public function getTitle(): string
    {
        return $this->viewMode === 'calendar' ? 'Календарь событий' : 'События';
    }

    public function getBreadcrumbs(): array
    {
        return [];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            MarketCalendarWorkspaceWidget::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    public function getHeading(): string|Htmlable|null
    {
        return null;
    }

    public function getView(): string
    {
        return 'filament.resources.market-holiday-resource.pages.index';
    }

    protected function getViewData(): array
    {
        $data = parent::getViewData();

        if ($this->viewMode !== 'calendar') {
            return $data;
        }

        return array_merge($data, $this->getCalendarViewData());
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function getPageClasses(): array
    {
        return [
            ...parent::getPageClasses(),
            'fi-resource-market-holidays-list-page',
        ];
    }

    private function urlForView(string $mode): string
    {
        $query = request()->query();
        unset($query['page']);

        if ($mode === 'list') {
            unset($query['view']);
            unset($query['month']);
        } else {
            $query['view'] = 'calendar';
            $query['month'] = $this->month !== '' ? $this->month : now()->format('Y-m');
        }

        $base = MarketHolidayResource::getUrl('index');

        return count($query) > 0 ? ($base . '?' . http_build_query($query)) : $base;
    }

    /**
     * @return array<string, mixed>
     */
    private function getCalendarViewData(): array
    {
        $currentMonth = $this->resolveMonth($this->month);
        $monthStart = $currentMonth->copy()->startOfMonth();
        $monthEnd = $currentMonth->copy()->endOfMonth();
        $gridStart = $monthStart->copy()->startOfWeek(Carbon::MONDAY);
        $gridEnd = $monthEnd->copy()->endOfWeek(Carbon::SUNDAY);

        $events = MarketHolidayResource::scopeUpcoming(
            MarketHolidayResource::getEloquentQuery()
        )
            ->whereDate('starts_at', '<=', $monthEnd->toDateString())
            ->where(function ($q) use ($monthStart): void {
                $q->where(function ($inner) use ($monthStart): void {
                    $inner->whereNull('ends_at')
                        ->whereDate('starts_at', '>=', $monthStart->toDateString());
                })->orWhere(function ($inner) use ($monthStart): void {
                    $inner->whereNotNull('ends_at')
                        ->whereDate('ends_at', '>=', $monthStart->toDateString());
                });
            })
            ->orderBy('starts_at')
            ->orderBy('title')
            ->get();

        $eventsByDate = [];

        foreach ($events as $event) {
            $start = $event->starts_at ? Carbon::parse($event->starts_at) : null;
            if (! $start) {
                continue;
            }

            $end = $event->ends_at ? Carbon::parse($event->ends_at) : $start->copy();

            $eventCursor = $start->copy()->max($monthStart);
            $eventEnd = $end->copy()->min($monthEnd);

            while ($eventCursor->lessThanOrEqualTo($eventEnd)) {
                $key = $eventCursor->toDateString();

                $eventsByDate[$key] ??= [];
                $eventsByDate[$key][] = $event;

                $eventCursor->addDay();
            }
        }

        $weeks = [];
        $cursor = $gridStart->copy();

        while ($cursor->lessThanOrEqualTo($gridEnd)) {
            $week = [];

            for ($i = 0; $i < 7; $i++) {
                $dateKey = $cursor->toDateString();
                $dayEvents = $eventsByDate[$dateKey] ?? [];

                $week[] = [
                    'date' => $dateKey,
                    'day' => $cursor->day,
                    'is_today' => $cursor->isToday(),
                    'is_current_month' => $cursor->month === $monthStart->month,
                    'events' => array_map(fn (MarketHoliday $event): array => [
                        'id' => (int) $event->id,
                        'title' => (string) $event->title,
                        'is_holiday' => MarketHolidayResource::isNationalHoliday($event),
                        'is_promotion' => MarketHolidayResource::isPromotion($event),
                        'url' => MarketHolidayResource::canEdit($event)
                            ? MarketHolidayResource::getUrl('edit', ['record' => $event])
                            : null,
                    ], $dayEvents),
                ];

                $cursor->addDay();
            }

            $weeks[] = $week;
        }

        return [
            'monthLabel' => $monthStart->translatedFormat('F Y'),
            'weeks' => $weeks,
            'weekdays' => ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'],
            'currentMonth' => $monthStart->format('Y-m'),
            'prevMonthLabel' => $monthStart->copy()->subMonth()->translatedFormat('F Y'),
            'nextMonthLabel' => $monthStart->copy()->addMonth()->translatedFormat('F Y'),
            'prevMonthUrl' => $this->urlForCalendarMonth($monthStart->copy()->subMonth()),
            'nextMonthUrl' => $this->urlForCalendarMonth($monthStart->copy()->addMonth()),
        ];
    }

    private function resolveMonth(string $month): Carbon
    {
        try {
            return Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        } catch (\Throwable) {
            return now()->startOfMonth();
        }
    }

    private function urlForCalendarMonth(Carbon $month): string
    {
        $query = request()->query();
        $query['view'] = 'calendar';
        $query['month'] = $month->format('Y-m');
        unset($query['page']);

        return MarketHolidayResource::getUrl('index') . '?' . http_build_query($query);
    }
}
