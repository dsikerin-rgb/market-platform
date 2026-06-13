<?php

namespace App\Filament\Resources\Roles\Schemas;

use App\Support\PermissionDisplayCatalog;
use App\Support\RoleCapabilityCatalog;
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
        // Загружаем permissions один раз и переиспользуем на всей странице
        $permissions = Permission::all()->sortBy('name');
        $permissionsById = [];
        $permissionsOptions = [];

        foreach ($permissions as $perm) {
            $permissionsById[$perm->id] = $perm->name;
            $permissionsOptions[$perm->id] = PermissionDisplayCatalog::label($perm->name);
        }

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
            ->disabled(fn ($record) => $record && in_array($record->name, ['super-admin', 'market-admin', 'merchant']))
            ->afterStateHydrated(function ($state, callable $set) use ($roleOptions) {
                if (is_string($state) && $state !== '' && ! array_key_exists($state, $roleOptions)) {
                    $set('name_custom', $state);
                    $set('name', '__custom');
                }
            })
            ->dehydrateStateUsing(fn ($state, $get) => $state === '__custom'
                ? trim((string) $get('name_custom'))
                : (string) $state)
            ->helperText('Системные коды лучше не менять.')
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
            ->content(function ($get) use ($permissionsById): HtmlString {
                $selected = collect($get('permissions') ?? [])
                    ->filter(fn ($value) => filled($value))
                    ->map(fn ($value) => is_numeric($value) ? (int) $value : (string) $value)
                    ->all();

                $selectedIds = array_values(array_filter($selected, 'is_int'));

                // Берём имена из предзагруженной карты вместо DB-запроса
                $selectedNames = [];
                foreach ($selectedIds as $id) {
                    if (isset($permissionsById[$id])) {
                        $selectedNames[] = $permissionsById[$id];
                    }
                }

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

        $effectiveCapabilitiesField = Forms\Components\Placeholder::make('effective_capabilities_preview')
            ->label('Фактический доступ')
            ->content(function ($get) use ($permissionsById): HtmlString {
                $selected = (string) ($get('name') ?? '');
                $slug = $selected === '__custom'
                    ? trim((string) ($get('name_custom') ?? ''))
                    : $selected;

                if ($slug === '') {
                    return new HtmlString('<span style="font-size:.8125rem; color:#6b7280;">Выберите роль, чтобы увидеть фактический доступ.</span>');
                }

                $permissionNames = RoleCapabilityCatalog::permissionNamesFromState($get('permissions') ?? [], $permissionsById);
                $summary = RoleCapabilityCatalog::summaryForRole($slug, $permissionNames);
                $limitations = RoleCapabilityCatalog::limitationsForRole($slug, $permissionNames);

                $summaryChips = array_map(
                    static fn (string $label): string => '<span style="display:inline-flex; align-items:center; border-radius:999px; background:#ecfdf5; color:#047857; border:1px solid #bbf7d0; padding:.25rem .55rem; font-size:.75rem; font-weight:600;">' . e($label) . '</span>',
                    $summary,
                );

                $limitationChips = array_map(
                    static fn (string $label): string => '<span style="display:inline-flex; align-items:center; border-radius:999px; background:#f8fafc; color:#64748b; border:1px solid #e2e8f0; padding:.25rem .55rem; font-size:.75rem;">' . e($label) . '</span>',
                    $limitations,
                );

                $limitationsHtml = $limitationChips === []
                    ? ''
                    : '<div style="display:flex; flex-wrap:wrap; gap:.375rem; margin-top:.5rem;">' . implode('', $limitationChips) . '</div>';

                return new HtmlString(
                    '<div style="font-size:.8125rem; line-height:1.5;">'
                    . '<div style="display:flex; flex-wrap:wrap; gap:.375rem;">' . implode('', $summaryChips) . '</div>'
                    . $limitationsHtml
                    . '<div style="margin-top:.5rem; color:#64748b;">Сводка учитывает кодовые правила доступа и выбранные permissions. Пользователь всё равно должен быть привязан к своему рынку, кроме super-admin.</div>'
                    . '</div>'
                );
            })
            ->columnSpan(12);

        $permissionsField = Forms\Components\CheckboxList::make('permissions')
            ->label('Доступы и разрешения')
            ->helperText('Выберите права, которые должна предоставлять роль.')
            ->columns(1)
            ->bulkToggleable()
            ->searchable()
            ->options($permissionsOptions)
            ->saveRelationshipsUsing(function ($record, $state) {
                $record->permissions()->sync($state ?? []);
            })
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
                        $effectiveCapabilitiesField,
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
