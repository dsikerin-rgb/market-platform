<?php

namespace App\Filament\Resources\TenantResource\Pages;

use App\Filament\Resources\TenantResource;
use App\Filament\Widgets\TenantsWorkspaceWidget;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

class ListTenants extends ListRecords
{
    protected static string $resource = TenantResource::class;

    protected static ?string $title = 'Арендаторы';

    public function getBreadcrumb(): string
    {
        return 'Арендаторы';
    }

    public function mount(): void
    {
        parent::mount();

        if (request()->boolean('with_red_debt')) {
            $currentCriticalValue = $this->tableFilters['has_critical_debt']['value'] ?? null;

            if (blank($currentCriticalValue)) {
                $this->tableFilters['has_critical_debt']['value'] = '1';
            }
        }

        if (! request()->boolean('with_debt')) {
            return;
        }

        $currentValue = $this->tableFilters['has_debt']['value'] ?? null;

        if (blank($currentValue)) {
            $this->tableFilters['has_debt']['value'] = '1';
        }
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            TenantsWorkspaceWidget::class,
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
}
