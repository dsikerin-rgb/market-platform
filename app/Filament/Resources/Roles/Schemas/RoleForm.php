<?php

namespace App\Filament\Resources\Roles\Schemas;

use App\Support\PermissionDisplayCatalog;
use App\Support\RoleScenarioCatalog;
use Filament\Forms;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
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
            ->label('Код роли')
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
            ->helperText('Системные коды лучше не менять. Для новых прав используйте "Другая".')
            ->columnSpan(['default' => 12, 'md' => 4]);

        $customNameField = Forms\Components\TextInput::make('name_custom')
            ->label('Кастомный код')
            ->placeholder('accountant, hr-manager')
            ->maxLength(255)
            ->visible(fn ($get) => $get('name') === '__custom')
            ->required(fn ($get) => $get('name') === '__custom')
            ->regex('/^[a-zA-Z0-9\-_]+$/')
            ->helperText('Только латиница, цифры, дефис и подчёркивание.')
            ->dehydrated(false)
            ->columnSpan(['default' => 12, 'md' => 4]);

        $labelField = Forms\Components\TextInput::make('label_ru')
            ->label('Название (RU)')
            ->maxLength(255)
            ->placeholder('Например: Бухгалтер')
            ->helperText('Отображается в списках и таблицах.')
            ->columnSpan(['default' => 12, 'md' => 4]);

        $guardField = Forms\Components\Hidden::make('guard_name')
            ->default('web')
            ->dehydrated(true);

        $profileField = Forms\Components\Placeholder::make('role_profile_preview')
            ->label('Профиль роли')
            ->content(function ($get): HtmlString {
                $selected = (string) ($get('name') ?? '');
                $slug = $selected === '__custom'
                    ? trim((string) ($get('name_custom') ?? ''))
                    : $selected;

                if ($slug === '') {
                    return new HtmlString('<span style="font-size:.8125rem; color:#6b7280;">Выберите роль, чтобы увидеть описание.</span>');
                }

                $label = e(RoleScenarioCatalog::labelForSlug($slug, $slug));
                $description = e(RoleScenarioCatalog::descriptionForSlug($slug) ?? 'Кастомная роль без преднастроенного профиля.');
                $topics = e(RoleScenarioCatalog::topicSummaryForSlug($slug));

                return new HtmlString(
                    '<div style="font-size:.8125rem; line-height:1.5; color:#0f172a;">'
                    . '<strong style="color:#0f172a;">' . $label . '</strong>'
                    . '<div style="margin-top:.25rem; color:#475569;">' . $description . '</div>'
                    . '<div style="margin-top:.25rem; color:#6b7280; font-size:.6875rem;">Сценарии уведомлений: ' . $topics . '</div>'
                    . '</div>'
                );
            })
            ->columnSpan(['default' => 12, 'md' => 6]);

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
                    $state = $isSelected ? 'Включено' : 'Выключено';
                    $stateClass = $isSelected ? 'color:#059669; font-weight:600;' : 'color:#9ca3af;';

                    return '<div style="display:flex; align-items:center; justify-content:space-between; gap:.5rem; padding:.35rem 0; border-bottom:1px solid rgba(0,0,0,.05);">'
                        . '<div style="font-size:.75rem; color:#374151;">' . e(PermissionDisplayCatalog::label($permission)) . '</div>'
                        . '<div style="font-size:.6875rem; ' . $stateClass . '">' . e($state) . '</div>'
                        . '</div>';
                }, PermissionDisplayCatalog::marketplacePermissions());

                return new HtmlString(
                    '<div style="font-size:.8125rem; line-height:1.5;">'
                    . '<div style="margin-bottom:.5rem; color:#6b7280;">Настройка прав для маркетплейса и промо-блоков.</div>'
                    . implode('', $rows)
                    . '</div>'
                );
            })
            ->columnSpan(['default' => 12, 'md' => 6]);

        $permissionsField = Forms\Components\Select::make('permissions')
            ->label('Доступы и разрешения')
            ->multiple()
            ->preload()
            ->searchable()
            ->relationship('permissions', 'name')
            ->getOptionLabelFromRecordUsing(fn ($record): string => PermissionDisplayCatalog::label((string) $record->name))
            ->helperText('Выберите права, которые должна предоставлять роль. Используйте поиск для быстрого фильтра.')
            ->columnSpan(2);

        return $schema->components([
            Section::make('Основные параметры')
                ->description('Код, название и системный профиль роли')
                ->schema([
                    Grid::make(12)->schema([
                        $nameField,
                        $customNameField,
                        $labelField,
                    ]),
                    Grid::make(12)->schema([
                        $profileField,
                        $marketplacePermissionsField,
                    ]),
                ]),

            Section::make('Права доступа')
                ->description('Конкретные разрешения для этой роли в системе')
                ->schema([
                    $permissionsField,
                ]),

            $guardField,
        ]);
    }
}
