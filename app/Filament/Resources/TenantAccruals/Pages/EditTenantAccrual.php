<?php
# app/Filament/Resources/TenantAccruals/Pages/EditTenantAccrual.php

namespace App\Filament\Resources\TenantAccruals\Pages;

use App\Filament\Resources\Pages\BaseEditRecord;
use App\Filament\Resources\TenantAccruals\TenantAccrualResource;
use Filament\Actions;

class EditTenantAccrual extends BaseEditRecord
{
    protected static string $resource = TenantAccrualResource::class;

    protected static ?string $title = null;

    public function getTitle(): string
    {
        return (string) static::$resource::getModelLabel();
    }

    public function getBreadcrumbs(): array
    {
        return [
            static::$resource::getUrl('index') => (string) static::$resource::getPluralModelLabel(),
            $this->getBreadcrumb(),
        ];
    }

    public function getBreadcrumb(): string
    {
        return (string) static::$resource::getModelLabel() . ' #' . (int) $this->getRecord()->getKey();
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('back')
                ->label('К списку')
                ->icon('heroicon-o-arrow-left')
                ->extraAttributes([
                    'class' => 'accrual-back-action',
                ])
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

    public function getPageClasses(): array
    {
        return [
            ...parent::getPageClasses(),
            'fi-resource-accruals-edit-page',
        ];
    }
}
