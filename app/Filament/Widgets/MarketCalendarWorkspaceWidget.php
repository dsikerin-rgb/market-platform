<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Resources\MarketHolidayResource;
use App\Models\Market;
use Filament\Facades\Filament;
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
        $marketId = $this->resolveMarketId();
        $market = $marketId > 0
            ? Market::query()->select(['id', 'name'])->find($marketId)
            : null;

        $baseQuery = MarketHolidayResource::scopeUpcoming(MarketHolidayResource::getEloquentQuery());
        $currentMonth = $this->resolveCurrentMonth();
        $monthStart = $currentMonth->copy()->startOfMonth()->toDateString();
        $monthEnd = $currentMonth->copy()->endOfMonth()->toDateString();

        $totalUpcoming = (clone $baseQuery)->count();
        $thisMonth = (clone $baseQuery)
            ->whereDate('starts_at', '<=', $monthEnd)
            ->where(function ($query) use ($monthStart): void {
                $query->where(function ($inner) use ($monthStart): void {
                    $inner->whereNull('ends_at')
                        ->whereDate('starts_at', '>=', $monthStart);
                })->orWhere(function ($inner) use ($monthStart): void {
                    $inner->whereNotNull('ends_at')
                        ->whereDate('ends_at', '>=', $monthStart);
                });
            })
            ->count();

        $holidays = (clone $baseQuery)
            ->whereIn('source', ['national_holiday', 'file'])
            ->count();

        $promotions = (clone $baseQuery)
            ->whereIn('source', ['promotion', 'promo'])
            ->count();

        $nearestEventDate = (clone $baseQuery)
            ->orderBy('starts_at')
            ->value('starts_at');

        return [
            'marketName' => $market?->name,
            'monthLabel' => $currentMonth->translatedFormat('F Y'),
            'totalUpcoming' => $totalUpcoming,
            'thisMonth' => $thisMonth,
            'holidays' => $holidays,
            'promotions' => $promotions,
            'nearestEventDate' => $this->formatDate($nearestEventDate),
            'listUrl' => MarketHolidayResource::getUrl('index'),
            'calendarUrl' => MarketHolidayResource::getUrl('index', ['view' => 'calendar', 'month' => $currentMonth->format('Y-m')]),
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

    private function formatDate(mixed $value): string
    {
        if (! filled($value)) {
            return 'Нет событий';
        }

        try {
            return Carbon::parse((string) $value)->format('d.m.Y');
        } catch (\Throwable) {
            return (string) $value;
        }
    }

    private function resolveMarketId(): int
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return 0;
        }

        if (! $user->isSuperAdmin()) {
            return (int) ($user->market_id ?: 0);
        }

        $panelId = Filament::getCurrentPanel()?->getId() ?? 'admin';
        $value = session("filament_{$panelId}_market_id");

        if (! filled($value)) {
            $value = session("filament.{$panelId}.selected_market_id");
        }

        return filled($value) ? (int) $value : 0;
    }
}
