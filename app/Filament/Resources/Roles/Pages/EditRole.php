<?php

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Resources\Pages\BaseEditRecord;
use App\Support\RoleScenarioCatalog;
use Filament\Actions\Action;
use Illuminate\Contracts\View\View;

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

    public function getHeading(): \Illuminate\Contracts\Support\Htmlable|string
    {
        return '';
    }

    public function getPageClasses(): array
    {
        return [
            ...parent::getPageClasses(),
            'fi-resource-roles-edit-page',
        ];
    }

    public function getHeader(): ?View
    {
        $role = $this->record;
        $roleName = $role ? ($role->label_ru ?: $role->name) : 'Роль';
        $isSystem = $role && in_array((string) $role->name, self::SYSTEM_ROLES, true);

        $profileLabel = '';
        $profileDescription = '';
        if ($role && $role->name) {
            $profileLabel = RoleScenarioCatalog::labelForSlug((string) $role->name);
            $profileDescription = RoleScenarioCatalog::descriptionForSlug((string) $role->name);
        }

        return view('filament.pages.roles-edit-hero', [
            'roleName' => $roleName,
            'isSystem' => $isSystem,
            'profileLabel' => $profileLabel,
            'profileDescription' => $profileDescription,
            'backUrl' => RoleResource::getUrl('index'),
        ]);
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
