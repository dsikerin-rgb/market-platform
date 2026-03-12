<?php
# app/Filament/Resources/TenantAccruals/Pages/ListTenantAccruals.php

namespace App\Filament\Resources\TenantAccruals\Pages;

use App\Filament\Resources\TenantAccruals\TenantAccrualResource;
use Filament\Resources\Pages\ListRecords;

class ListTenantAccruals extends ListRecords
{
    protected static string $resource = TenantAccrualResource::class;

    protected static ?string $title = 'Детализация начислений';

    public function getBreadcrumb(): string
    {
        return 'Детализация начислений';
    }

    protected function getHeaderActions(): array
    {
        // Создание вручную отключено: строки приходят импортом.
        return [];
    }

    public function getSubheading(): ?string
    {
        return 'Страница показывает детализированный слой начислений. Источник строки может быть 1С или исторический CSV-импорт; договоры и задолженности остаются финансовой истиной 1С.';
    }
}
