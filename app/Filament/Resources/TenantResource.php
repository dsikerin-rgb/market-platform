<?php
# app/Filament/Resources/TenantResource.php

namespace App\Filament\Resources;

use App\Filament\Resources\TenantResource\Pages;
use App\Filament\Resources\TenantResource\RelationManagers\RequestsRelationManager;
use App\Models\Tenant;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Filament\Forms;
use App\Filament\Resources\BaseResource;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema as DbSchema;
use Illuminate\Support\HtmlString;
use Throwable;

class TenantResource extends BaseResource
{
    protected static ?string $model = Tenant::class;
    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'Арендатор';
    protected static ?string $pluralModelLabel = 'Арендаторы';
    protected static ?string $navigationLabel = 'Арендаторы';

    /** @var array<string, array<int, string>> */
    private static array $tableColumnsCache = [];

    /** @var array<string, array<string, mixed>> */
    private static array $accrualSummaryCache = [];

    /**
     * Группа динамическая:
     * - super-admin видит "Рынки"
     * - market-admin и остальные сотрудники не видят "Рынки", но могут открыть через "Настройки рынка"
     */
    public static function getNavigationGroup(): ?string
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return null;
        }

        return $user->isSuperAdmin() ? 'Рынки' : 'Рынок';
    }

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-users';

    protected static function selectedMarketIdFromSession(): ?int
    {
        $panelId = Filament::getCurrentPanel()?->getId() ?? 'admin';
        $key = "filament_{$panelId}_market_id";

        $value = session($key);

        return filled($value) ? (int) $value : null;
    }

    
    public static function getGloballySearchableAttributes(): array
    {
        return [
            'name',
            'short_name',
            'inn',
            'phone',
            'email',
            'external_id',
            'one_c_uid',
        ];
    }
    public static function form(Schema $schema): Schema
    {
        $user = Filament::auth()->user();

        $tabs = Tabs::make('tenant_tabs')
            ->columnSpanFull();

        // Безопасно: если в вашей версии Filament нет этого метода — просто пропускаем.
        if (method_exists($tabs, 'persistTabInQueryString')) {
            $tabs->persistTabInQueryString();
        }

        return $schema->components([
            $tabs->tabs([
                Tab::make('Основное')
                    ->schema([
                        Section::make('Карточка арендатора')
                            ->schema([
                                Forms\Components\Select::make('market_id')
                                    ->label('Рынок')
                                    ->relationship('market', 'name')
                                    ->required()
                                    ->searchable()
                                    ->preload()
                                    ->default(function () use ($user) {
                                        if (! $user) {
                                            return null;
                                        }

                                        if ($user->isSuperAdmin()) {
                                            return static::selectedMarketIdFromSession() ?: null;
                                        }

                                        return $user->market_id;
                                    })
                                    ->disabled(fn () => (bool) $user && ! $user->isSuperAdmin())
                                    ->dehydrated(true),

                                Forms\Components\TextInput::make('name')
                                    ->label('Название арендатора')
                                    ->required()
                                    ->maxLength(255),

                                Forms\Components\TextInput::make('short_name')
                                    ->label('Краткое название / вывеска')
                                    ->maxLength(255),

                                Forms\Components\Select::make('type')
                                    ->label('Тип арендатора')
                                    ->options([
                                        'llc' => 'ООО',
                                        'sole_trader' => 'ИП',
                                        'self_employed' => 'Самозанятый',
                                        'individual' => 'Физическое лицо',
                                    ])
                                    ->nullable(),

                                Forms\Components\Select::make('status')
                                    ->label('Статус договора')
                                    ->options([
                                        'active' => 'В аренде',
                                        'paused' => 'Приостановлено',
                                        'finished' => 'Завершён договор',
                                    ])
                                    ->nullable(),

                                Forms\Components\Toggle::make('is_active')
                                    ->label('Активен')
                                    ->default(true),
                            ])
                            ->columns(2),

                        Section::make('Задолженность (ручной статус)')
                            ->schema([
                                Forms\Components\Placeholder::make('debt_status_summary')
                                    ->hiddenLabel()
                                    ->dehydrated(false)
                                    ->content(fn (?Tenant $record): HtmlString => static::renderDebtStatusSummary($record))
                                    ->columnSpanFull(),

                                Forms\Components\Select::make('debt_status')
                                    ->label('Задолженность')
                                    ->options(static::debtStatusOptions())
                                    ->placeholder('Не указано')
                                    ->nullable()
                                    ->helperText('Временный ручной статус до интеграции с 1С. Оплата — до 30 календарных дней.'),

                                Forms\Components\Textarea::make('debt_status_note')
                                    ->label('Комментарий по задолженности')
                                    ->nullable()
                                    ->rows(3),

                                Forms\Components\Placeholder::make('debt_status_updated_at')
                                    ->label('Обновлено')
                                    ->content(function (?Tenant $record): string {
                                        if (! $record?->debt_status_updated_at) {
                                            return '—';
                                        }

                                        return $record->debt_status_updated_at->format('d.m.Y H:i');
                                    }),
                            ])
                            ->columns(2),
                    ]),

                Tab::make('Торговые места')
                    ->schema([
                        Section::make('Расчеты по договорам')
                            ->description('Начислено, оплачено и долг по последнему снимку 1С.')
                            ->schema([
                                Forms\Components\Placeholder::make('payments_debt_summary')
                                    ->hiddenLabel()
                                    ->dehydrated(false)
                                    ->content(fn (?Tenant $record): HtmlString => static::renderPaymentsDebtSummary($record))
                                    ->columnSpanFull(),
                            ]),

                        Section::make()
                            ->schema([
                                Forms\Components\Placeholder::make('spaces_last_period')
                                    ->hiddenLabel()
                                    ->dehydrated(false)
                                    ->content(fn (?Tenant $record) => static::renderSpacesLastPeriod($record))
                                    ->columnSpanFull(),
                            ]),
                    ]),

                Tab::make('Договоры')
                    ->schema([
                        Section::make('Договоры')
                            ->description('Реестр договоров арендатора с привязкой к торговым местам.')
                            ->schema([
                                Forms\Components\Placeholder::make('contracts_by_spaces')
                                    ->hiddenLabel()
                                    ->dehydrated(false)
                                    ->content(fn (?Tenant $record): HtmlString => static::renderContractsBySpaces($record))
                                    ->columnSpanFull(),
                            ]),
                    ]),

                Tab::make('Контакты')
                    ->schema([
                        Section::make('Контактные данные')
                            ->schema([
                                Forms\Components\TextInput::make('phone')
                                    ->label('Телефон'),

                                Forms\Components\TextInput::make('email')
                                    ->label('Email'),

                                Forms\Components\TextInput::make('contact_person')
                                    ->label('Контактное лицо'),
                            ])
                            ->columns(2),
                    ]),

                Tab::make('Реквизиты')
                    ->schema([
                        Section::make('Реквизиты')
                            ->description('Минимальный набор для идентификации арендатора. Расширенные реквизиты добавим отдельным обновлением.')
                            ->schema([
                                Forms\Components\TextInput::make('inn')
                                    ->label('ИНН')
                                    ->maxLength(20),

                                Forms\Components\TextInput::make('ogrn')
                                    ->label('ОГРН / ОГРНИП')
                                    ->maxLength(20),
                            ])
                            ->columns(2),
                    ]),

                Tab::make('Примечания')
                    ->schema([
                        Section::make('Примечания')
                            ->schema([
                                Forms\Components\Textarea::make('notes')
                                    ->hiddenLabel()
                                    ->rows(6)
                                    ->columnSpanFull(),
                            ]),
                    ]),

                Tab::make('История аренды мест')
                    ->schema([
                        Section::make('История аренды мест')
                            ->schema([
                                Forms\Components\Placeholder::make('space_history')
                                    ->hiddenLabel()
                                    ->dehydrated(false)
                                    ->content(fn (?Tenant $record): HtmlString => static::renderSpaceHistory($record))
                                    ->columnSpanFull(),
                            ]),
                    ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $user = Filament::auth()->user();

        $toolbarActions = static::tenantToolbarActions();

        return $table
            ->columns([
                TextColumn::make('market.name')
                    ->label('Рынок')
                    ->sortable()
                    ->searchable()
                    ->visible(fn () => (bool) $user && $user->isSuperAdmin())
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('name')
                    ->label('Арендатор')
                    ->sortable()
                    ->searchable(
                        query: function (Builder $query, string $search): Builder {
                            $search = trim($search);

                            if ($search === '') {
                                return $query;
                            }

                            $variants = array_values(array_unique(array_filter([
                                $search,
                                mb_strtolower($search, 'UTF-8'),
                                mb_strtoupper($search, 'UTF-8'),
                                mb_convert_case($search, MB_CASE_TITLE, 'UTF-8'),
                            ], static fn ($value) => is_string($value) && $value !== '')));

                            return $query->where(function (Builder $inner) use ($variants): void {
                                foreach ($variants as $variant) {
                                    $pattern = "%{$variant}%";

                                    $inner
                                        ->orWhere('tenants.name', 'like', $pattern)
                                        ->orWhere('tenants.short_name', 'like', $pattern)
                                        ->orWhere('tenants.inn', 'like', $pattern)
                                        ->orWhere('tenants.phone', 'like', $pattern)
                                        ->orWhere('tenants.email', 'like', $pattern)
                                        ->orWhere('tenants.external_id', 'like', $pattern)
                                        ->orWhere('tenants.one_c_uid', 'like', $pattern);
                                }
                            });
                        },
                    )
                    ->forceSearchCaseInsensitive(false)
                    ->wrap()
                    ->description(function (Tenant $record): ?string {
                        $parts = [];

                        if (filled($record->short_name)) {
                            $parts[] = (string) $record->short_name;
                        }

                        if (filled($record->inn)) {
                            $parts[] = 'ИНН ' . (string) $record->inn;
                        }

                        if (filled($record->phone)) {
                            $parts[] = (string) $record->phone;
                        }

                        return $parts ? implode(' · ', $parts) : null;
                    }),

                TextColumn::make('type')
                    ->label('Тип')
                    ->formatStateUsing(function (?string $state): string {
                        $s = trim((string) $state);
                        if ($s === '') {
                            return '—';
                        }

                        return match ($s) {
                            'llc' => 'ООО',
                            'sole_trader' => 'ИП',
                            'self_employed' => 'Самозанятый',
                            'individual' => 'Физ. лицо',
                            default => $s,
                        };
                    })
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('accruals_last_period')
                    ->label('Последнее начисление')
                    ->formatStateUsing(function ($state): string {
                        if (! filled($state)) {
                            return '—';
                        }

                        try {
                            return Carbon::parse((string) $state)->format('Y-m');
                        } catch (\Throwable) {
                            return (string) $state;
                        }
                    })
                    ->sortable(),

                TextColumn::make('accruals_count')
                    ->label('Начислений')
                    ->numeric()
                    ->sortable()
                    ->alignCenter(),

                TextColumn::make('accruals_distinct_spaces_count')
                    ->label('Мест (в начисл.)')
                    ->numeric()
                    ->sortable()
                    ->alignCenter()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('accruals_total_with_vat_sum')
                    ->label('Сумма начислений')
                    ->money('RUB', locale: 'ru')
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Статус договора')
                    ->formatStateUsing(fn (?string $state) => match ($state) {
                        'active' => 'В аренде',
                        'paused' => 'Приостановлено',
                        'finished' => 'Завершён договор',
                        default => filled($state) ? $state : '—',
                    })
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('debt_status')
                    ->label('Задолженность')
                    ->formatStateUsing(fn (?string $state, Tenant $record) => $record->debt_status_label)
                    ->badge()
                    ->color(fn (?string $state) => static::debtStatusColor($state))
                    ->sortable(),

                IconColumn::make('is_active')
                    ->label('Активен')
                    ->boolean()
                    ->alignCenter(),

                TextColumn::make('created_at')
                    ->label('Создан')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Активен')
                    ->default(true),

                SelectFilter::make('status')
                    ->label('Статус договора')
                    ->options([
                        'active' => 'В аренде',
                        'paused' => 'Приостановлено',
                        'finished' => 'Завершён договор',
                    ]),

                SelectFilter::make('type')
                    ->label('Тип')
                    ->options([
                        'llc' => 'ООО',
                        'sole_trader' => 'ИП',
                        'self_employed' => 'Самозанятый',
                        'individual' => 'Физ. лицо',
                        'ООО' => 'ООО (legacy)',
                        'АО' => 'АО (legacy)',
                        'ИП' => 'ИП (legacy)',
                    ]),
            ])
            ->defaultSort('accruals_total_with_vat_sum', 'desc')
            ->toolbarActions($toolbarActions)
            ->recordUrl(fn (Tenant $record): string => static::getUrl('edit', ['record' => $record]));
    }

    /**
     * @return array<int, mixed>
     */
    private static function tenantToolbarActions(): array
    {
        if (! class_exists(BulkAction::class)) {
            return [];
        }

        $bulkEdit = BulkAction::make('bulk_edit')
            ->label('Массовое редактирование')
            ->icon('heroicon-o-pencil-square')
            ->color('warning')
            ->requiresConfirmation()
            ->deselectRecordsAfterCompletion()
            ->successNotificationTitle('Изменения применены к выбранным арендаторам')
            ->form([
                Forms\Components\Select::make('is_active_mode')
                    ->label('Активность')
                    ->options([
                        '' => 'Без изменений',
                        '1' => 'Сделать активными',
                        '0' => 'Сделать неактивными',
                    ])
                    ->default(''),

                Forms\Components\Select::make('type_mode')
                    ->label('Тип')
                    ->options([
                        '' => 'Без изменений',
                        'llc' => 'ООО',
                        'sole_trader' => 'ИП',
                        'self_employed' => 'Самозанятый',
                        'individual' => 'Физическое лицо',
                        '__clear' => 'Очистить тип',
                    ])
                    ->default(''),

                Forms\Components\Select::make('status_mode')
                    ->label('Статус договора')
                    ->options([
                        '' => 'Без изменений',
                        'active' => 'В аренде',
                        'paused' => 'Приостановлено',
                        'finished' => 'Завершён договор',
                        '__clear' => 'Очистить статус',
                    ])
                    ->default(''),

                Forms\Components\Textarea::make('notes_append')
                    ->label('Добавить примечание')
                    ->rows(3)
                    ->helperText('Текст будет добавлен в конец текущего примечания каждого выбранного арендатора.'),
            ])
            ->action(function (array $data, EloquentCollection $records): void {
                $updates = [];

                $isActiveMode = (string) ($data['is_active_mode'] ?? '');
                if ($isActiveMode !== '') {
                    $updates['is_active'] = $isActiveMode === '1';
                }

                $typeMode = (string) ($data['type_mode'] ?? '');
                if ($typeMode === '__clear') {
                    $updates['type'] = null;
                } elseif ($typeMode !== '') {
                    $updates['type'] = $typeMode;
                }

                $statusMode = (string) ($data['status_mode'] ?? '');
                if ($statusMode === '__clear') {
                    $updates['status'] = null;
                } elseif ($statusMode !== '') {
                    $updates['status'] = $statusMode;
                }

                $notesAppend = trim((string) ($data['notes_append'] ?? ''));

                if (($updates === []) && ($notesAppend === '')) {
                    return;
                }

                DB::transaction(function () use ($records, $updates, $notesAppend): void {
                    /** @var Tenant $tenant */
                    foreach ($records as $tenant) {
                        $payload = $updates;

                        if ($notesAppend !== '') {
                            $current = trim((string) ($tenant->notes ?? ''));
                            $payload['notes'] = $current === ''
                                ? $notesAppend
                                : ($current . PHP_EOL . $notesAppend);
                        }

                        if ($payload === []) {
                            continue;
                        }

                        $tenant->fill($payload);
                        $tenant->save();
                    }
                });
            });

        $bulkDelete = null;
        if (class_exists(DeleteBulkAction::class)) {
            $bulkDelete = DeleteBulkAction::make()
                ->label('Массовое удаление')
                ->requiresConfirmation();
        }

        $actions = [$bulkEdit];
        if ($bulkDelete) {
            $actions[] = $bulkDelete;
        }

        if (class_exists(BulkActionGroup::class)) {
            return [BulkActionGroup::make($actions)];
        }

        return $actions;
    }

    public static function getRelations(): array
    {
        return [
            RequestsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTenants::route('/'),
            'create' => Pages\CreateTenant::route('/create'),
            'edit' => Pages\EditTenant::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = Filament::auth()->user();

        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->isSuperAdmin()) {
            $selectedMarketId = static::selectedMarketIdFromSession();
            $query = filled($selectedMarketId)
                ? $query->where('market_id', (int) $selectedMarketId)
                : $query;
        } elseif ($user->market_id) {
            $query = $query->where('market_id', $user->market_id);
        } else {
            return $query->whereRaw('1 = 0');
        }

        return static::withAccrualMetrics($query);
    }

    /**
     * Global search in topbar.
     * Avoid Filament default lower() flow for pgsql because Cyrillic case conversion
     * may be inconsistent in current DB locale/collation.
     */
    protected static function applyGlobalSearchAttributeConstraints(Builder $query, string $search): void
    {
        $search = trim($search);

        if ($search === '') {
            return;
        }

        $terms = array_values(array_filter(
            preg_split('/\s+/u', $search) ?: [],
            static fn ($term) => is_string($term) && $term !== '',
        ));

        $columns = [
            $query->qualifyColumn('name'),
            $query->qualifyColumn('short_name'),
            $query->qualifyColumn('inn'),
            $query->qualifyColumn('phone'),
            $query->qualifyColumn('email'),
            $query->qualifyColumn('external_id'),
            $query->qualifyColumn('one_c_uid'),
        ];

        foreach ($terms as $term) {
            $variants = array_values(array_unique(array_filter([
                $term,
                mb_strtolower($term, 'UTF-8'),
                mb_strtoupper($term, 'UTF-8'),
                mb_convert_case($term, MB_CASE_TITLE, 'UTF-8'),
            ], static fn ($value) => is_string($value) && $value !== '')));

            $query->where(function (Builder $termQuery) use ($columns, $variants): void {
                foreach ($variants as $variant) {
                    $pattern = "%{$variant}%";

                    $termQuery->orWhere(function (Builder $variantQuery) use ($columns, $pattern): void {
                        foreach ($columns as $index => $column) {
                            if ($index === 0) {
                                $variantQuery->where($column, 'like', $pattern);
                            } else {
                                $variantQuery->orWhere($column, 'like', $pattern);
                            }
                        }
                    });
                }
            });
        }
    }

    protected static function withAccrualMetrics(Builder $query): Builder
    {
        $base = DB::table('tenant_accruals as ta')
            ->whereColumn('ta.tenant_id', 'tenants.id')
            ->whereColumn('ta.market_id', 'tenants.market_id');

        return $query->addSelect([
            'accruals_count' => (clone $base)->selectRaw('COUNT(*)'),
            'accruals_last_period' => (clone $base)->selectRaw('MAX(period)'),
            'accruals_total_with_vat_sum' => (clone $base)->selectRaw('COALESCE(SUM(total_with_vat), 0)'),
            'accruals_distinct_spaces_count' => (clone $base)->selectRaw('COUNT(DISTINCT ta.market_space_id)'),
        ]);
    }

    /**
     * Данные для блока "Сводка".
     *
     * @return array<string, mixed>
     */
    private static function accrualSummaryData(Tenant $record): array
    {
        $cacheKey = (string) $record->market_id . ':' . (string) $record->id;

        if (isset(self::$accrualSummaryCache[$cacheKey])) {
            return self::$accrualSummaryCache[$cacheKey];
        }

        $base = DB::table('tenant_accruals')
            ->where('market_id', (int) $record->market_id)
            ->where('tenant_id', (int) $record->id);

        $count = (int) (clone $base)->count();
        $lastPeriod = (clone $base)->max('period');
        $sumAll = (float) ((clone $base)->selectRaw('COALESCE(SUM(total_with_vat), 0) as s')->value('s') ?? 0);

        $sumLast = 0.0;
        $countLast = 0;
        $spacesLast = 0;
        $withoutSpace = 0;

        if ($lastPeriod) {
            $countLast = (int) (DB::table('tenant_accruals')
                ->where('market_id', (int) $record->market_id)
                ->where('tenant_id', (int) $record->id)
                ->where('period', $lastPeriod)
                ->count());

            $sumLast = (float) (DB::table('tenant_accruals')
                ->where('market_id', (int) $record->market_id)
                ->where('tenant_id', (int) $record->id)
                ->where('period', $lastPeriod)
                ->selectRaw('COALESCE(SUM(total_with_vat), 0) as s')
                ->value('s') ?? 0);

            $spacesLast = (int) (DB::table('tenant_accruals')
                ->where('market_id', (int) $record->market_id)
                ->where('tenant_id', (int) $record->id)
                ->where('period', $lastPeriod)
                ->whereNotNull('market_space_id')
                ->distinct()
                ->count('market_space_id'));

            $withoutSpace = (int) (DB::table('tenant_accruals')
                ->where('market_id', (int) $record->market_id)
                ->where('tenant_id', (int) $record->id)
                ->where('period', $lastPeriod)
                ->whereNull('market_space_id')
                ->count());
        }

        $lastPeriodLabel = '—';
        if ($lastPeriod) {
            try {
                $lastPeriodLabel = Carbon::parse((string) $lastPeriod)->format('Y-m');
            } catch (\Throwable) {
                $lastPeriodLabel = (string) $lastPeriod;
            }
        }

        $data = [
            'count' => $count,
            'last_period' => $lastPeriod,
            'last_period_label' => $lastPeriodLabel,
            'sum_all' => $sumAll,
            'sum_last' => $sumLast,
            'count_last_period' => $countLast,
            'spaces_last' => $spacesLast,
            'without_space' => $withoutSpace,
        ];

        self::$accrualSummaryCache[$cacheKey] = $data;

        return $data;
    }

    private static function renderSpacesLastPeriod(?Tenant $record): HtmlString
    {
        if (! $record) {
            return new HtmlString('<div style="font-size:13px;opacity:.85;">Список площадей появится после сохранения арендатора.</div>');
        }

        $lastPeriod = DB::table('tenant_accruals')
            ->where('market_id', (int) $record->market_id)
            ->where('tenant_id', (int) $record->id)
            ->max('period');

        if (! $lastPeriod) {
            return new HtmlString('<div style="font-size:13px;opacity:.85;">Начислений пока нет — показать арендуемые площади невозможно.</div>');
        }

        $taHasArea = static::hasColumn('tenant_accruals', 'area_sqm');

        $msHasDisplayName = static::hasColumn('market_spaces', 'display_name');
        $msHasCode = static::hasColumn('market_spaces', 'code');
        $msHasNumber = static::hasColumn('market_spaces', 'number');
        $msHasAreaSqm = static::hasColumn('market_spaces', 'area_sqm');
        $msHasArea = static::hasColumn('market_spaces', 'area');

        $placeCodeExpr = 'COALESCE('
            . ($msHasCode ? 'ms.code, ' : '')
            . ($msHasNumber ? 'ms.number, ' : '')
            . 'ta.source_place_code)';

        $placeNameExpr = $msHasDisplayName
            ? 'COALESCE(ms.display_name, ta.source_place_name)'
            : "COALESCE(ta.source_place_name, '')";

        $areaExprParts = [];
        if ($taHasArea) {
            $areaExprParts[] = 'MAX(ta.area_sqm)';
        }
        if ($msHasAreaSqm) {
            $areaExprParts[] = 'MAX(ms.area_sqm)';
        }
        if ($msHasArea) {
            $areaExprParts[] = 'MAX(ms.area)';
        }

        $hasArea = ! empty($areaExprParts);

        $selectParts = [
            'ta.market_space_id as market_space_id',
            "{$placeCodeExpr} as place_code",
            "{$placeNameExpr} as place_name",
        ];

        if ($hasArea) {
            $selectParts[] = 'COALESCE(' . implode(', ', $areaExprParts) . ', 0) as area_sqm';
        }

        $selectParts[] = 'COALESCE(SUM(ta.rent_amount), 0) as rent_sum';
        $selectParts[] = 'COALESCE(SUM(ta.total_with_vat), 0) as total_with_vat_sum';

        $rows = DB::table('tenant_accruals as ta')
            ->leftJoin('market_spaces as ms', 'ms.id', '=', 'ta.market_space_id')
            ->where('ta.market_id', (int) $record->market_id)
            ->where('ta.tenant_id', (int) $record->id)
            ->where('ta.period', $lastPeriod)
            ->selectRaw(implode(",\n", $selectParts))
            ->groupBy([
                'ta.market_space_id',
                'ta.source_place_code',
                'ta.source_place_name',
                ...($msHasCode ? ['ms.code'] : []),
                ...($msHasNumber ? ['ms.number'] : []),
                ...($msHasDisplayName ? ['ms.display_name'] : []),
            ])
            ->orderByRaw($placeCodeExpr . ' ASC')
            ->limit(500)
            ->get();

        $periodLabel = '—';
        try {
            $periodLabel = Carbon::parse((string) $lastPeriod)->format('Y-m');
        } catch (\Throwable) {
            $periodLabel = (string) $lastPeriod;
        }

        $contractsBySpace = [];
        $contractExternalToSpace = [];
        $contractsWithoutSpace = 0;
        $activeContractsWithoutSpace = 0;
        if (DbSchema::hasTable('tenant_contracts')) {
            $tcHasExternalId = static::hasColumn('tenant_contracts', 'external_id');
            $tcSelect = [
                'id',
                'market_space_id',
                'number',
                'is_active',
            ];
            if ($tcHasExternalId) {
                $tcSelect[] = 'external_id';
            }

            $contractRows = DB::table('tenant_contracts')
                ->where('market_id', (int) $record->market_id)
                ->where('tenant_id', (int) $record->id)
                ->orderByDesc('is_active')
                ->orderByDesc('starts_at')
                ->orderBy('id')
                ->get($tcSelect);

            foreach ($contractRows as $contractRow) {
                $spaceIdRaw = $contractRow->market_space_id;
                $isActive = (bool) ($contractRow->is_active ?? false);

                if ($spaceIdRaw === null) {
                    $contractsWithoutSpace++;
                    if ($isActive) {
                        $activeContractsWithoutSpace++;
                    }
                    continue;
                }

                $spaceId = (int) $spaceIdRaw;
                if (! array_key_exists($spaceId, $contractsBySpace)) {
                    $contractsBySpace[$spaceId] = [
                        'contracts' => 0,
                        'active' => 0,
                        'items' => [],
                    ];
                }

                $contractsBySpace[$spaceId]['contracts']++;
                if ($isActive) {
                    $contractsBySpace[$spaceId]['active']++;
                }

                if (count($contractsBySpace[$spaceId]['items']) < 3) {
                    $number = trim((string) ($contractRow->number ?? ''));
                    $contractsBySpace[$spaceId]['items'][] = [
                        'id' => (int) $contractRow->id,
                        'number' => $number !== '' ? $number : ('Договор #' . (int) $contractRow->id),
                        'is_active' => $isActive,
                    ];
                }

                $externalId = trim((string) ($contractRow->external_id ?? ''));
                if ($externalId !== '') {
                    $contractExternalToSpace[$externalId] = $spaceId;
                }
            }
        }

        $paymentsBySpace = [];
        $paymentsSnapshotLabel = null;
        $paymentsRowsCount = 0;
        $paymentsUnmappedContracts = 0;
        $paymentsUnmappedPaid = 0.0;
        $paymentsUnmappedDebt = 0.0;
        $paymentsTotalPaid = 0.0;
        $paymentsTotalDebt = 0.0;
        $hasPaymentsData = false;

        if (
            DbSchema::hasTable('contract_debts')
            && static::hasColumn('contract_debts', 'tenant_id')
            && static::hasColumn('contract_debts', 'contract_external_id')
        ) {
            $cdHasMarketId = static::hasColumn('contract_debts', 'market_id');
            $cdHasCalculatedAt = static::hasColumn('contract_debts', 'calculated_at');
            $cdHasCreatedAt = static::hasColumn('contract_debts', 'created_at');
            $cdHasPeriod = static::hasColumn('contract_debts', 'period');
            $cdHasPaid = static::hasColumn('contract_debts', 'paid_amount');
            $cdHasDebt = static::hasColumn('contract_debts', 'debt_amount');

            $debtsBase = DB::table('contract_debts')
                ->where('tenant_id', (int) $record->id);

            if ($cdHasMarketId) {
                $debtsBase->where('market_id', (int) $record->market_id);
            }

            if ($cdHasCalculatedAt) {
                $latest = (clone $debtsBase)->max('calculated_at');
                if ($latest) {
                    $debtsBase->where('calculated_at', $latest);
                    try {
                        $paymentsSnapshotLabel = Carbon::parse((string) $latest)->format('d.m.Y H:i');
                    } catch (\Throwable) {
                        $paymentsSnapshotLabel = (string) $latest;
                    }
                }
            } elseif ($cdHasCreatedAt) {
                $latest = (clone $debtsBase)->max('created_at');
                if ($latest) {
                    $debtsBase->where('created_at', $latest);
                    try {
                        $paymentsSnapshotLabel = Carbon::parse((string) $latest)->format('d.m.Y H:i');
                    } catch (\Throwable) {
                        $paymentsSnapshotLabel = (string) $latest;
                    }
                }
            } elseif ($cdHasPeriod) {
                $latest = (clone $debtsBase)->max('period');
                if ($latest) {
                    $debtsBase->where('period', $latest);
                    $paymentsSnapshotLabel = (string) $latest;
                }
            }

            $debtsSelect = ['contract_external_id'];
            if ($cdHasPaid) {
                $debtsSelect[] = 'paid_amount';
            }
            if ($cdHasDebt) {
                $debtsSelect[] = 'debt_amount';
            }

            $debtRows = (clone $debtsBase)->get($debtsSelect);
            $paymentsRowsCount = $debtRows->count();
            $hasPaymentsData = $paymentsRowsCount > 0;

            foreach ($debtRows as $debtRow) {
                $externalId = trim((string) ($debtRow->contract_external_id ?? ''));
                if ($externalId === '') {
                    continue;
                }

                $paid = (float) ($debtRow->paid_amount ?? 0);
                $debt = (float) ($debtRow->debt_amount ?? 0);

                $paymentsTotalPaid += $paid;
                $paymentsTotalDebt += $debt;

                if (! array_key_exists($externalId, $contractExternalToSpace)) {
                    $paymentsUnmappedContracts++;
                    $paymentsUnmappedPaid += $paid;
                    $paymentsUnmappedDebt += $debt;
                    continue;
                }

                $spaceId = (int) $contractExternalToSpace[$externalId];
                if (! array_key_exists($spaceId, $paymentsBySpace)) {
                    $paymentsBySpace[$spaceId] = [
                        'paid' => 0.0,
                        'debt' => 0.0,
                        'rows' => 0,
                    ];
                }

                $paymentsBySpace[$spaceId]['paid'] += $paid;
                $paymentsBySpace[$spaceId]['debt'] += $debt;
                $paymentsBySpace[$spaceId]['rows']++;
            }
        }

        $mapLinksBySpace = [];
        if (DbSchema::hasTable('market_space_map_shapes')) {
            $spaceIds = $rows
                ->pluck('market_space_id')
                ->filter(static fn ($id) => $id !== null)
                ->map(static fn ($id) => (int) $id)
                ->unique()
                ->values();

            if ($spaceIds->isNotEmpty()) {
                $shapeRows = DB::table('market_space_map_shapes')
                    ->where('market_id', (int) $record->market_id)
                    ->whereIn('market_space_id', $spaceIds->all())
                    ->where('is_active', true)
                    ->orderByDesc('id')
                    ->get([
                        'market_space_id',
                        'page',
                        'version',
                        'bbox_x1',
                        'bbox_y1',
                        'bbox_x2',
                        'bbox_y2',
                    ]);

                foreach ($shapeRows as $shapeRow) {
                    $spaceId = (int) ($shapeRow->market_space_id ?? 0);
                    if ($spaceId <= 0 || array_key_exists($spaceId, $mapLinksBySpace)) {
                        continue;
                    }

                    $params = [
                        'market_space_id' => $spaceId,
                        'page' => (int) ($shapeRow->page ?? 1),
                        'version' => (int) ($shapeRow->version ?? 1),
                    ];

                    if (
                        $shapeRow->bbox_x1 !== null
                        && $shapeRow->bbox_y1 !== null
                        && $shapeRow->bbox_x2 !== null
                        && $shapeRow->bbox_y2 !== null
                    ) {
                        $params['bbox_x1'] = (float) $shapeRow->bbox_x1;
                        $params['bbox_y1'] = (float) $shapeRow->bbox_y1;
                        $params['bbox_x2'] = (float) $shapeRow->bbox_x2;
                        $params['bbox_y2'] = (float) $shapeRow->bbox_y2;
                    }

                    try {
                        $mapLinksBySpace[$spaceId] = route('filament.admin.market-map', $params);
                    } catch (\Throwable) {
                        // ignore: route might be unavailable in tests/CLI contexts
                    }
                }
            }
        }

        $totalRent = 0.0;
        $totalWithVat = 0.0;
        $totalArea = 0.0;
        $contractsMappedTotal = 0;
        $contractsMappedActiveTotal = 0;

        $tableRows = '';
        foreach ($rows as $r) {
            $totalRent += (float) $r->rent_sum;
            $totalWithVat += (float) $r->total_with_vat_sum;

            $code = trim((string) ($r->place_code ?? ''));
            $name = (string) ($r->place_name ?? '');
            $spaceId = isset($r->market_space_id) ? (int) $r->market_space_id : null;

            $codeLabel = $code !== '' ? $code : '—';
            $contractStat = ($spaceId !== null && array_key_exists($spaceId, $contractsBySpace))
                ? $contractsBySpace[$spaceId]
                : ['contracts' => 0, 'active' => 0, 'items' => []];
            $contractsMappedTotal += (int) $contractStat['contracts'];
            $contractsMappedActiveTotal += (int) $contractStat['active'];

            $paymentStat = ($spaceId !== null && array_key_exists($spaceId, $paymentsBySpace))
                ? $paymentsBySpace[$spaceId]
                : null;

            $paidCell = $hasPaymentsData
                ? e(static::formatRub((float) ($paymentStat['paid'] ?? 0.0)))
                : '—';
            $debtCell = $hasPaymentsData
                ? e(static::formatRub((float) ($paymentStat['debt'] ?? 0.0)))
                : '—';

            $contractCell = '—';
            if ((int) $contractStat['contracts'] > 0) {
                $links = [];
                foreach ($contractStat['items'] as $contractItem) {
                    $linkLabel = e((string) $contractItem['number']);
                    if (($contractItem['is_active'] ?? false) === true) {
                        $linkLabel .= ' <span class="tenant-spaces__active-dot" title="Активный договор">●</span>';
                    }
                    $links[] = '<a href="?tab=dogovory::data::tab#tenant-contract-' . (int) $contractItem['id'] . '" class="tenant-spaces__contract-link">'
                        . $linkLabel
                        . '</a>';
                }

                $contractCell = implode(', ', $links);
                if ((int) $contractStat['contracts'] > count($contractStat['items'])) {
                    $left = (int) $contractStat['contracts'] - count($contractStat['items']);
                    $contractCell .= ' <span class="tenant-spaces__more-contracts">+ ещё ' . e((string) $left) . '</span>';
                }
            }

            $mapCell = '<span class="tenant-spaces__map-na">—</span>';
            if ($spaceId !== null && array_key_exists($spaceId, $mapLinksBySpace)) {
                $mapCell = '<a href="' . e((string) $mapLinksBySpace[$spaceId]) . '" target="_blank" rel="noopener" class="tenant-spaces__map-btn">Показать на карте</a>';
            }

            $areaCell = '';
            if ($hasArea) {
                $area = (float) ($r->area_sqm ?? 0);
                $totalArea += max(0, $area);
                $areaCell = '<td class="tenant-spaces__num">' . e(static::formatArea($area)) . '</td>';
            }

            $tableRows .= '
                <tr>
                    <td class="tenant-spaces__code">' . e($codeLabel) . '</td>
                    <td class="tenant-spaces__name">' . e($name) . '</td>
                    ' . $areaCell . '
                    <td class="tenant-spaces__num">' . e(static::formatRub((float) $r->rent_sum)) . '</td>
                    <td class="tenant-spaces__num">' . e(static::formatRub((float) $r->total_with_vat_sum)) . '</td>
                    <td class="tenant-spaces__num">' . $paidCell . '</td>
                    <td class="tenant-spaces__num">' . $debtCell . '</td>
                    <td class="tenant-spaces__contracts">' . $contractCell . '</td>
                    <td class="tenant-spaces__map">' . $mapCell . '</td>
                </tr>
            ';
        }

        $areaHeader = $hasArea ? '<th class="tenant-spaces__num">Площадь</th>' : '';
        $colspan = $hasArea ? 9 : 8;

        $summaryCards = [
            ['label' => 'Месяц начислений', 'value' => $periodLabel],
            ['label' => 'Торговых мест', 'value' => (string) $rows->count()],
        ];

        if ($hasArea) {
            $summaryCards[] = ['label' => 'Площадь', 'value' => static::formatArea($totalArea)];
        }

        $summaryCards[] = ['label' => 'Итого аренда', 'value' => static::formatRub($totalRent)];
        $summaryCards[] = ['label' => 'Итого с НДС', 'value' => static::formatRub($totalWithVat)];
        if ($hasPaymentsData) {
            $summaryCards[] = ['label' => 'Оплачено (снимок)', 'value' => static::formatRub($paymentsTotalPaid)];
            $summaryCards[] = ['label' => 'Долг (снимок)', 'value' => static::formatRub($paymentsTotalDebt)];
        }
        $summaryCards[] = ['label' => 'Договоров с привязкой', 'value' => (string) $contractsMappedTotal];
        $summaryCards[] = ['label' => 'Активных договоров', 'value' => (string) $contractsMappedActiveTotal];

        $summaryHtml = '';
        foreach ($summaryCards as $card) {
            $summaryHtml .= '<div class="tenant-spaces__summary-card">'
                . '<div class="tenant-spaces__summary-label">' . e((string) $card['label']) . '</div>'
                . '<div class="tenant-spaces__summary-value">' . e((string) $card['value']) . '</div>'
                . '</div>';
        }

        $warn = '';
        if ($contractsWithoutSpace > 0) {
            $warn = '<div class="tenant-spaces__warn">Есть договоры без привязки к торговому месту: <strong>'
                . e((string) $contractsWithoutSpace)
                . '</strong> (активных: <strong>'
                . e((string) $activeContractsWithoutSpace)
                . '</strong>). Их нужно привязать к месту.</div>';
        }

        if ($hasPaymentsData && $paymentsUnmappedContracts > 0) {
            $warn .= '<div class="tenant-spaces__warn">Часть оплат/долгов не разложена по местам: договоров без привязки <strong>'
                . e((string) $paymentsUnmappedContracts)
                . '</strong>, оплачено <strong>'
                . e(static::formatRub($paymentsUnmappedPaid))
                . '</strong>, долг <strong>'
                . e(static::formatRub($paymentsUnmappedDebt))
                . '</strong>.</div>';
        }

        $style = '
<style>
.tenant-spaces{display:flex;flex-direction:column;gap:12px}
.tenant-spaces__summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px}
.tenant-spaces__summary-card{border:1px solid rgba(0,0,0,.10);border-radius:12px;padding:10px 12px;background:rgba(0,0,0,.02)}
.dark .tenant-spaces__summary-card{border-color:rgba(255,255,255,.12);background:rgba(255,255,255,.03)}
.tenant-spaces__summary-label{font-size:12px;opacity:.72;line-height:1.2}
.tenant-spaces__summary-value{margin-top:4px;font-size:20px;font-weight:700;line-height:1.2}
.tenant-spaces__snapshot{font-size:12px;opacity:.75}
.tenant-spaces__warn{padding:10px 12px;border-radius:10px;border:1px solid rgba(245,158,11,.35);background:rgba(245,158,11,.10);font-size:13px;line-height:1.4}
.dark .tenant-spaces__warn{border-color:rgba(245,158,11,.45);background:rgba(245,158,11,.14)}

.tenant-spaces__table-wrap{overflow-x:auto;border-radius:14px;border:1px solid rgba(0,0,0,.10)}
.dark .tenant-spaces__table-wrap{border-color:rgba(255,255,255,.12)}

.tenant-spaces table{width:100%;border-collapse:separate;border-spacing:0;font-size:13px}
.tenant-spaces thead th{padding:10px 12px;font-weight:700;white-space:nowrap;text-align:left;border-bottom:1px solid rgba(0,0,0,.08);background:rgba(0,0,0,.03)}
.dark .tenant-spaces thead th{border-bottom-color:rgba(255,255,255,.10);background:rgba(255,255,255,.04)}

.tenant-spaces tbody td{padding:10px 12px;vertical-align:top;border-top:1px solid rgba(0,0,0,.08)}
.dark .tenant-spaces tbody td{border-top-color:rgba(255,255,255,.10)}
.tenant-spaces tbody tr:first-child td{border-top:none}

.tenant-spaces__num{text-align:right;white-space:nowrap}
.tenant-spaces__code{font-weight:700;white-space:nowrap}
.tenant-spaces__name{min-width:240px}
.tenant-spaces__contracts{min-width:260px}
.tenant-spaces__map{white-space:nowrap}
.tenant-spaces__map-btn{display:inline-flex;align-items:center;padding:4px 10px;border-radius:999px;border:1px solid rgba(37,99,235,.35);background:rgba(37,99,235,.10);font-size:12px;font-weight:700;text-decoration:none;color:inherit}
.tenant-spaces__map-btn:hover{background:rgba(37,99,235,.16)}
.tenant-spaces__map-na{opacity:.65}
.tenant-spaces__contract-link{color:inherit;text-decoration:underline;text-underline-offset:2px}
.tenant-spaces__more-contracts{opacity:.75;font-size:12px;white-space:nowrap}
.tenant-spaces__active-dot{color:rgba(16,185,129,.85);font-size:11px;vertical-align:middle}

</style>';

        $html = $style . '
<div class="tenant-spaces">
    <div class="tenant-spaces__summary">' . $summaryHtml . '</div>
    ' . ($paymentsSnapshotLabel ? '<div class="tenant-spaces__snapshot">Снимок оплат 1С: <strong>' . e($paymentsSnapshotLabel) . '</strong></div>' : '') . '
    ' . $warn . '

    <div class="tenant-spaces__table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Место</th>
                    <th>Название</th>
                    ' . $areaHeader . '
                    <th class="tenant-spaces__num">Аренда</th>
                    <th class="tenant-spaces__num">Итого с НДС</th>
                    <th class="tenant-spaces__num">Оплачено</th>
                    <th class="tenant-spaces__num">Долг</th>
                    <th>Договор</th>
                    <th>Карта</th>
                </tr>
            </thead>
            <tbody>
                ' . ($tableRows !== '' ? $tableRows : '<tr><td colspan="' . (int) $colspan . '" style="padding:12px;opacity:.85;">Нет строк для отображения.</td></tr>') . '
            </tbody>
        </table>
    </div>
</div>';

        return new HtmlString($html);
    }

    private static function renderContractsBySpaces(?Tenant $record): HtmlString
    {
        if (! $record) {
            return new HtmlString('<div style="font-size:13px;opacity:.85;">Список договоров появится после сохранения арендатора.</div>');
        }

        if (! DbSchema::hasTable('tenant_contracts')) {
            return new HtmlString('<div style="font-size:13px;opacity:.85;">Таблица договоров ещё не создана.</div>');
        }

        $msHasDisplayName = static::hasColumn('market_spaces', 'display_name');
        $msHasNumber = static::hasColumn('market_spaces', 'number');
        $msHasCode = static::hasColumn('market_spaces', 'code');

        $select = [
            'tc.id',
            'tc.market_space_id',
            'tc.number',
            'tc.status',
            'tc.starts_at',
            'tc.ends_at',
            'tc.monthly_rent',
            'tc.currency',
            'tc.is_active',
        ];

        if ($msHasDisplayName) {
            $select[] = 'ms.display_name as space_display_name';
        }
        if ($msHasNumber) {
            $select[] = 'ms.number as space_number';
        }
        if ($msHasCode) {
            $select[] = 'ms.code as space_code';
        }

        $query = DB::table('tenant_contracts as tc')
            ->leftJoin('market_spaces as ms', 'ms.id', '=', 'tc.market_space_id')
            ->where('tc.market_id', (int) $record->market_id)
            ->where('tc.tenant_id', (int) $record->id);

        if ($msHasNumber) {
            $query->orderBy('ms.number');
        } elseif ($msHasCode) {
            $query->orderBy('ms.code');
        } else {
            $query->orderBy('tc.market_space_id');
        }

        $rows = $query
            ->orderByDesc('tc.starts_at')
            ->orderBy('tc.id')
            ->limit(500)
            ->get($select);

        if ($rows->isEmpty()) {
            return new HtmlString('<div style="font-size:13px;opacity:.85;">По арендатору пока нет договоров.</div>');
        }

        $contractsTotal = $rows->count();
        $activeTotal = $rows->filter(static fn ($row): bool => (bool) ($row->is_active ?? false))->count();
        $withoutSpaceTotal = $rows->whereNull('market_space_id')->count();
        $canManageContracts = static::canManageTenantContracts($record);

        $tableRows = '';
        foreach ($rows as $row) {
            $displayName = trim((string) ($row->space_display_name ?? ''));
            $number = trim((string) ($row->space_number ?? ''));
            $code = trim((string) ($row->space_code ?? ''));

            $spaceLabel = $displayName !== '' ? $displayName : ($number !== '' ? $number : ($code !== '' ? $code : 'Не привязано'));
            $spaceCell = '<span class="tenant-contracts__warn-badge">Не привязано</span>';
            if ($row->market_space_id) {
                $spaceEditUrl = null;
                try {
                    $spaceEditUrl = route('filament.admin.resources.market-spaces.edit', ['record' => (int) $row->market_space_id]);
                } catch (\Throwable) {
                    $spaceEditUrl = null;
                }

                $spaceCell = $spaceEditUrl
                    ? '<a href="' . e($spaceEditUrl) . '" class="tenant-contracts__space-link">' . e($spaceLabel) . '</a>'
                    : e($spaceLabel);
            }

            $startsAt = null;
            if (filled($row->starts_at)) {
                try {
                    $startsAt = Carbon::parse((string) $row->starts_at)->format('d.m.Y');
                } catch (\Throwable) {
                    $startsAt = (string) $row->starts_at;
                }
            }

            $endsAt = null;
            if (filled($row->ends_at)) {
                try {
                    $endsAt = Carbon::parse((string) $row->ends_at)->format('d.m.Y');
                } catch (\Throwable) {
                    $endsAt = (string) $row->ends_at;
                }
            }

            $periodLabel = ($startsAt ?? '—') . ' - ' . ($endsAt ?? 'без срока');

            $rentValue = filled($row->monthly_rent)
                ? static::formatRub((float) $row->monthly_rent)
                : '—';
            $currency = trim((string) ($row->currency ?? ''));
            $rentCell = $rentValue . ($currency !== '' ? ' ' . e($currency) : '');

            $activeBadge = (bool) $row->is_active
                ? '<span class="tenant-contracts__ok-badge">Активен</span>'
                : '<span class="tenant-contracts__off-badge">Неактивен</span>';

            $deleteCell = '—';
            if ($canManageContracts) {
                $deleteUrl = route('filament.admin.tenants.contracts.delete', [
                    'tenant' => (int) $record->id,
                    'contract' => (int) $row->id,
                ]);

                $deleteCell = '<button type="submit"'
                    . ' class="tenant-contracts__delete-btn"'
                    . ' formmethod="POST"'
                    . ' formaction="' . e($deleteUrl) . '"'
                    . ' formnovalidate'
                    . ' onclick="return confirm(\'Удалить договор ' . e((string) ($row->number ?? '#' . (int) $row->id)) . '?\');"'
                    . '>Удалить</button>';
            }

            $tableRows .= '
                <tr id="tenant-contract-' . (int) $row->id . '">
                    <td>' . $spaceCell . '</td>
                    <td class="tenant-contracts__number">' . e((string) ($row->number ?? '—')) . '</td>
                    <td>' . e($periodLabel) . '</td>
                    <td>' . static::renderContractStatusBadge((string) ($row->status ?? '')) . '</td>
                    <td class="tenant-contracts__num">' . $rentCell . '</td>
                    <td>' . $activeBadge . '</td>
                    <td>' . $deleteCell . '</td>
                </tr>
            ';
        }

        $warn = $withoutSpaceTotal > 0
            ? '<div class="tenant-contracts__warn">Найдены договоры без торгового места: <strong>' . e((string) $withoutSpaceTotal) . '</strong>. Их нужно привязать, иначе данные по месту будут неполными.</div>'
            : '';

        $style = '
<style>
.tenant-contracts{display:flex;flex-direction:column;gap:12px}
.tenant-contracts__meta{display:flex;flex-wrap:wrap;gap:10px;align-items:center;font-size:13px;line-height:1.35;opacity:.92}
.tenant-contracts__dot{opacity:.55}
.tenant-contracts__warn{padding:10px 12px;border-radius:10px;border:1px solid rgba(245,158,11,.35);background:rgba(245,158,11,.10);font-size:13px;line-height:1.4}
.dark .tenant-contracts__warn{border-color:rgba(245,158,11,.45);background:rgba(245,158,11,.14)}

.tenant-contracts__table-wrap{overflow-x:auto;border-radius:14px;border:1px solid rgba(0,0,0,.10)}
.dark .tenant-contracts__table-wrap{border-color:rgba(255,255,255,.12)}
.tenant-contracts table{width:100%;border-collapse:separate;border-spacing:0;font-size:13px}
.tenant-contracts thead th{padding:10px 12px;font-weight:700;white-space:nowrap;text-align:left;border-bottom:1px solid rgba(0,0,0,.08);background:rgba(0,0,0,.03)}
.dark .tenant-contracts thead th{border-bottom-color:rgba(255,255,255,.10);background:rgba(255,255,255,.04)}
.tenant-contracts tbody td{padding:10px 12px;vertical-align:top;border-top:1px solid rgba(0,0,0,.08)}
.dark .tenant-contracts tbody td{border-top-color:rgba(255,255,255,.10)}
.tenant-contracts tbody tr:first-child td{border-top:none}
.tenant-contracts tbody tr:target td{background:rgba(37,99,235,.10)}
.tenant-contracts__num{text-align:right;white-space:nowrap}
.tenant-contracts__number{font-weight:700;white-space:nowrap}
.tenant-contracts__space-link{color:inherit;text-decoration:underline;text-underline-offset:2px}

.tenant-contracts__warn-badge{display:inline-flex;align-items:center;padding:3px 8px;border-radius:999px;border:1px solid rgba(245,158,11,.45);background:rgba(245,158,11,.10);font-size:12px;font-weight:700}
.tenant-contracts__ok-badge{display:inline-flex;align-items:center;padding:3px 8px;border-radius:999px;border:1px solid rgba(16,185,129,.35);background:rgba(16,185,129,.10);font-size:12px;font-weight:700}
.tenant-contracts__off-badge{display:inline-flex;align-items:center;padding:3px 8px;border-radius:999px;border:1px solid rgba(0,0,0,.16);background:rgba(0,0,0,.04);font-size:12px;font-weight:700}
.tenant-contracts__status-badge{display:inline-flex;align-items:center;padding:3px 8px;border-radius:999px;border:1px solid rgba(0,0,0,.16);background:rgba(0,0,0,.04);font-size:12px;font-weight:700}
.tenant-contracts__status-badge--success{border-color:rgba(16,185,129,.35);background:rgba(16,185,129,.10)}
.tenant-contracts__status-badge--warning{border-color:rgba(245,158,11,.45);background:rgba(245,158,11,.12)}
.tenant-contracts__delete-btn{display:inline-flex;align-items:center;padding:4px 10px;border-radius:999px;border:1px solid rgba(220,38,38,.35);background:rgba(220,38,38,.10);font-size:12px;font-weight:700;cursor:pointer}
.tenant-contracts__delete-btn:hover{background:rgba(220,38,38,.16)}
</style>';

        $meta = [
            '<span>Всего договоров: <strong>' . e((string) $contractsTotal) . '</strong></span>',
            '<span class="tenant-contracts__dot">•</span>',
            '<span>Активных: <strong>' . e((string) $activeTotal) . '</strong></span>',
            '<span class="tenant-contracts__dot">•</span>',
            '<span>Без привязки к месту: <strong>' . e((string) $withoutSpaceTotal) . '</strong></span>',
        ];

        $html = $style . '
<div class="tenant-contracts">
    <div class="tenant-contracts__meta">' . implode('', $meta) . '</div>
    ' . $warn . '
    <div class="tenant-contracts__table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Торговое место</th>
                    <th>Номер договора</th>
                    <th>Срок действия</th>
                    <th>Статус договора</th>
                    <th class="tenant-contracts__num">Аренда в месяц</th>
                    <th>Активность</th>
                    <th>Действия</th>
                </tr>
            </thead>
            <tbody>
                ' . $tableRows . '
            </tbody>
        </table>
    </div>
</div>';

        return new HtmlString($html);
    }

    private static function renderContractStatusBadge(?string $status): string
    {
        $s = trim((string) $status);

        if ($s === '') {
            return '<span class="tenant-contracts__status-badge">—</span>';
        }

        $label = match ($s) {
            'draft' => 'Черновик',
            'active' => 'Активен',
            'paused' => 'Приостановлен',
            'terminated' => 'Расторгнут',
            'archived' => 'Архив',
            default => $s,
        };

        $class = match ($s) {
            'active' => 'tenant-contracts__status-badge tenant-contracts__status-badge--success',
            'paused' => 'tenant-contracts__status-badge tenant-contracts__status-badge--warning',
            'terminated', 'archived' => 'tenant-contracts__status-badge',
            default => 'tenant-contracts__status-badge',
        };

        return '<span class="' . e($class) . '">' . e($label) . '</span>';
    }

    private static function renderSpaceHistory(?Tenant $record): HtmlString
    {
        if (! $record) {
            return new HtmlString('<div style="font-size:13px;opacity:.85;">История появится после сохранения арендатора.</div>');
        }

        if (! DbSchema::hasTable('market_space_tenant_histories')) {
            return new HtmlString('<div style="font-size:13px;opacity:.85;">Таблица истории аренды мест ещё не создана — выполните миграции.</div>');
        }

        $rows = DB::table('market_space_tenant_histories as h')
            ->leftJoin('market_spaces as ms', 'ms.id', '=', 'h.market_space_id')
            ->leftJoin('users as u', 'u.id', '=', 'h.changed_by_user_id')
            ->where(function ($q) use ($record) {
                $q->where('h.new_tenant_id', (int) $record->id)
                    ->orWhere('h.old_tenant_id', (int) $record->id);
            })
            ->where('ms.market_id', (int) $record->market_id)
            ->orderByDesc('h.changed_at')
            ->limit(300)
            ->get([
                'h.changed_at',
                'h.old_tenant_id',
                'h.new_tenant_id',
                'ms.display_name',
                'ms.number',
                'ms.code',
                'u.name as user_name',
            ]);

        $items = $rows->map(function ($row) use ($record): array {
            $displayName = trim((string) ($row->display_name ?? ''));
            $number = trim((string) ($row->number ?? ''));
            $code = trim((string) ($row->code ?? ''));

            $spaceLabel = $displayName !== '' ? $displayName : ($number !== '' ? $number : ($code !== '' ? $code : '—'));

            $event = ((int) $row->new_tenant_id === (int) $record->id) ? 'Привязан' : 'Отвязан';

            return [
                'changed_at' => $row->changed_at ? (string) \Carbon\Carbon::parse($row->changed_at)->format('d.m.Y H:i') : '—',
                'space_label' => $spaceLabel,
                'event' => $event,
                'user_name' => $row->user_name ? (string) $row->user_name : '—',
            ];
        })->all();

        return new HtmlString(view('filament.tenants.space-history', [
            'items' => $items,
        ])->render());
    }

    /**
     * @return array<string, string>
     */
    private static function debtStatusOptions(): array
    {
        return Tenant::DEBT_STATUS_LABELS;
    }

    private static function debtStatusColor(?string $state): string
    {
        return match ($state) {
            'green' => 'success',
            'orange' => 'warning',
            'red' => 'danger',
            default => 'gray',
        };
    }

    private static function debtStatusHex(?string $state): string
    {
        return match ($state) {
            'green' => '#16a34a',
            'orange' => '#f59e0b',
            'red' => '#dc2626',
            default => '#6b7280',
        };
    }

    private static function renderPaymentsDebtSummary(?Tenant $record): HtmlString
    {
        if (! $record) {
            return new HtmlString('<div style="font-size:13px;opacity:.85;">Данные появятся после сохранения арендатора.</div>');
        }

        if (! DbSchema::hasTable('contract_debts') || ! static::hasColumn('contract_debts', 'tenant_id')) {
            return new HtmlString('<div style="font-size:13px;opacity:.85;">Нет таблицы contract_debts — данные об оплатах недоступны.</div>');
        }

        $hasMarketId = static::hasColumn('contract_debts', 'market_id');
        $hasCalculatedAt = static::hasColumn('contract_debts', 'calculated_at');
        $hasCreatedAt = static::hasColumn('contract_debts', 'created_at');
        $hasPeriod = static::hasColumn('contract_debts', 'period');

        $base = DB::table('contract_debts')
            ->where('tenant_id', (int) $record->id);

        if ($hasMarketId) {
            $base->where('market_id', (int) $record->market_id);
        }

        $snapshotLabel = null;
        if ($hasCalculatedAt) {
            $latest = (clone $base)->max('calculated_at');
            if ($latest) {
                $base->where('calculated_at', $latest);
                try {
                    $snapshotLabel = Carbon::parse((string) $latest)->format('d.m.Y H:i');
                } catch (\Throwable) {
                    $snapshotLabel = (string) $latest;
                }
            }
        } elseif ($hasCreatedAt) {
            $latest = (clone $base)->max('created_at');
            if ($latest) {
                $base->where('created_at', $latest);
                try {
                    $snapshotLabel = Carbon::parse((string) $latest)->format('d.m.Y H:i');
                } catch (\Throwable) {
                    $snapshotLabel = (string) $latest;
                }
            }
        } elseif ($hasPeriod) {
            $latest = (clone $base)->max('period');
            if ($latest) {
                $base->where('period', $latest);
                $snapshotLabel = (string) $latest;
            }
        }

        $rows = (int) (clone $base)->count();
        if ($rows === 0) {
            return new HtmlString('<div style="font-size:13px;opacity:.85;">Нет данных об оплатах по этому арендатору.</div>');
        }

        $hasAccrued = static::hasColumn('contract_debts', 'accrued_amount');
        $hasPaid = static::hasColumn('contract_debts', 'paid_amount');
        $hasDebt = static::hasColumn('contract_debts', 'debt_amount');
        $hasContractExternalId = static::hasColumn('contract_debts', 'contract_external_id');

        $accrued = $hasAccrued ? (float) ((clone $base)->sum('accrued_amount') ?? 0) : null;
        $paid = $hasPaid ? (float) ((clone $base)->sum('paid_amount') ?? 0) : null;
        $debt = $hasDebt ? (float) ((clone $base)->sum('debt_amount') ?? 0) : null;

        if ($debt === null && $accrued !== null && $paid !== null) {
            $debt = $accrued - $paid;
        }

        $contractsCount = $hasContractExternalId
            ? (int) ((clone $base)->distinct('contract_external_id')->count('contract_external_id') ?? 0)
            : null;

        $cards = [
            ['title' => 'Начислено', 'value' => $accrued !== null ? static::formatRub($accrued) : '—'],
            ['title' => 'Оплачено', 'value' => $paid !== null ? static::formatRub($paid) : '—'],
            ['title' => 'Долг', 'value' => $debt !== null ? static::formatRub($debt) : '—'],
        ];

        $cardsHtml = '';
        foreach ($cards as $card) {
            $cardsHtml .= '<div style="border:1px solid rgba(0,0,0,.10);border-radius:12px;padding:10px 12px;">'
                . '<div style="font-size:12px;opacity:.75;line-height:1.2;">' . e($card['title']) . '</div>'
                . '<div style="margin-top:4px;font-size:24px;font-weight:700;line-height:1.15;">' . e($card['value']) . '</div>'
                . '</div>';
        }

        $meta = [];
        if ($snapshotLabel !== null) {
            $meta[] = 'Снимок: <strong>' . e($snapshotLabel) . '</strong>';
        }
        $meta[] = 'Строк: <strong>' . e((string) $rows) . '</strong>';
        if ($contractsCount !== null) {
            $meta[] = 'Договоров: <strong>' . e((string) $contractsCount) . '</strong>';
        }

        $metaHtml = '<div style="margin-bottom:10px;font-size:12px;opacity:.78;">' . implode(' • ', $meta) . '</div>';

        return new HtmlString(
            '<div>'
                . $metaHtml
                . '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;">'
                    . $cardsHtml
                . '</div>'
            . '</div>'
        );
    }

    private static function renderDebtStatusSummary(?Tenant $record): HtmlString
    {
        if (! $record) {
            return new HtmlString('<div style="font-size:13px;opacity:.85;">Статус появится после сохранения арендатора.</div>');
        }

        $label = $record->debt_status_label;
        $color = static::debtStatusHex($record->debt_status);
        $note = trim((string) ($record->debt_status_note ?? ''));
        $noteHtml = $note !== ''
            ? '<div style="margin-top:6px;opacity:.75;">Комментарий: ' . e($note) . '</div>'
            : '';

        $updatedAt = $record->debt_status_updated_at
            ? $record->debt_status_updated_at->format('d.m.Y H:i')
            : null;
        $updatedHtml = $updatedAt
            ? '<div style="margin-top:4px;opacity:.65;">Обновлено: ' . e($updatedAt) . '</div>'
            : '';

        $badge = '<span style="display:inline-flex;align-items:center;padding:2px 8px;border-radius:999px;border:1px solid ' . e($color) . ';color:' . e($color) . ';font-weight:600;font-size:12px;">'
            . e($label)
            . '</span>';

        return new HtmlString(
            '<div style="font-size:13px;">' . $badge . $noteHtml . $updatedHtml . '</div>'
        );
    }

    private static function formatRub(float $value): string
    {
        $v = round($value, 2);
        $s = number_format($v, 2, ',', ' ');

        return $s . ' ₽';
    }

    private static function formatArea(float $value): string
    {
        if ($value <= 0) {
            return '—';
        }

        $v = round($value, 2);
        $s = number_format($v, 2, ',', ' ');
        $s = rtrim(rtrim($s, '0'), ',');

        return $s . ' м²';
    }

    private static function hasColumn(string $table, string $column): bool
    {
        try {
            if (! DbSchema::hasTable($table)) {
                return false;
            }

            $cols = self::$tableColumnsCache[$table] ?? null;
            if ($cols === null) {
                $cols = DbSchema::getColumnListing($table);
                self::$tableColumnsCache[$table] = $cols;
            }

            return in_array($column, $cols, true);
        } catch (Throwable) {
            return false;
        }
    }

    private static function canManageTenantContracts(Tenant $record): bool
    {
        $user = Filament::auth()->user();
        if (! $user) {
            return false;
        }

        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return true;
        }

        return (method_exists($user, 'hasRole') && $user->hasRole('market-admin'))
            && (int) ($user->market_id ?? 0) === (int) $record->market_id;
    }

    public static function canViewAny(): bool
    {
        $user = Filament::auth()->user();

        return (bool) $user && ($user->isSuperAdmin() || (bool) $user->market_id);
    }

    public static function canCreate(): bool
    {
        $user = Filament::auth()->user();

        return (bool) $user && ($user->isSuperAdmin() || (bool) $user->market_id);
    }

    public static function canEdit($record): bool
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        return $user->market_id && $record->market_id === $user->market_id;
    }

    public static function canDelete($record): bool
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        return $user->market_id && $record->market_id === $user->market_id;
    }
}
