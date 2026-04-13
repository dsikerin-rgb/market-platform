<?php

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Resources\Pages\BaseEditRecord;

class EditRole extends BaseEditRecord
{
    protected static string $resource = RoleResource::class;

    /**
     * Системные роли нельзя удалять/переименовывать.
     */
    private const SYSTEM_ROLES = ['super-admin', 'market-admin', 'merchant'];

    public function getBreadcrumbs(): array
    {
        return [
            RoleResource::getUrl('index') => (string) static::$resource::getPluralModelLabel(),
        ];
    }

    public function getSubheading(): string|\Illuminate\Support\HtmlString|null
    {
        return null;
    }

    public function getPageClasses(): array
    {
        return [
            ...parent::getPageClasses(),
            'fi-resource-roles-edit-page',
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        // guard всегда web
        $data['guard_name'] = 'web';

        // Чтобы системные роли нельзя было переименовать:
        // если в форме выбран "__custom" — не даём подменять на кастом.
        if (in_array((string) ($this->record->name ?? ''), self::SYSTEM_ROLES, true)) {
            $data['name'] = (string) $this->record->name;
            $data['name_custom'] = null;
        }

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // guard всегда web
        $data['guard_name'] = 'web';

        // Системные роли не переименовываем
        if (in_array((string) ($this->record->name ?? ''), self::SYSTEM_ROLES, true)) {
            $data['name'] = (string) $this->record->name;
        }

        return $data;
    }

    protected function getHeaderActions(): array
    {
        $actions = [];

        // Delete — только если роль НЕ системная
        if (class_exists(\Filament\Actions\DeleteAction::class)) {
            $actions[] = \Filament\Actions\DeleteAction::make()
                ->label('Удалить роль')
                ->visible(fn () => ! in_array((string) ($this->record->name ?? ''), self::SYSTEM_ROLES, true));
        } elseif (class_exists(\Filament\Pages\Actions\DeleteAction::class)) {
            $actions[] = \Filament\Pages\Actions\DeleteAction::make()
                ->label('Удалить роль')
                ->visible(fn () => ! in_array((string) ($this->record->name ?? ''), self::SYSTEM_ROLES, true));
        }

        return $actions;
    }
}
