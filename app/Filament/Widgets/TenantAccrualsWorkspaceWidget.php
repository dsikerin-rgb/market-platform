<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Resources\TenantAccruals\TenantAccrualResource;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class TenantAccrualsWorkspaceWidget extends Widget
{
    protected string $view = 'filament.widgets.tenant-accruals-workspace-widget';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = -100;

    public static function canView(): bool
    {
        return TenantAccrualResource::canViewAny();
    }

    protected function getViewData(): array
    {
        $baseQuery = TenantAccrualResource::getEloquentQuery();
        $oneCQuery = (clone $baseQuery)->where('source', '1c');

        return [
            'latestPeriodLabel' => $this->resolveLatestPeriodLabel($oneCQuery, $baseQuery),
            'hasData' => (clone $baseQuery)->exists(),
        ];
    }

    private function resolveLatestPeriodLabel(Builder $oneCQuery, Builder $baseQuery): ?string
    {
        $latestPeriod = (clone $oneCQuery)->max('period');

        if (! filled($latestPeriod)) {
            $latestPeriod = (clone $baseQuery)->max('period');
        }

        if (! filled($latestPeriod)) {
            return null;
        }

        try {
            return Carbon::parse((string) $latestPeriod)->format('m.Y');
        } catch (\Throwable) {
            return (string) $latestPeriod;
        }
    }
}
