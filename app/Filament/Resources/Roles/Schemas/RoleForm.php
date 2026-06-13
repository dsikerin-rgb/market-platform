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
            ->label('Карточка роли')
            ->content(function ($get): HtmlString {
                $slug = self::selectedRoleSlug($get);

                if ($slug === '') {
                    return new HtmlString('<span style="font-size:.8125rem; color:#6b7280;">Выберите профиль роли, чтобы увидеть описание.</span>');
                }

                $label = e(RoleScenarioCatalog::labelForSlug($slug, $slug));
                $description = e(RoleScenarioCatalog::descriptionForSlug($slug) ?? 'Нестандартная роль без готового описания.');
                $topics = e(RoleScenarioCatalog::topicSummaryForSlug($slug));

                return new HtmlString(
                    '<div style="border:1px solid #dbeafe; background:linear-gradient(135deg,#eff6ff,#ffffff); border-radius:.75rem; padding:1rem; min-height:8rem;">'
                    . '<div style="font-size:.75rem; color:#2563eb; font-weight:700; text-transform:uppercase; letter-spacing:.04em;">Назначение роли</div>'
                    . '<div style="margin-top:.35rem; font-size:1rem; font-weight:700; color:#0f172a;">' . $label . '</div>'
                    . '<div style="margin-top:.4rem; font-size:.875rem; line-height:1.5; color:#475569;">' . $description . '</div>'
                    . '<div style="margin-top:.75rem; font-size:.75rem; color:#64748b;">Уведомления: ' . $topics . '</div>'
                    . '</div>'
                );
            })
            ->columnSpan(['default' => 12, 'md' => 6]);

        $permissionPresetField = Forms\Components\ToggleButtons::make('permission_preset')
            ->label('Готовые наборы прав')
            ->options(RolePermissionPresetCatalog::options())
            ->icons([
                'market_admin' => 'heroicon-o-shield-check',
                'marketplace_content' => 'heroicon-o-building-storefront',
                'staff_management' => 'heroicon-o-users',
                'finance_view' => 'heroicon-o-banknotes',
                'market_readonly' => 'heroicon-o-eye',
                'clear' => 'heroicon-o-x-mark',
            ])
            ->colors([
                'market_admin' => 'success',
                'marketplace_content' => 'info',
                'staff_management' => 'info',
                'finance_view' => 'warning',
                'market_readonly' => 'gray',
                'clear' => 'danger',
            ])
            ->columns(['default' => 1, 'md' => 3])
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
            ->helperText('Кнопка заменяет выбранные подробные права. Встроенный доступ профиля роли сохраняется.')
            ->columnSpan(12);

        $permissionPresetPreview = Forms\Components\Placeholder::make('permission_preset_preview')
            ->label('Что изменит выбранный набор')
            ->content(function ($get): HtmlString {
                $preset = (string) ($get('permission_preset') ?? '');
                $description = $preset !== ''
                    ? RolePermissionPresetCatalog::description($preset)
                    : 'Можно оставить пустым и настроить права вручную.';

                return new HtmlString(
                    '<div style="border:1px solid #e2e8f0; background:#f8fafc; border-radius:.625rem; padding:.85rem 1rem; font-size:.875rem; line-height:1.5; color:#475569;">'
                    . e($description ?? '')
                    . '</div>'
                );
            })
            ->columnSpan(12);

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
                    '<div style="border:1px solid #bbf7d0; background:linear-gradient(135deg,#f0fdf4,#ffffff); border-radius:.75rem; padding:1rem; min-height:8rem; font-size:.8125rem; line-height:1.5;">'
                    . '<div style="font-size:.75rem; color:#047857; font-weight:700; text-transform:uppercase; letter-spacing:.04em;">Что получит пользователь</div>'
                    . '<div style="display:flex; flex-wrap:wrap; gap:.375rem; margin-top:.55rem;">' . implode('', $summaryChips) . '</div>'
                    . $limitationsHtml
                    . '<div style="margin-top:.75rem; color:#64748b;">Итог учитывает профиль роли, дополнительные права и привязку пользователя к рынку. Исключение только для супер-администратора.</div>'
                    . '</div>'
                );
            })
            ->columnSpan(['default' => 12, 'md' => 6]);

        $permissionsField = Forms\Components\CheckboxList::make('permissions')
            ->label('Права доступа')
            ->helperText('Тонкая настройка для нестандартных ролей. Для типовых ролей обычно достаточно профиля и готового набора.')
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
            Section::make('Роль и фактический доступ')
                ->description('Сначала выберите, какую работу выполняет сотрудник, затем проверьте итоговый доступ.')
                ->schema([
                    Grid::make(12)->schema([
                        $profileField,
                        $customProfileField,
                        $labelField,
                    ]),
                    Grid::make(12)->schema([
                        $roleProfilePreview,
                        $effectiveCapabilitiesField,
                    ]),
                ]),

            Section::make('Быстрое заполнение прав')
                ->description('Готовые наборы помогают заполнить дополнительные права без чтения технического списка.')
                ->schema([
                    $permissionPresetField,
                    $permissionPresetPreview,
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
