<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Pages\Requests;
use App\Filament\Resources\TenantAccruals\TenantAccrualResource;
use App\Filament\Resources\TenantContractResource;
use App\Filament\Resources\TenantResource;
use App\Support\AdminCapabilities;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;

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
        $canViewFinance = AdminCapabilities::canViewFullTenantProfile(Filament::auth()->user());

        return [
            'createUrl' => TenantResource::canCreate() ? TenantResource::getUrl('create') : null,
            'contractsUrl' => $canViewFinance ? TenantContractResource::getUrl('index') : null,
            'accrualsUrl' => $canViewFinance ? TenantAccrualResource::getUrl('index') : null,
            'requestsUrl' => Requests::getUrl(),
        ];
    }
}
