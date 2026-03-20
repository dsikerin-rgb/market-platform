<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Resources\MarketHolidayResource;
use Filament\Widgets\Widget;
use Illuminate\Support\Carbon;

class MarketCalendarWorkspaceWidget extends Widget
{
    protected string $view = 'filament.widgets.market-calendar-workspace-widget';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = -100;

    public static function canView(): bool
    {
        return MarketHolidayResource::canViewAny();
    }

    protected function getViewData(): array
    {
        $currentMonth = $this->resolveCurrentMonth();

        return [
            'initialMonthLabel' => $currentMonth->translatedFormat('F Y'),
            'listUrl' => MarketHolidayResource::getUrl('index'),
            'calendarUrl' => MarketHolidayResource::getUrl('index', ['view' => 'calendar', 'month' => $currentMonth->format('Y-m')]),
            'createUrl' => MarketHolidayResource::canCreate() ? MarketHolidayResource::getUrl('create') : null,
        ];
    }

    private function resolveCurrentMonth(): Carbon
    {
        $month = (string) request()->query('month', '');

        if ($month !== '' && preg_match('/^\d{4}-\d{2}$/', $month) === 1) {
            try {
                return Carbon::createFromFormat('Y-m', $month)->startOfMonth();
            } catch (\Throwable) {
                // ignore invalid month
            }
        }

        return now()->startOfMonth();
    }
}
