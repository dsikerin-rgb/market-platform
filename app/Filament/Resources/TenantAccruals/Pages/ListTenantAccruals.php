<?php
# app/Filament/Resources/TenantAccruals/Pages/ListTenantAccruals.php

namespace App\Filament\Resources\TenantAccruals\Pages;

use App\Filament\Resources\TenantAccruals\TenantAccrualResource;
use Filament\Resources\Pages\ListRecords;

class ListTenantAccruals extends ListRecords
{
    protected static string $resource = TenantAccrualResource::class;

    protected static ?string $title = 'Начисления';

    public function getBreadcrumb(): string
    {
        return 'Начисления';
    }

    protected function getHeaderActions(): array
    {
        // Создание вручную отключено: источник — импорт из Excel/CSV.
        return [];
    }

    public function getSubheading(): ?string
    {
        return 'Данные формируются импортом из Excel/CSV. Для просмотра открой запись, для заметок используй поле “Примечания”.';
    }
}
