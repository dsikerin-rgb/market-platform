<?php

namespace App\Filament\Resources\Roles\Schemas;

use App\Support\PermissionDisplayCatalog;
use App\Support\RoleCapabilityCatalog;
use App\Support\RolePermissionPresetCatalog;
use App\Support\RoleScenarioCatalog;
use Filament\Forms;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use Spatie\Permission\Models\Permission;

class RoleForm
{
    public static function configure(Schema $schema): Schema
    {
        $permissions = Permission::all()->sortBy('name');
        $permissionsById = [];
        $permissionIdsByName = [];

        foreach ($permissions as $permission) {
            $permissionsById[(int) $permission->id] = (string) $permission->name;
            $permissionIdsByName[(string) $permission->name] = (int) $permission->id;
        }

        $permissionOptions = PermissionDisplayCatalog::options($permissionsById);
        $permissionDescriptions = PermissionDisplayCatalog::descriptions($permissionsById);

        $roleOptions = RoleScenarioCatalog::options() + [
            '__custom' => 'Другая роль',
        ];

        $profileField = Forms\Components\Select::make('name')
            ->label('Профиль роли')
            ->options($roleOptions)
            ->searchable()
            ->preload()
            ->required()
            ->reactive()
            ->disabled(fn ($record) => $record && in_array($record->name, ['super-admin', 'market-admin', 'merchant'], true))
            ->afterStateHydrated(function ($state, callable $set) use ($roleOptions): void {
                if (is_string($state) && $state !== '' && ! array_key_exists($state, $roleOptions)) {
                    $set('name_custom', $state);
                    $set('name', '__custom');
                }
            })
            ->dehydrateStateUsing(fn ($state, $get) => $state === '__custom'
                ? trim((string) $get('name_custom'))
                : (string) $state)
            ->helperText('Выберите готовый профиль. Для обычной работы лучше не менять системные профили.')
            ->columnSpan(['default' => 12, 'md' => 4]);

        $customProfileField = Forms\Components\TextInput::make('name_custom')
            ->label('Служебное имя роли')
            ->placeholder('accountant, hr-manager')
            ->maxLength(255)
            ->visible(fn ($get) => $get('name') === '__custom')
            ->required(fn ($get) => $get('name') === '__custom')
            ->regex('/^[a-zA-Z0-9\-_]+$/')
            ->helperText('Нужно только для новой нестандартной роли. Используйте латиницу, цифры, дефис или подчеркивание.')
            ->dehydrated(false)
            ->columnSpan(['default' => 12, 'md' => 4]);

        $labelField = Forms\Components\TextInput::make('label_ru')
            ->label('Название на русском')
            ->maxLength(255)
            ->placeholder('Например: Бухгалтер')
            ->helperText('Показывается в списках и таблицах.')
            ->columnSpan(['default' => 12, 'md' => 4]);

        $guardField = Forms\Components\Hidden::make('guard_name')
            ->default('web')
            ->dehydrated(true);

        $roleProfilePreview = Forms\Components\Placeholder::make('role_profile_preview')
            ->label('Что означает профиль')
            ->content(function ($get): HtmlString {
                $slug = self::selectedRoleSlug($get);

                if ($slug === '') {
                    return new HtmlString('<span style="font-size:.8125rem; color:#6b7280;">Выберите профиль роли, чтобы увидеть описание.</span>');
                }

                $label = e(RoleScenarioCatalog::labelForSlug($slug, $slug));
                $description = e(RoleScenarioCatalog::descriptionForSlug($slug) ?? 'Нестандартная роль без готового описания.');
                $topics = e(RoleScenarioCatalog::topicSummaryForSlug($slug));

                return new HtmlString(
                    '<div style="font-size:.8125rem; line-height:1.5; color:#0f172a;">'
                    . '<strong style="color:#0f172a;">' . $label . '</strong>'
                    . '<div style="margin-top:.25rem; color:#475569;">' . $description . '</div>'
                    . '<div style="margin-top:.25rem; color:#6b7280; font-size:.75rem;">Уведомления: ' . $topics . '</div>'
                    . '</div>'
                );
            })
            ->columnSpan(['default' => 12, 'md' => 6]);

        $permissionPresetField = Forms\Components\Select::make('permission_preset')
            ->label('Быстро заполнить права')
            ->placeholder('Выберите набор прав')
            ->options(RolePermissionPresetCatalog::options())
            ->searchable()
            ->preload()
            ->dehydrated(false)
            ->reactive()
            ->afterStateUpdated(function ($state, callable $set) use ($permissionIdsByName): void {
                if (! is_string($state) || $state === '') {
                    return;
                }

                $set('permissions', array_map(
                    static fn (int $id): string => (string) $id,
                    RolePermissionPresetCatalog::permissionIdsForPreset($state, $permissionIdsByName),
                ));
            })
            ->helperText('Заменяет список прав ниже. Встроенный доступ профиля роли при этом сохраняется.')
            ->columnSpan(['default' => 12, 'md' => 6]);

        $permissionPresetPreview = Forms\Components\Placeholder::make('permission_preset_preview')
            ->label('Подсказка по набору')
            ->content(function ($get): HtmlString {
                $preset = (string) ($get('permission_preset') ?? '');
                $description = $preset !== ''
                    ? RolePermissionPresetCatalog::description($preset)
                    : 'Можно оставить пустым и настроить права вручную.';

                return new HtmlString('<span style="font-size:.8125rem; color:#475569;">' . e($description ?? '') . '</span>');
            })
            ->columnSpan(['default' => 12, 'md' => 6]);

        $effectiveCapabilitiesField = Forms\Components\Placeholder::make('effective_capabilities_preview')
            ->label('Фактический доступ')
            ->content(function ($get) use ($permissionsById): HtmlString {
                $slug = self::selectedRoleSlug($get);

                if ($slug === '') {
                    return new HtmlString('<span style="font-size:.8125rem; color:#6b7280;">Выберите профиль роли, чтобы увидеть фактический доступ.</span>');
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
                    . '<div style="margin-top:.5rem; color:#64748b;">Это итоговая проверка: она учитывает выбранный профиль роли, дополнительные права и привязку пользователя к рынку. Исключение только для супер-администратора.</div>'
                    . '</div>'
                );
            })
            ->columnSpan(12);

        $permissionsField = Forms\Components\CheckboxList::make('permissions')
            ->label('Права доступа')
            ->helperText('Подробная настройка для нестандартных ролей. Для типовых ролей обычно достаточно выбрать профиль выше.')
            ->columns(['default' => 1, 'md' => 2])
            ->bulkToggleable()
            ->searchable()
            ->allowHtml()
            ->relationship('permissions', 'name')
            ->options($permissionOptions)
            ->descriptions($permissionDescriptions)
            ->saveRelationshipsUsing(function ($record, $state): void {
                $record->permissions()->sync($state ?? []);
            })
            ->columnSpan(12);

        return $schema->components([
            Section::make('Основные параметры')
                ->description('Профиль, название и краткое назначение роли')
                ->schema([
                    Grid::make(12)->schema([
                        $profileField,
                        $customProfileField,
                        $labelField,
                    ]),
                    Grid::make(12)->schema([
                        $roleProfilePreview,
                        $permissionPresetField,
                        $permissionPresetPreview,
                        $effectiveCapabilitiesField,
                    ]),
                ]),

            Section::make('Подробные права')
                ->description('Дополнительные разрешения сгруппированы по смыслу. Технические коды скрыты из основного интерфейса.')
                ->collapsible()
                ->collapsed()
                ->schema([
                    $permissionsField,
                ]),

            $guardField,
        ]);
    }

    private static function selectedRoleSlug(callable $get): string
    {
        $selected = (string) ($get('name') ?? '');

        return $selected === '__custom'
            ? trim((string) ($get('name_custom') ?? ''))
            : $selected;
    }
}
