<?php
# app/Filament/Widgets/MapReviewDataQualitySignalsWidget.php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Resources\TenantResource;
use App\Services\Tenants\TenantDuplicateSignalService;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;

class MapReviewDataQualitySignalsWidget extends Widget
{
    protected string $view = 'filament.widgets.map-review-data-quality-signals-widget';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 20;

    public static function canView(): bool
    {
        $user = Filament::auth()->user();

        return (bool) $user && $user->isSuperAdmin();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $marketId = $this->selectedMarketId();
        $signals = $marketId > 0
            ? app(TenantDuplicateSignalService::class)->signalsForMarket($marketId, 8)
            : [];

        return [
            'marketId' => $marketId,
            'signals' => array_map(fn (array $signal): array => $this->withTenantUrls($signal), $signals),
        ];
    }

    private function selectedMarketId(): int
    {
        $panelId = Filament::getCurrentPanel()?->getId() ?? 'admin';

        $value = session('dashboard_market_id')
            ?? session("filament.{$panelId}.selected_market_id")
            ?? session("filament_{$panelId}_market_id")
            ?? session('filament.admin.selected_market_id')
            ?? Filament::auth()->user()?->market_id;

        return filled($value) ? (int) $value : 0;
    }

    /**
     * @param  array<string, mixed>  $signal
     * @return array<string, mixed>
     */
    private function withTenantUrls(array $signal): array
    {
        foreach (['candidate_a', 'candidate_b'] as $key) {
            $tenantId = (int) data_get($signal, $key . '.id', 0);

            data_set($signal, $key . '.url', $tenantId > 0
                ? TenantResource::getUrl('edit', ['record' => $tenantId])
                : null);
        }

        return $signal;
    }
}
