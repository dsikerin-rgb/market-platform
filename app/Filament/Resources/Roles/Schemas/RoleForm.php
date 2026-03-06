<?php

namespace App\Filament\Resources\Roles\Schemas;

use App\Support\PermissionDisplayCatalog;
use App\Support\RoleScenarioCatalog;
use Filament\Forms;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use Spatie\Permission\Models\Permission;

class RoleForm
{
    public static function configure(Schema $schema): Schema
    {
        $roleOptions = RoleScenarioCatalog::options() + [
            '__custom' => 'Другая (ввести вручную)',
        ];

        $nameField = Forms\Components\Select::make('name')
            ->label('Роль')
            ->options($roleOptions)
            ->searchable()
            ->preload()
            ->required()
            ->reactive()
            ->afterStateHydrated(function ($state, callable $set) use ($roleOptions) {
                if (is_string($state) && $state !== '' && ! array_key_exists($state, $roleOptions)) {
                    $set('name_custom', $state);
                    $set('name', '__custom');
                }
            })
            ->dehydrateStateUsing(fn ($state, $get) => $state === '__custom'
                ? trim((string) $get('name_custom'))
                : (string) $state)
            ->helperText('Для надёжной работы прав доступа системные коды ролей лучше не менять.');

        $customNameField = Forms\Components\TextInput::make('name_custom')
            ->label('Код роли (вручную)')
            ->placeholder('например: accountant, security, hr-manager')
            ->maxLength(255)
            ->visible(fn ($get) => $get('name') === '__custom')
            ->required(fn ($get) => $get('name') === '__custom')
            ->regex('/^[a-zA-Z0-9\-_]+$/')
            ->helperText('Только латиница, цифры, дефис и подчёркивание.')
            ->dehydrated(false);

        $guardField = Forms\Components\Hidden::make('guard_name')
            ->default('web')
            ->dehydrated(true);

        $labelField = Forms\Components\TextInput::make('label_ru')
            ->label('Название (RU)')
            ->maxLength(255)
            ->helperText('Отображается в таблицах и списках навигации.');

        $permissionsField = Forms\Components\Select::make('permissions')
            ->label('Права роли')
            ->multiple()
            ->preload()
            ->searchable()
            ->relationship('permissions', 'name')
            ->getOptionLabelFromRecordUsing(fn ($record): string => PermissionDisplayCatalog::label((string) $record->name))
            ->helperText('Добавьте права, которые должна предоставлять роль. В списке используются человекочитаемые названия.');

        $marketplacePermissionsField = Forms\Components\Placeholder::make('marketplace_permissions_preview')
            ->label('Права маркетплейса')
            ->content(function ($get): HtmlString {
                $selected = collect($get('permissions') ?? [])
                    ->filter(fn ($value) => filled($value))
                    ->map(fn ($value) => is_numeric($value) ? (int) $value : (string) $value)
                    ->all();

                $selectedIds = array_values(array_filter($selected, 'is_int'));

                $selectedNames = Permission::query()
                    ->when($selectedIds !== [], fn ($query) => $query->whereIn('id', $selectedIds))
                    ->pluck('name')
                    ->map(fn ($value): string => (string) $value)
                    ->all();

                foreach ($selected as $value) {
                    if (is_string($value) && $value !== '') {
                        $selectedNames[] = $value;
                    }
                }

                $rows = array_map(function (string $permission) use ($selectedNames): string {
                    $isSelected = in_array($permission, $selectedNames, true);
                    $state = $isSelected ? 'Подключено' : 'Не выдано';
                    $stateClass = $isSelected ? 'text-success-600' : 'text-gray-500';

                    return '<div class="flex items-start justify-between gap-3 text-sm">'
                        . '<div>'
                        . '<div class="font-medium">' . e(PermissionDisplayCatalog::label($permission)) . '</div>'
                        . '<div class="text-gray-500">' . e($permission) . '</div>'
                        . '</div>'
                        . '<div class="' . $stateClass . '">' . e($state) . '</div>'
                        . '</div>';
                }, PermissionDisplayCatalog::marketplacePermissions());

                return new HtmlString(
                    '<div class="space-y-3">'
                    . '<div class="text-sm text-gray-500">Отдельная группа прав для настройки маркетплейса и его промо-блоков.</div>'
                    . implode('', $rows)
                    . '</div>'
                );
            });

        $profileField = Forms\Components\Placeholder::make('role_profile_preview')
            ->label('Профиль роли')
            ->content(function ($get): HtmlString {
                $selected = (string) ($get('name') ?? '');
                $slug = $selected === '__custom'
                    ? trim((string) ($get('name_custom') ?? ''))
                    : $selected;

                if ($slug === '') {
                    return new HtmlString('<span class="text-sm text-gray-500">Выберите роль, чтобы увидеть описание профиля.</span>');
                }

                $label = e(RoleScenarioCatalog::labelForSlug($slug, $slug));
                $description = e(RoleScenarioCatalog::descriptionForSlug($slug) ?? 'Кастомная роль без преднастроенного профиля.');
                $topics = e(RoleScenarioCatalog::topicSummaryForSlug($slug));

                return new HtmlString(
                    '<div class="text-sm leading-6">'
                    . '<strong>' . $label . '</strong>'
                    . '<div class="text-gray-500">' . $description . '</div>'
                    . '<div class="text-gray-500">Сценарии уведомлений: ' . $topics . '</div>'
                    . '</div>'
                );
            });

        if (class_exists(\Filament\Forms\Components\Grid::class)) {
            return $schema->components([
                \Filament\Forms\Components\Grid::make(2)->schema([
                    $nameField,
                    $customNameField,
                    $labelField,
                ]),
                $profileField,
                $marketplacePermissionsField,
                $permissionsField,
                $guardField,
            ]);
        }

        return $schema->components([
            $nameField,
            $customNameField,
            $labelField,
            $profileField,
            $marketplacePermissionsField,
            $permissionsField,
            $guardField,
        ]);
    }
}
