<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Pages\Requests;
use App\Filament\Resources\TenantAccruals\TenantAccrualResource;
use App\Filament\Resources\TenantContractResource;
use App\Filament\Resources\TenantResource;
use App\Models\Market;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TenantsWorkspaceWidget extends Widget
{
    protected string $view = 'filament.widgets.tenants-workspace-widget';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = -100;

    public static function canView(): bool
    {
        return TenantResource::canViewAny();
    }

    protected function getViewData(): array
    {
        $marketId = $this->resolveMarketId();
        $market = $marketId > 0
            ? Market::query()->select(['id', 'name'])->find($marketId)
            : null;

        $baseQuery = TenantResource::getEloquentQuery();

        $total = (clone $baseQuery)->count();
        $withPlaces = $this->countTenantsWithPlaces($marketId);
        $withoutPlaces = max($total - $withPlaces, 0);
        $withDebt = $this->countTenantsWithDebt($marketId);
        $withCabinet = $this->countTenantsWithCabinetUsers($marketId);
        $latestSnapshotLabel = $this->resolveLatestSnapshotLabel($marketId);

        return [
            'marketName' => $market?->name,
            'total' => $total,
            'withPlaces' => $withPlaces,
            'withoutPlaces' => $withoutPlaces,
            'withDebt' => $withDebt,
            'withCabinet' => $withCabinet,
            'latestSnapshotLabel' => $latestSnapshotLabel,
            'allUrl' => TenantResource::getUrl('index'),
            'contractsUrl' => TenantContractResource::getUrl('index'),
            'accrualsUrl' => TenantAccrualResource::getUrl('index'),
            'requestsUrl' => Requests::getUrl(),
        ];
    }

    private function countTenantsWithPlaces(int $marketId): int
    {
        if ($marketId <= 0 || ! Schema::hasTable('market_spaces')) {
            return 0;
        }

        return (int) DB::table('market_spaces')
            ->join('tenants', function ($join) use ($marketId): void {
                $join->on('tenants.id', '=', 'market_spaces.tenant_id')
                    ->where('tenants.market_id', '=', $marketId)
                    ->where('tenants.is_active', '=', true);
            })
            ->where('market_spaces.market_id', $marketId)
            ->whereNotNull('market_spaces.tenant_id')
            ->distinct()
            ->count('market_spaces.tenant_id');
    }

    private function countTenantsWithDebt(int $marketId): int
    {
        if (
            $marketId <= 0
            || ! Schema::hasTable('contract_debts')
            || ! Schema::hasColumn('contract_debts', 'tenant_id')
            || ! Schema::hasColumn('contract_debts', 'debt_amount')
        ) {
            return 0;
        }

        $latestSnapshotAt = DB::table('contract_debts')
            ->where('market_id', $marketId)
            ->max('calculated_at');

        if (! filled($latestSnapshotAt)) {
            return 0;
        }

        return (int) DB::table('contract_debts')
            ->where('market_id', $marketId)
            ->where('calculated_at', $latestSnapshotAt)
            ->where('debt_amount', '>', 0)
            ->distinct()
            ->count('tenant_id');
    }

    private function countTenantsWithCabinetUsers(int $marketId): int
    {
        if ($marketId <= 0 || ! Schema::hasTable('users')) {
            return 0;
        }

        return (int) DB::table('users')
            ->where('market_id', $marketId)
            ->whereNotNull('tenant_id')
            ->distinct()
            ->count('tenant_id');
    }

    private function resolveLatestSnapshotLabel(int $marketId): string
    {
        if ($marketId <= 0 || ! Schema::hasTable('contract_debts')) {
            return 'Нет данных';
        }

        $latestSnapshotAt = DB::table('contract_debts')
            ->where('market_id', $marketId)
            ->max('calculated_at');

        if (! filled($latestSnapshotAt)) {
            return 'Нет данных';
        }

        try {
            return \Illuminate\Support\Carbon::parse((string) $latestSnapshotAt)->format('d.m.Y H:i');
        } catch (\Throwable) {
            return (string) $latestSnapshotAt;
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

        return filled($value) ? (int) $value : 0;
    }
}
