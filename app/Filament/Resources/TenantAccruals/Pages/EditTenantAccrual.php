<?php
# app/Filament/Resources/TenantAccruals/Pages/EditTenantAccrual.php

namespace App\Filament\Resources\TenantAccruals\Pages;

use App\Filament\Resources\TenantAccruals\TenantAccrualResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditTenantAccrual extends EditRecord
{
    protected static string $resource = TenantAccrualResource::class;

    protected static ?string $title = 'Начисление';

    public function getBreadcrumb(): string
    {
        return 'Начисление';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('back')
                ->label('К списку')
                ->icon('heroicon-o-arrow-left')
                ->url(static::$resource::getUrl('index')),
        ];
    }

    /**
     * Начисления создаются импортом. На карточке разрешаем только обновление
     * (например, примечаний), но не удаление.
     */
    protected function canDelete(): bool
    {
        return false;
    }
}
