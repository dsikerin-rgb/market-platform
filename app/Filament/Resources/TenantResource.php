<?php
# app/Filament/Resources/TenantResource.php

namespace App\Filament\Resources;

use App\Filament\Resources\TenantResource\Pages;
use App\Filament\Resources\TenantResource\RelationManagers\RequestsRelationManager;
use App\Models\Tenant;
use App\Services\Debt\DebtAggregator;
use App\Services\Debt\DebtStatusResolver;
use Carbon\Carbon;
use Filament\Actions\Action;
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

    protected static ?int $navigationSort = 30;

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
        return null;
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
                                    ->required(fn () => (bool) $user && $user->isSuperAdmin())
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
                                    ->visible(fn () => (bool) $user && $user->isSuperAdmin())
                                    ->dehydrated(fn () => (bool) $user && $user->isSuperAdmin()),

                                Forms\Components\Hidden::make('market_id')
                                    ->default(function () use ($user) {
                                        if (! $user) {
                                            return null;
                                        }

                                        return $user->isSuperAdmin()
                                            ? (static::selectedMarketIdFromSession() ?: null)
                                            : $user->market_id;
                                    })
                                    ->dehydrated(fn () => (bool) $user && ! $user->isSuperAdmin()),

                                Forms\Components\TextInput::make('name')
                                    ->label('Название арендатора')
                                    ->required()
                                    ->maxLength(255),

                                Forms\Components\TextInput::make('short_name')
                                    ->label('Краткое название / вывеска')
                                    ->maxLength(255),

                                Forms\Components\Toggle::make('is_active')
                                    ->label('Активен')
                                    ->default(true),

                                Forms\Components\Textarea::make('notes')
                                    ->label('Примечания')
                                    ->rows(4)
                                    ->columnSpanFull(),
                            ])
                            ->columns(2),

                        Section::make('Задолженность')
                            ->schema([
                                Forms\Components\Placeholder::make('debt_status_summary')
                                    ->hiddenLabel()
                                    ->dehydrated(false)
                                    ->content(fn (?Tenant $record): HtmlString => static::renderDebtStatusSummary($record))
                                    ->columnSpanFull(),

                                Forms\Components\Select::make('debt_status')
                                    ->label('Задолженность')
                                    ->options(static::debtStatusOptions())
                                    ->native(false)
                                    ->placeholder('Автоматически (из 1С)')
                                    ->nullable()
                                    ->helperText('По умолчанию — автоматически из 1С (последний снимок в contract_debts). Выберите вручную только как временный override.')
                                    ->columnSpan(1),

                            ])
                            ->columns([
                                'default' => 1,
                                'md' => 2,
                            ]),
                    ]),

                Tab::make('Торговые места')
                    ->schema([
                        Section::make()
                            ->schema([
                                Forms\Components\Placeholder::make('spaces_last_period')
                                    ->hiddenLabel()
                                    ->dehydrated(false)
                                    ->content(fn (?Tenant $record) => static::renderSpacesLastPeriod($record))
                                    ->columnSpanFull(),
                            ]),

                        Section::make('Расчеты по договорам')
                            ->description('Начислено, оплачено и долг по последнему снимку 1С.')
                            ->collapsed()
                            ->schema([
                                Forms\Components\Placeholder::make('payments_debt_summary')
                                    ->hiddenLabel()
                                    ->dehydrated(false)
                                    ->content(fn (?Tenant $record): HtmlString => static::renderPaymentsDebtSummary($record))
                                    ->columnSpanFull(),
                            ]),

                        Section::make('История аренды мест')
                            ->description('Последние изменения привязки мест к арендатору.')
                            ->collapsed()
                            ->schema([
                                Forms\Components\Placeholder::make('space_history_recent')
                                    ->hiddenLabel()
                                    ->dehydrated(false)
                                    ->content(fn (?Tenant $record): HtmlString => static::renderSpaceHistory($record, 30))
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

                        static::cabinetAccessSection(),

                        Section::make('Сотрудники по торговым местам')
                            ->schema([
                                Forms\Components\Placeholder::make('contacts_staff_by_spaces')
                                    ->hiddenLabel()
                                    ->dehydrated(false)
                                    ->content(fn (?Tenant $record): HtmlString => static::renderContactsStaffBySpaces($record))
                                    ->columnSpanFull(),
                            ])
                            ->collapsible()
                            ->collapsed(),
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

                Tab::make('Кабинет')
                    ->schema([
                        Section::make('Витрина арендатора')
                            ->schema([
                                Forms\Components\TextInput::make('showcase_title')
                                    ->label('Заголовок витрины')
                                    ->maxLength(255),

                                Forms\Components\TextInput::make('showcase_phone')
                                    ->label('Телефон для витрины')
                                    ->maxLength(50),

                                Forms\Components\TextInput::make('showcase_telegram')
                                    ->label('Telegram / ссылка')
                                    ->maxLength(255),

                                Forms\Components\TextInput::make('showcase_website')
                                    ->label('Сайт')
                                    ->maxLength(255),

                                Forms\Components\Textarea::make('showcase_description')
                                    ->label('Описание витрины')
                                    ->rows(4)
                                    ->columnSpanFull(),
                            ])
                            ->columns(2),
                    ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $user = Filament::auth()->user();

        $toolbarActions = static::tenantToolbarActions();

        $debtStatusLabelForTable = static function (Tenant $record): string {
            $resolved = static::resolveDebtStatusForDisplay($record);

            if (($resolved['status'] ?? null) === 'pending') {
                return 'Срок не нарушен';
            }

            return (string) ($resolved['label'] ?? 'Нет данных');
        };

        $debtStatusColumn = TextColumn::make('debt_status')
            ->label('Задолженность');

        if (method_exists($debtStatusColumn, 'state')) {
            $debtStatusColumn->state($debtStatusLabelForTable);
        } elseif (method_exists($debtStatusColumn, 'getStateUsing')) {
            $debtStatusColumn->getStateUsing($debtStatusLabelForTable);
        } else {
            $debtStatusColumn->formatStateUsing($debtStatusLabelForTable);
        }

        $debtStatusColumn
            ->badge()
            ->color(fn (Tenant $record) => static::debtStatusColor(static::resolveDebtStatusForDisplay($record)['status']))
            ->description(fn (Tenant $record) => static::resolveDebtStatusForDisplay($record)['mode'] === 'manual' ? 'Вручную' : 'Автоматически (1С)');

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

                TextColumn::make('financial_snapshot_at')
                    ->label('Снимок 1С')
                    ->formatStateUsing(function ($state): string {
                        if (! filled($state)) {
                            return '—';
                        }

                        try {
                            return Carbon::parse((string) $state)->format('d.m.Y H:i');
                        } catch (\Throwable) {
                            return (string) $state;
                        }
                    })
                    ->sortable(),

                TextColumn::make('financial_accrued_sum')
                    ->label('Начислено (1С)')
                    ->money('RUB', locale: 'ru')
                    ->sortable(),

                TextColumn::make('financial_debt_sum')
                    ->label('Долг (1С)')
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

                $debtStatusColumn,

                IconColumn::make('is_active')
                    ->label('Карточка активна')
                    ->boolean()
                    ->alignCenter()
                    ->toggleable(isToggledHiddenByDefault: true),

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
            ->defaultSort('financial_debt_sum', 'desc')
            ->toolbarActions($toolbarActions)
            ->recordUrl(fn (Tenant $record): string => static::getUrl('edit', ['record' => $record]));
    }

    private static function cabinetAccessSection(): Section
    {
        return Section::make('Доступ в кабинет арендатора')
            ->schema([
                Forms\Components\TextInput::make('cabinet_user_name')
                    ->label('Имя пользователя кабинета')
                    ->maxLength(255)
                    ->placeholder('Например: ИП Иванов И.И.'),

                Forms\Components\TextInput::make('cabinet_user_email')
                    ->label('Логин (email)')
                    ->email()
                    ->maxLength(255)
                    ->placeholder('tenant@example.com'),

                Forms\Components\TextInput::make('cabinet_user_password')
                    ->label('Новый пароль')
                    ->password()
                    ->revealable()
                    ->minLength(8)
                    ->maxLength(255)
                    ->helperText('Для существующего аккаунта оставьте пустым, если пароль менять не нужно.'),

                Forms\Components\Repeater::make('cabinet_additional_users')
                    ->label('Доп. пользователи кабинета')
                    ->columnSpanFull()
                    ->defaultItems(0)
                    ->addActionLabel('Добавить сотрудника')
                    ->reorderable(false)
                    ->collapsible()
                    ->collapsed()
                    ->itemLabel(function (array $state): ?string {
                        $name = trim((string) ($state['name'] ?? ''));
                        $email = trim((string) ($state['email'] ?? ''));

                        if ($name !== '' && $email !== '') {
                            return $name . ' (' . $email . ')';
                        }

                        if ($email !== '') {
                            return $email;
                        }

                        if ($name !== '') {
                            return $name;
                        }

                        return 'Новый сотрудник';
                    })
                    ->schema([
                        Forms\Components\Hidden::make('id')
                            ->dehydrated(true),

                        Forms\Components\TextInput::make('name')
                            ->label('Имя сотрудника')
                            ->maxLength(255)
                            ->placeholder('Например: Менеджер точки'),

                        Forms\Components\TextInput::make('email')
                            ->label('Логин (email)')
                            ->email()
                            ->maxLength(255)
                            ->placeholder('employee@example.com'),

                        Forms\Components\Select::make('space_ids')
                            ->label('Торговые места')
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->options(function (?Tenant $record): array {
                                if (! $record) {
                                    return [];
                                }

                                return \App\Models\MarketSpace::query()
                                    ->where('tenant_id', (int) $record->id)
                                    ->when((int) ($record->market_id ?? 0) > 0, fn ($query) => $query->where('market_id', (int) $record->market_id))
                                    ->orderByRaw('COALESCE(code, number, display_name) asc')
                                    ->get(['id', 'code', 'number', 'display_name'])
                                    ->mapWithKeys(static function ($space): array {
                                        $label = trim((string) ($space->code ?: $space->number ?: $space->display_name ?: ('#' . $space->id)));
                                        $name = trim((string) ($space->display_name ?? ''));

                                        return [(int) $space->id => $name !== '' ? ($label . ' · ' . $name) : $label];
                                    })
                                    ->all();
                            })
                            ->helperText('Если не выбрано ни одного места, сотрудник видит все места арендатора.'),

                        Forms\Components\TextInput::make('password')
                            ->label('Новый пароль')
                            ->password()
                            ->revealable()
                            ->maxLength(255)
                            ->minLength(8)
                            ->suffixAction(
                                Action::make('generateAdditionalCabinetUserPassword')
                                    ->label('')
                                    ->tooltip('Сгенерировать пароль')
                                    ->icon('heroicon-m-sparkles')
                                    ->color('gray')
                                    ->iconButton()
                                    ->action(function (\Filament\Schemas\Components\Utilities\Set $set): void {
                                        $set(
                                            'password',
                                            \Illuminate\Support\Str::password(length: 12, letters: true, numbers: true, symbols: false, spaces: false),
                                        );
                                    }),
                                isInline: true,
                            )
                            ->helperText('Можно ввести пароль вручную или сгенерировать кнопкой.')
                            ->dehydrated(true),
                    ])
                    ->columns(3)
                    ->helperText('Сотрудники арендатора смогут входить в кабинет по своим логинам и паролям.'),
            ])
            ->collapsible()
            ->collapsed()
            ->columns(2);
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
        $query = parent::getEloquentQuery()
            ->where('is_active', true);
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

        return static::withFinancialMetrics($query);
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

    protected static function withFinancialMetrics(Builder $query): Builder
    {
        if (! DbSchema::hasTable('contract_debts') || ! static::hasColumn('contract_debts', 'tenant_id')) {
            return $query->addSelect([
                'financial_snapshot_at' => DB::raw('NULL'),
                'financial_accrued_sum' => DB::raw('0'),
                'financial_debt_sum' => DB::raw('0'),
            ]);
        }

        $hasMarketId = static::hasColumn('contract_debts', 'market_id');
        $hasCalculatedAt = static::hasColumn('contract_debts', 'calculated_at');
        $hasAccruedAmount = static::hasColumn('contract_debts', 'accrued_amount');
        $hasDebtAmount = static::hasColumn('contract_debts', 'debt_amount');

        $snapshotSubquery = DB::table('contract_debts as cd')
            ->whereColumn('cd.tenant_id', 'tenants.id');

        if ($hasMarketId) {
            $snapshotSubquery->whereColumn('cd.market_id', 'tenants.market_id');
        }

        if ($hasCalculatedAt) {
            $snapshotSubquery->selectRaw('MAX(cd.calculated_at)');
        } else {
            $snapshotSubquery->selectRaw('NULL');
        }

        $latestSnapshotConstraint = function ($query, string $alias) use ($hasCalculatedAt, $hasMarketId): void {
            if (! $hasCalculatedAt) {
                return;
            }

            $query->where("{$alias}.calculated_at", '=', function ($sub) use ($hasMarketId): void {
                $sub->from('contract_debts as cd2')
                    ->selectRaw('MAX(cd2.calculated_at)')
                    ->whereColumn('cd2.tenant_id', 'tenants.id');

                if ($hasMarketId) {
                    $sub->whereColumn('cd2.market_id', 'tenants.market_id');
                }
            });
        };

        $accruedSubquery = DB::table('contract_debts as cd')
            ->whereColumn('cd.tenant_id', 'tenants.id');

        if ($hasMarketId) {
            $accruedSubquery->whereColumn('cd.market_id', 'tenants.market_id');
        }

        $latestSnapshotConstraint($accruedSubquery, 'cd');

        $debtSubquery = DB::table('contract_debts as cd')
            ->whereColumn('cd.tenant_id', 'tenants.id');

        if ($hasMarketId) {
            $debtSubquery->whereColumn('cd.market_id', 'tenants.market_id');
        }

        $latestSnapshotConstraint($debtSubquery, 'cd');

        return $query->addSelect([
            'financial_snapshot_at' => $snapshotSubquery,
            'financial_accrued_sum' => $hasAccruedAmount
                ? $accruedSubquery->selectRaw('COALESCE(SUM(cd.accrued_amount), 0)')
                : DB::raw('0'),
            'financial_debt_sum' => $hasDebtAmount
                ? $debtSubquery->selectRaw('COALESCE(SUM(cd.debt_amount), 0)')
                : DB::raw('0'),
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

        $totalWithVat = 0.0;
        $totalArea = 0.0;
        $contractsMappedTotal = 0;
        $contractsMappedActiveTotal = 0;
        $contractsTabBaseUrl = '?tab=dogovory::data::tab';
        try {
            $contractsTabBaseUrl = static::getUrl('edit', [
                'record' => (int) $record->id,
                'tab' => 'dogovory::data::tab',
            ]);
        } catch (\Throwable) {
            // keep relative fallback
        }

        $tableRows = '';
        foreach ($rows as $r) {
            $totalWithVat += (float) $r->total_with_vat_sum;

            $code = trim((string) ($r->place_code ?? ''));
            $name = (string) ($r->place_name ?? '');
            $spaceId = isset($r->market_space_id) ? (int) $r->market_space_id : null;

            $codeLabel = $code !== '' ? $code : '—';
            $nameLabel = trim($name) !== '' ? $name : '—';
            $spaceUrl = null;
            if ($spaceId !== null && $spaceId > 0) {
                try {
                    $spaceUrl = route('filament.admin.resources.market-spaces.edit', ['record' => $spaceId]);
                } catch (\Throwable) {
                    $spaceUrl = null;
                }
            }

            $codeCell = $spaceUrl
                ? '<a href="' . e($spaceUrl) . '" class="tenant-spaces__space-link">' . e($codeLabel) . '</a>'
                : e($codeLabel);
            $nameCell = $spaceUrl
                ? '<a href="' . e($spaceUrl) . '" class="tenant-spaces__space-link">' . e($nameLabel) . '</a>'
                : e($nameLabel);

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
                    $links[] = '<a href="' . e($contractsTabBaseUrl) . '#tenant-contract-' . (int) $contractItem['id'] . '" class="tenant-spaces__contract-link">'
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
                    <td class="tenant-spaces__code">' . $codeCell . '</td>
                    <td class="tenant-spaces__name">' . $nameCell . '</td>
                    ' . $areaCell . '
                    <td class="tenant-spaces__num">' . e(static::formatRub((float) $r->total_with_vat_sum)) . '</td>
                    <td class="tenant-spaces__num">' . $paidCell . '</td>
                    <td class="tenant-spaces__num">' . $debtCell . '</td>
                    <td class="tenant-spaces__contracts">' . $contractCell . '</td>
                    <td class="tenant-spaces__map">' . $mapCell . '</td>
                </tr>
            ';
        }

        $areaHeader = $hasArea ? '<th class="tenant-spaces__num">Площадь</th>' : '';
        $colspan = $hasArea ? 8 : 7;

        $summaryCards = [
            ['label' => 'Месяц начислений', 'value' => $periodLabel],
            ['label' => 'Торговых мест', 'value' => (string) $rows->count()],
        ];

        if ($hasArea) {
            $summaryCards[] = ['label' => 'Площадь', 'value' => static::formatArea($totalArea)];
        }

        $summaryCards[] = ['label' => 'Итого с НДС', 'value' => static::formatRub($totalWithVat)];
        if ($hasPaymentsData) {
            $summaryCards[] = ['label' => 'Оплачено (снимок)', 'value' => static::formatRub($paymentsTotalPaid)];
            $summaryCards[] = ['label' => 'Долг (снимок)', 'value' => static::formatRub($paymentsTotalDebt)];
        }
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
.tenant-spaces__space-link{color:inherit;text-decoration:underline;text-underline-offset:2px}
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

    private static function renderContactsStaffBySpaces(?Tenant $record): HtmlString
    {
        if (! $record) {
            return new HtmlString('<div style="font-size:13px;opacity:.85;">Список сотрудников появится после сохранения арендатора.</div>');
        }

        if (! DbSchema::hasTable('market_spaces') || ! DbSchema::hasTable('users')) {
            return new HtmlString('<div style="font-size:13px;opacity:.85;">Данные сотрудников недоступны.</div>');
        }

        $spaces = DB::table('market_spaces')
            ->where('tenant_id', (int) $record->id)
            ->where('market_id', (int) $record->market_id)
            ->orderByRaw('COALESCE(code, number, display_name, id::text) ASC')
            ->get(['id', 'code', 'number', 'display_name']);

        if ($spaces->isEmpty()) {
            return new HtmlString('<div style="font-size:13px;opacity:.85;">У арендатора нет торговых мест.</div>');
        }

        $cabinetTabUrl = url('/admin/tenants/' . (int) $record->id . '/edit?tab=kabinet::data::tab');

        $usersQuery = DB::table('users')
            ->where('tenant_id', (int) $record->id)
            ->select(['id', 'name', 'email']);

        if (static::hasColumn('users', 'market_id')) {
            $usersQuery->where(function ($query) use ($record): void {
                $query->where('market_id', (int) $record->market_id)
                    ->orWhereNull('market_id');
            });
        }

        $users = $usersQuery->orderBy('name')->orderBy('id')->get();
        if ($users->isEmpty()) {
            return new HtmlString(
                '<div style="font-size:13px;opacity:.85;">Сотрудников арендатора пока нет. '
                . '<a href="' . e($cabinetTabUrl) . '" style="text-decoration:underline;text-underline-offset:2px;">Добавить на вкладке «Кабинет»</a>.'
                . '</div>'
            );
        }

        $spaceIds = $spaces->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->values()
            ->all();

        $scopedSpaceIdsByUser = [];
        if (DbSchema::hasTable('tenant_user_market_spaces')) {
            $pivotRows = DB::table('tenant_user_market_spaces')
                ->whereIn('user_id', $users->pluck('id')->map(static fn ($id): int => (int) $id)->values()->all())
                ->whereIn('market_space_id', $spaceIds)
                ->get(['user_id', 'market_space_id']);

            foreach ($pivotRows as $pivotRow) {
                $userId = (int) ($pivotRow->user_id ?? 0);
                $spaceId = (int) ($pivotRow->market_space_id ?? 0);
                if ($userId <= 0 || $spaceId <= 0) {
                    continue;
                }

                if (! isset($scopedSpaceIdsByUser[$userId])) {
                    $scopedSpaceIdsByUser[$userId] = [];
                }
                $scopedSpaceIdsByUser[$userId][$spaceId] = true;
            }
        }

        $staffBySpace = [];
        foreach ($users as $user) {
            $userId = (int) ($user->id ?? 0);
            if ($userId <= 0) {
                continue;
            }

            $name = trim((string) ($user->name ?? ''));
            $email = trim((string) ($user->email ?? ''));
            $label = $name !== '' ? $name : ($email !== '' ? $email : ('Пользователь #' . $userId));

            $scopedIds = isset($scopedSpaceIdsByUser[$userId]) ? array_keys($scopedSpaceIdsByUser[$userId]) : [];
            $allSpaces = $scopedIds === [];
            $effectiveIds = $allSpaces ? $spaceIds : $scopedIds;

            foreach ($effectiveIds as $spaceId) {
                $spaceId = (int) $spaceId;
                if ($spaceId <= 0) {
                    continue;
                }

                if (! isset($staffBySpace[$spaceId])) {
                    $staffBySpace[$spaceId] = [];
                }

                $staffBySpace[$spaceId][] = [
                    'id' => $userId,
                    'label' => $label,
                    'all_spaces' => $allSpaces,
                ];
            }
        }

        $cards = '';
        foreach ($spaces as $space) {
            $spaceId = (int) ($space->id ?? 0);
            if ($spaceId <= 0) {
                continue;
            }

            $code = trim((string) ($space->code ?? ''));
            $number = trim((string) ($space->number ?? ''));
            $name = trim((string) ($space->display_name ?? ''));
            $spaceLabel = $code !== '' ? $code : ($number !== '' ? $number : ('#' . $spaceId));
            if ($name !== '') {
                $spaceLabel .= ' · ' . $name;
            }

            $members = $staffBySpace[$spaceId] ?? [];
            $membersHtml = '';
            if ($members === []) {
                $membersHtml = '<div class="tenant-contact-staff__empty">Сотрудники не назначены.</div>';
            } else {
                foreach ($members as $member) {
                    $membersHtml .= '<div class="tenant-contact-staff__member">'
                        . '<a href="' . e($cabinetTabUrl) . '#cabinet-user-' . (int) ($member['id'] ?? 0) . '" class="tenant-contact-staff__member-link">'
                        . e((string) $member['label'])
                        . '</a>'
                        . (! empty($member['all_spaces']) ? ' <span class="tenant-contact-staff__note">(все места)</span>' : '')
                        . '</div>';
                }
            }

            $cards .= '<div class="tenant-contact-staff__card">'
                . '<div class="tenant-contact-staff__space">' . e($spaceLabel) . '</div>'
                . '<div class="tenant-contact-staff__members">' . $membersHtml . '</div>'
                . '</div>';
        }

        $style = '
<style>
.tenant-contact-staff{display:flex;flex-direction:column;gap:10px}
.tenant-contact-staff__head{display:flex;align-items:center;justify-content:space-between;gap:10px}
.tenant-contact-staff__hint{font-size:12px;opacity:.75}
.tenant-contact-staff__action{font-size:12px;text-decoration:underline;text-underline-offset:2px}
.tenant-contact-staff__grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:8px}
.tenant-contact-staff__card{border:1px solid rgba(14,165,233,.30);border-radius:10px;padding:8px 10px;background:rgba(14,165,233,.07)}
.dark .tenant-contact-staff__card{border-color:rgba(56,189,248,.35);background:rgba(56,189,248,.10)}
.tenant-contact-staff__space{font-size:12px;font-weight:700;line-height:1.3}
.tenant-contact-staff__members{margin-top:6px;display:flex;flex-direction:column;gap:4px}
.tenant-contact-staff__member{font-size:12px;line-height:1.35}
.tenant-contact-staff__member-link{text-decoration:underline;text-underline-offset:2px}
.tenant-contact-staff__note{opacity:.75;font-size:11px}
.tenant-contact-staff__empty{font-size:12px;opacity:.78}
</style>';

        $html = $style . '
<div class="tenant-contact-staff">
    <div class="tenant-contact-staff__head">
        <div class="tenant-contact-staff__hint">Настройка сотрудников и привязок к местам выполняется на вкладке «Кабинет».</div>
        <a href="' . e($cabinetTabUrl) . '" class="tenant-contact-staff__action">Управлять сотрудниками</a>
    </div>
    <div class="tenant-contact-staff__grid">' . ($cards !== '' ? $cards : '<div class="tenant-contact-staff__empty">Нет данных.</div>') . '</div>
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

        $query->orderByDesc('tc.starts_at')
            ->orderBy('tc.id');

        $rows = $query->limit(500)->get($select);

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

    private static function renderSpaceHistory(?Tenant $record, int $limit = 300): HtmlString
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
            ->limit(max(1, $limit))
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
        return ['' => 'Автоматически (из 1С)'] + Tenant::DEBT_STATUS_LABELS;
    }

    public static function debtStatusColor(?string $state): string
    {
        return match ($state) {
            'green', 'pending' => 'success',
            'orange' => 'warning',
            'red' => 'danger',
            default => 'gray',
        };
    }

    private static function debtStatusHex(?string $state): string
    {
        return match ($state) {
            'green', 'pending' => '#16a34a',
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

        $resolved = static::resolveDebtStatusForDisplay($record);
        $label = $resolved['label'];
        $color = static::debtStatusHex($resolved['status']);

        $metaParts = [];
        $metaParts[] = $resolved['mode'] === 'manual' ? 'Режим: вручную' : 'Режим: автоматически (1С)';

        if (filled($resolved['updated_at'])) {
            $metaParts[] = 'Обновлено: ' . e((string) $resolved['updated_at']);
        }

        if (filled($resolved['source'])) {
            $metaParts[] = e((string) $resolved['source']);
        }

        $metaHtml = $metaParts === []
            ? ''
            : '<div style="margin-top:4px;opacity:.65;">' . implode(' • ', $metaParts) . '</div>';

        $badge = '<span style="display:inline-flex;align-items:center;padding:2px 8px;border-radius:999px;border:1px solid ' . e($color) . ';color:' . e($color) . ';font-weight:600;font-size:12px;">'
            . e($label)
            . '</span>';

        return new HtmlString(
            '<div style="font-size:13px;">' . $badge . $metaHtml . '</div>'
        );
    }

    /**
     * @return array{mode:string,status:?string,label:string,updated_at:?string,source:?string,severity:int}
     */
    public static function resolveDebtStatusForDisplay(?Tenant $record): array
    {
        if (! $record) {
            return [
                'mode' => 'auto',
                'status' => null,
                'label' => 'Auto: no data',
                'updated_at' => null,
                'source' => null,
                'severity' => 0,
            ];
        }

        $resolver = app(DebtStatusResolver::class);

        // Check manual status first.
        $manualStatus = trim($record->debt_status ?? '');
        if ($manualStatus !== '' && isset(Tenant::DEBT_STATUS_LABELS[$manualStatus])) {
            return [
                'mode' => 'manual',
                'status' => $manualStatus,
                'label' => $resolver->labelForStatus($manualStatus, (int) $record->market_id),
                'updated_at' => $record->debt_status_updated_at?->format('d.m.Y H:i'),
                'source' => 'Manual override',
                'severity' => self::getDebtSeverity($manualStatus),
            ];
        }

        // Prefer space-level aggregation, but fall back to tenant-level 1C status when no space data exists.
        $aggregator = app(DebtAggregator::class);
        $aggregateMode = self::getTenantAggregateMode($record);
        $result = $aggregator->aggregate($record, $aggregateMode);

        if ($result['aggregate_status'] === null || $result['aggregate_status'] === 'gray') {
            return $resolver->resolve($record);
        }

        return [
            'mode' => 'auto',
            'status' => $result['aggregate_status'],
            'label' => $result['aggregate_label'],
            'updated_at' => null,
            'source' => 'Aggregator mode: ' . $aggregateMode,
            'severity' => $result['aggregate_severity'],
        ];
    }

    /**
     * Resolve tenant aggregate mode.
     */
    private static function getTenantAggregateMode(Tenant $tenant): string
    {
        $market = $tenant->market;
        if (! $market || ! isset($market->settings['debt_monitoring'])) {
            return 'worst';
        }

        return $market->settings['debt_monitoring']['tenant_aggregate_mode'] ?? 'worst';
    }


    /**
     * Получить severity статуса.
     */
    private static function getDebtSeverity(string $status): int
    {
        return match ($status) {
            'green' => 0,
            'pending' => 1,
            'orange' => 2,
            'red' => 3,
            default => 0,
        };
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
