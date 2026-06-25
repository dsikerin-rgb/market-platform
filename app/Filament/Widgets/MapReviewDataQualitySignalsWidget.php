<?php
# app/Filament/Widgets/MapReviewDataQualitySignalsWidget.php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Resources\TenantResource;
use App\Services\Tenants\TenantDuplicateSignalService;
use App\Support\MarketContext;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
            'hiddenPairs' => $marketId > 0 ? $this->hiddenPairsForMarket($marketId) : [],
        ];
    }

    private function selectedMarketId(): int
    {
        return (int) (app(MarketContext::class)->currentMarketId() ?? 0);
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

    /**
     * @return list<array<string, mixed>>
     */
    private function hiddenPairsForMarket(int $marketId): array
    {
        if (! Schema::hasTable('tenant_duplicate_ignores')) {
            return [];
        }

        return DB::table('tenant_duplicate_ignores as ignored')
            ->join('tenants as left_tenant', 'left_tenant.id', '=', 'ignored.tenant_left_id')
            ->join('tenants as right_tenant', 'right_tenant.id', '=', 'ignored.tenant_right_id')
            ->where('ignored.market_id', $marketId)
            ->orderByDesc('ignored.updated_at')
            ->limit(24)
            ->get([
                'ignored.id',
                'ignored.tenant_left_id',
                'ignored.tenant_right_id',
                'ignored.reason',
                'ignored.comment',
                'ignored.updated_at',
                'left_tenant.name as left_name',
                'right_tenant.name as right_name',
            ])
            ->map(function (object $row): array {
                $leftTenantId = (int) $row->tenant_left_id;
                $rightTenantId = (int) $row->tenant_right_id;

                return [
                    'id' => (int) $row->id,
                    'tenant_a' => [
                        'id' => $leftTenantId,
                        'name' => (string) $row->left_name,
                        'url' => TenantResource::getUrl('edit', ['record' => $leftTenantId]),
                    ],
                    'tenant_b' => [
                        'id' => $rightTenantId,
                        'name' => (string) $row->right_name,
                        'url' => TenantResource::getUrl('edit', ['record' => $rightTenantId]),
                    ],
                    'reason' => (string) $row->reason,
                    'reason_label' => $this->reasonLabel((string) $row->reason),
                    'comment' => (string) ($row->comment ?? ''),
                    'hidden_at' => filled($row->updated_at) ? (string) $row->updated_at : '',
                ];
            })
            ->all();
    }

    private function reasonLabel(string $reason): string
    {
        return match ($reason) {
            'different_tenants' => 'разные арендаторы',
            default => $reason !== '' ? $reason : 'проверено вручную',
        };
    }
}
