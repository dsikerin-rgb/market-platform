<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\TenantContractResource\Pages;
use App\Models\MarketSpace;
use App\Models\TenantContract;
use App\Services\MarketSpaces\SpaceGroupResolver;
use App\Services\TenantContracts\ContractDocumentClassifier;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class TenantContractResource extends BaseResource
{
    protected static ?string $model = TenantContract::class;

    protected static ?string $recordTitleAttribute = 'number';

    protected static ?string $modelLabel = 'Договор';
    protected static ?string $pluralModelLabel = 'Договоры';
    protected static ?string $navigationLabel = 'Договоры';
    protected static ?string $slug = 'contracts';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected static ?int $navigationSort = 35;

    /** @var array<string, array<string, mixed>> */
    private static array $classificationCache = [];

    /** @var array<string, array{is_composite:bool,group_token:?string,group_segments:?string}> */
    private static array $spaceGroupMetaCache = [];

    /** @var array<int, array<int, array{chain_count:int,chain_position:int,overlap_count:int}>> */
    private static array $chainStatsCache = [];

    /** @var array<int, array<string, bool>> */
    private static array $latestDebtContractIdsCache = [];

    /** @var array<int, array<int, bool>> */
    private static array $latestAccrualContractIdsCache = [];

    /** @var array<int, array<string, bool>> */
    private static array $debtHistoryContractIdsCache = [];

    /** @var array<int, array<int, bool>> */
    private static array $accrualHistoryContractIdsCache = [];

    /** @var array<string, array<string, list<int>>> */
    private static array $workbenchIdsCache = [];

    public static function shouldRegisterNavigation(): bool
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        return $user->hasAnyRole(['market-admin', 'market-manager'])
            && (bool) $user->market_id;
    }

    public static function getNavigationGroup(): ?string
    {
        return null;
    }

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
            'number',
            'external_id',
            'status',
            'tenant.name',
            'tenant.short_name',
            'marketSpace.number',
            'marketSpace.display_name',
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Документ из 1С')
                ->description('Поля ниже приходят из 1С и доступны только для просмотра.')
                ->schema([
                    Forms\Components\TextInput::make('tenant_display')
                        ->label('Арендатор')
                        ->formatStateUsing(fn (?TenantContract $record): string => static::tenantLabel($record))
                        ->disabled()
                        ->dehydrated(false),

                    Forms\Components\TextInput::make('number')
                        ->label('Номер документа')
                        ->disabled()
                        ->dehydrated(false),

                    Forms\Components\TextInput::make('document_type_display')
                        ->label('Тип документа')
                        ->formatStateUsing(fn (?TenantContract $record): string => (string) static::classificationForRecord($record)['label'])
                        ->disabled()
                        ->dehydrated(false),

                    Forms\Components\TextInput::make('place_token_display')
                        ->label('Токен места')
                        ->formatStateUsing(fn (?TenantContract $record): string => (string) (static::classificationForRecord($record)['place_token'] ?: '—'))
                        ->disabled()
                        ->dehydrated(false),

                    Forms\Components\TextInput::make('space_group_display')
                        ->label('Группа мест')
                        ->formatStateUsing(fn (?TenantContract $record): string => (string) (static::spaceGroupMetaForRecord($record)['group_token'] ?: '—'))
                        ->disabled()
                        ->dehydrated(false),

                    Forms\Components\TextInput::make('space_group_segments_display')
                        ->label('Сегменты в группе')
                        ->formatStateUsing(fn (?TenantContract $record): string => (string) (static::spaceGroupMetaForRecord($record)['group_segments'] ?: '—'))
                        ->disabled()
                        ->dehydrated(false),

                    Forms\Components\TextInput::make('document_date_display')
                        ->label('Дата из номера')
                        ->formatStateUsing(fn (?TenantContract $record): string => static::formatClassifierDate(static::classificationForRecord($record)['document_date'] ?? null))
                        ->disabled()
                        ->dehydrated(false),

                    Forms\Components\TextInput::make('signed_at')
                        ->label('Дата подписания из 1С')
                        ->formatStateUsing(fn (?TenantContract $record): string => $record?->signed_at?->format('d.m.Y') ?: '—')
                        ->disabled()
                        ->dehydrated(false),

                    Forms\Components\TextInput::make('starts_at')
                        ->label('Техническая дата 1С')
                        ->helperText('Не используется как основная дата договора. Для истории приоритет у даты из номера договора.')
                        ->formatStateUsing(fn (?TenantContract $record): string => $record?->starts_at?->format('d.m.Y') ?: '—')
                        ->disabled()
                        ->dehydrated(false),

                    Forms\Components\TextInput::make('ends_at')
                        ->label('Окончание')
                        ->formatStateUsing(fn (?TenantContract $record): string => $record?->ends_at?->format('d.m.Y') ?: '—')
                        ->disabled()
                        ->dehydrated(false),

                    Forms\Components\TextInput::make('status_display')
                        ->label('Статус договора')
                        ->formatStateUsing(fn (?TenantContract $record): string => static::contractStatusLabel($record?->status))
                        ->disabled()
                        ->dehydrated(false),

                    Forms\Components\TextInput::make('is_active_display')
                        ->label('Активность из 1С')
                        ->formatStateUsing(fn (?TenantContract $record): string => match (true) {
                            ! $record => '—',
                            (bool) $record->is_active => 'Активен',
                            default => 'Неактивен',
                        })
                        ->disabled()
                        ->dehydrated(false),

                    Forms\Components\TextInput::make('chain_display')
                        ->label('Цепочка')
                        ->formatStateUsing(fn (?TenantContract $record): string => $record ? static::chainDisplay($record) : '—')
                        ->disabled()
                        ->dehydrated(false),

                    Forms\Components\TextInput::make('overlap_display')
                        ->label('Наложение')
                        ->formatStateUsing(fn (?TenantContract $record): string => $record ? static::overlapDisplay($record) : '—')
                        ->disabled()
                        ->dehydrated(false),
                ])
                ->columns(2),

            Section::make('Локальная привязка')
                ->description('Редактируются только локальные поля привязки. Канонические данные договора остаются под управлением 1С.')
                ->schema([
                    Forms\Components\Select::make('space_mapping_mode')
                        ->label('Режим привязки')
                        ->options([
                            TenantContract::SPACE_MAPPING_MODE_AUTO => 'Авто (1С может обновить)',
                            TenantContract::SPACE_MAPPING_MODE_MANUAL => 'Ручная фиксация',
                            TenantContract::SPACE_MAPPING_MODE_EXCLUDED => 'Не участвует в привязке к месту',
                        ])
                        ->default(TenantContract::SPACE_MAPPING_MODE_AUTO)
                        ->native(false)
                        ->helperText('При изменении места режим станет ручным автоматически. В авто-режиме 1С может перезаписать привязку. Режим "Не участвует" исключает договор из привязки и очищает место.'),

                    Forms\Components\Checkbox::make('limit_spaces_to_contract_tenant')
                        ->label('Только места этого арендатора')
                        ->default(true)
                        ->live()
                        ->dehydrated(false)
                        ->helperText('По умолчанию список мест ограничен арендатором договора. Снимите галочку, если раньше у этого места был другой арендатор и нужен поиск по всему рынку.'),

                    Forms\Components\Checkbox::make('limit_spaces_to_place_group')
                        ->label('Только места этой группы')
                        ->default(true)
                        ->live()
                        ->dehydrated(false)
                        ->visible(fn (?TenantContract $record): bool => filled(static::spaceGroupMetaForRecord($record)['group_token'] ?? null))
                        ->helperText('Если система распознала группу мест из номера договора, список мест будет ограничен этой группой. Снимите галочку, если нужно искать место по всему рынку.'),

                    Forms\Components\Select::make('market_space_id')
                        ->label('Торговое место')
                        ->options(function (Get $get, ?TenantContract $record): array {
                            if (! $record) {
                                return [];
                            }

                            $currentSpaceId = (int) ($get('market_space_id') ?: $record->market_space_id ?: 0);
                            $restrictToTenant = (bool) ($get('limit_spaces_to_contract_tenant') ?? true);
                            $spaceGroupMeta = static::spaceGroupMetaForRecord($record);
                            $restrictToGroup = (bool) ($get('limit_spaces_to_place_group') ?? true);
                            $groupToken = trim((string) ($spaceGroupMeta['group_token'] ?? ''));
                            $groupSegments = static::explodeGroupSegments($spaceGroupMeta['group_segments'] ?? null);

                            $spaces = MarketSpace::query()
                                ->where('market_id', (int) $record->market_id)
                                ->when(
                                    $restrictToTenant && filled($record->tenant_id),
                                    fn (Builder $query) => $query->where(function (Builder $query) use ($record, $currentSpaceId): void {
                                            $query->where('tenant_id', (int) $record->tenant_id);

                                            if ($currentSpaceId > 0) {
                                                $query->orWhere('id', $currentSpaceId);
                                            }
                                        })
                                    )
                                ->when(
                                    $restrictToGroup && $groupToken !== '',
                                    fn (Builder $query) => $query->where(function (Builder $query) use ($groupToken, $currentSpaceId): void {
                                        $query->where('space_group_token', $groupToken);

                                        if ($currentSpaceId > 0) {
                                            $query->orWhere('id', $currentSpaceId);
                                        }
                                    })
                                )
                                ->orderByRaw('COALESCE(display_name, number, code)')
                                ->get(['id', 'display_name', 'number', 'code', 'space_group_token', 'space_group_slot']);

                            if ($groupSegments !== []) {
                                $spaces = $spaces->sort(function (MarketSpace $left, MarketSpace $right) use ($groupSegments): int {
                                    $leftPriority = in_array(static::normalizeGroupSlot($left->space_group_slot), $groupSegments, true) ? 0 : 1;
                                    $rightPriority = in_array(static::normalizeGroupSlot($right->space_group_slot), $groupSegments, true) ? 0 : 1;

                                    if ($leftPriority !== $rightPriority) {
                                        return $leftPriority <=> $rightPriority;
                                    }

                                    $leftLabel = mb_strtolower(
                                        static::spaceOptionLabel(
                                            $left->display_name,
                                            $left->number,
                                            $left->code,
                                            $left->space_group_token,
                                            $left->space_group_slot,
                                        ),
                                        'UTF-8'
                                    );

                                    $rightLabel = mb_strtolower(
                                        static::spaceOptionLabel(
                                            $right->display_name,
                                            $right->number,
                                            $right->code,
                                            $right->space_group_token,
                                            $right->space_group_slot,
                                        ),
                                        'UTF-8'
                                    );

                                    return $leftLabel <=> $rightLabel;
                                })->values();
                            }

                            $options = [];
                            foreach ($spaces as $space) {
                                $options[(int) $space->id] = static::spaceOptionLabel(
                                    $space->display_name,
                                    $space->number,
                                    $space->code,
                                    $space->space_group_token,
                                    $space->space_group_slot,
                                );
                            }

                            return $options;
                        })
                        ->searchable()
                        ->preload()
                        ->nullable()
                        ->helperText('Здесь задаётся только локальная привязка договора к торговому месту. Список можно ограничить арендатором договора и, если договор составной, его группой мест.'),

                    Forms\Components\TextInput::make('space_mapping_updated_display')
                        ->label('Последняя локальная фиксация')
                        ->formatStateUsing(fn (?TenantContract $record): string => static::spaceMappingAuditLabel($record))
                        ->disabled()
                        ->dehydrated(false),

                    Forms\Components\Textarea::make('notes')
                        ->label('Заметки по привязке')
                        ->rows(5)
                        ->columnSpanFull(),
                ])
                ->columns(2),

            Section::make('Историческая цепочка по месту')
                ->description('Показывает документы по тому же токену места в порядке даты из номера договора. Именно эта дата считается основной для истории.')
                ->schema([
                    Forms\Components\Textarea::make('history_chain_display')
                        ->label('Договоры по этому месту')
                        ->formatStateUsing(fn (?TenantContract $record): string => static::historyChainPreview($record))
                        ->rows(12)
                        ->disabled()
                        ->dehydrated(false)
                        ->columnSpanFull(),
                ])
                ->columns(1),
        ]);
    }

    public static function table(Table $table): Table
    {
        $user = Filament::auth()->user();

        return $table
            ->columns([
                TextColumn::make('market.name')
                    ->label('Рынок')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->visible(fn () => (bool) $user && $user->isSuperAdmin()),

                TextColumn::make('tenant.name')
                    ->label('Арендатор')
                    ->sortable()
                    ->searchable()
                    ->placeholder('—')
                    ->description(fn (TenantContract $record): ?string => filled($record->tenant?->short_name) ? (string) $record->tenant?->short_name : null)
                    ->wrap(),

                TextColumn::make('number')
                    ->label('Номер документа')
                    ->sortable()
                    ->searchable()
                    ->placeholder('—')
                    ->wrap(),

                TextColumn::make('ordering_status')
                    ->label('Статус упорядочивания')
                    ->state(fn (TenantContract $record): string => static::orderingMeta($record)['label'])
                    ->badge()
                    ->color(fn (TenantContract $record): string => static::orderingMeta($record)['color']),

                TextColumn::make('chain_position')
                    ->label('Цепочка')
                    ->state(fn (TenantContract $record): string => static::chainDisplay($record))
                    ->badge()
                    ->color(fn (TenantContract $record): string => static::chainColor($record))
                    ->toggleable(),

                TextColumn::make('overlap_status')
                    ->label('Наложение')
                    ->state(fn (TenantContract $record): string => static::overlapDisplay($record))
                    ->badge()
                    ->color(fn (TenantContract $record): string => static::overlapColor($record))
                    ->toggleable(),

                TextColumn::make('document_type')
                    ->label('Тип документа')
                    ->state(fn (TenantContract $record): string => (string) static::classificationForRecord($record)['label'])
                    ->badge()
                    ->color(fn (TenantContract $record): string => static::documentTypeColor((string) static::classificationForRecord($record)['category'])),

                TextColumn::make('place_token')
                    ->label('Токен места')
                    ->state(fn (TenantContract $record): string => (string) (static::classificationForRecord($record)['place_token'] ?: '—'))
                    ->toggleable(),

                TextColumn::make('document_date')
                    ->label('Дата из номера')
                    ->state(fn (TenantContract $record): string => static::formatClassifierDate(static::classificationForRecord($record)['document_date'] ?? null))
                    ->toggleable(),

                TextColumn::make('effective_order_date')
                    ->label('Дата для цепочки')
                    ->state(fn (TenantContract $record): string => static::effectiveOrderDateLabel($record))
                    ->toggleable(),

                TextColumn::make('date_consistency')
                    ->label('Дата 1С / БД')
                    ->state(fn (TenantContract $record): string => static::dateConsistencyLabel($record))
                    ->badge()
                    ->color(fn (TenantContract $record): string => static::dateConsistencyColor($record)),

                TextColumn::make('market_space_link')
                    ->label('Текущее место')
                    ->state(fn (TenantContract $record): string => static::spaceLabel($record))
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('latest_debt_snapshot')
                    ->label('В последней задолженности')
                    ->state(fn (TenantContract $record): string => static::isInLatestDebtSnapshot($record) ? 'Да' : 'Нет')
                    ->badge()
                    ->color(fn (TenantContract $record): string => static::isInLatestDebtSnapshot($record) ? 'success' : 'gray')
                    ->toggleable(),

                TextColumn::make('space_mapping_mode')
                    ->label('Режим привязки')
                    ->state(fn (TenantContract $record): string => static::spaceMappingModeLabel($record->space_mapping_mode))
                    ->badge()
                    ->color(fn (TenantContract $record): string => static::spaceMappingModeColor($record->space_mapping_mode))
                    ->toggleable(),

                TextColumn::make('starts_at')
                    ->label('Техническая дата 1С')
                    ->date('d.m.Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—'),

                TextColumn::make('ends_at')
                    ->label('Окончание')
                    ->date('d.m.Y')
                    ->sortable()
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('status')
                    ->label('Статус договора')
                    ->formatStateUsing(fn (?string $state): string => static::contractStatusLabel($state))
                    ->badge()
                    ->color(fn (?string $state): string => static::contractStatusColor($state))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                IconColumn::make('is_active')
                    ->label('Активен')
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Статус договора')
                    ->options(static function (): array {
                        $query = TenantContract::query()
                            ->whereNotNull('status')
                            ->where('status', '!=', '')
                            ->distinct()
                            ->orderBy('status');

                        $user = Filament::auth()->user();

                        if ($user?->isSuperAdmin()) {
                            $selectedMarketId = static::selectedMarketIdFromSession();

                            if (filled($selectedMarketId)) {
                                $query->where('market_id', (int) $selectedMarketId);
                            }
                        } elseif ($user?->market_id) {
                            $query->where('market_id', (int) $user->market_id);
                        }

                        $values = $query->pluck('status', 'status')->all();

                        $options = [];
                        foreach ($values as $value) {
                            $value = (string) $value;
                            $options[$value] = static::contractStatusLabel($value);
                        }

                        return $options;
                    }),

                TernaryFilter::make('is_active')
                    ->label('Активность')
                    ->trueLabel('Только активные')
                    ->falseLabel('Только неактивные')
                    ->queries(
                        true: fn (Builder $query) => $query->where('is_active', true),
                        false: fn (Builder $query) => $query->where('is_active', false),
                        blank: fn (Builder $query) => $query,
                    ),

                TernaryFilter::make('has_market_space')
                    ->label('Привязка к месту')
                    ->trueLabel('Только с местом')
                    ->falseLabel('Только без места')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('market_space_id'),
                        false: fn (Builder $query) => $query->whereNull('market_space_id'),
                        blank: fn (Builder $query) => $query,
                    ),

                TernaryFilter::make('in_latest_debt_snapshot')
                    ->label('Последняя выгрузка задолженности')
                    ->trueLabel('Только финансово актуальные')
                    ->falseLabel('Только вне последней задолженности')
                    ->queries(
                        true: fn (Builder $query) => static::applyLatestDebtSnapshotFilter($query, true),
                        false: fn (Builder $query) => static::applyLatestDebtSnapshotFilter($query, false),
                        blank: fn (Builder $query) => $query,
                    ),

                SelectFilter::make('document_category')
                    ->label('Классификация документа')
                    ->options(static::contractCategoryOptions())
                    ->query(static function (Builder $query, array $data): Builder {
                        $value = trim((string) ($data['value'] ?? ''));

                        return $value !== ''
                            ? static::applyWorkbenchIdsFilter($query, $value, true)
                            : $query;
                    }),

                TernaryFilter::make('has_place_token')
                    ->label('Токен места в номере')
                    ->trueLabel('Только с токеном места')
                    ->falseLabel('Только без токена места')
                    ->queries(
                        true: fn (Builder $query) => static::applyWorkbenchIdsFilter($query, 'has_place_token', true),
                        false: fn (Builder $query) => static::applyWorkbenchIdsFilter($query, 'has_place_token', false),
                        blank: fn (Builder $query) => $query,
                    ),

                TernaryFilter::make('has_document_date')
                    ->label('Дата в номере')
                    ->trueLabel('Только с датой в номере')
                    ->falseLabel('Только без даты в номере')
                    ->queries(
                        true: fn (Builder $query) => static::applyWorkbenchIdsFilter($query, 'has_document_date', true),
                        false: fn (Builder $query) => static::applyWorkbenchIdsFilter($query, 'has_document_date', false),
                        blank: fn (Builder $query) => $query,
                    ),

                TernaryFilter::make('has_chain')
                    ->label('Историческая цепочка')
                    ->trueLabel('Только в цепочке')
                    ->falseLabel('Только вне цепочки')
                    ->queries(
                        true: fn (Builder $query) => static::applyWorkbenchIdsFilter($query, 'has_chain', true),
                        false: fn (Builder $query) => static::applyWorkbenchIdsFilter($query, 'has_chain', false),
                        blank: fn (Builder $query) => $query,
                    ),

                TernaryFilter::make('has_overlap')
                    ->label('Наложение в цепочке')
                    ->trueLabel('Только с наложением')
                    ->falseLabel('Только без наложения')
                    ->queries(
                        true: fn (Builder $query) => static::applyWorkbenchIdsFilter($query, 'has_overlap', true),
                        false: fn (Builder $query) => static::applyWorkbenchIdsFilter($query, 'has_overlap', false),
                        blank: fn (Builder $query) => $query,
                    ),

                TernaryFilter::make('needs_mapping')
                    ->label('Требует привязки к месту')
                    ->trueLabel('Только требующие привязки')
                    ->falseLabel('Только не требующие привязки')
                    ->queries(
                        true: fn (Builder $query) => static::applyWorkbenchIdsFilter($query, 'needs_mapping', true),
                        false: fn (Builder $query) => static::applyWorkbenchIdsFilter($query, 'needs_mapping', false),
                        blank: fn (Builder $query) => $query,
                    ),

                TernaryFilter::make('needs_review')
                    ->label('Требует разбора')
                    ->trueLabel('Только требующие разбора')
                    ->falseLabel('Только не требующие разбора')
                    ->queries(
                        true: fn (Builder $query) => static::applyWorkbenchIdsFilter($query, 'needs_review', true),
                        false: fn (Builder $query) => static::applyWorkbenchIdsFilter($query, 'needs_review', false),
                        blank: fn (Builder $query) => $query,
                    ),

                SelectFilter::make('space_mapping_mode')
                    ->label('Режим привязки')
                    ->options([
                        TenantContract::SPACE_MAPPING_MODE_AUTO => 'Авто',
                        TenantContract::SPACE_MAPPING_MODE_MANUAL => 'Ручная фиксация',
                        TenantContract::SPACE_MAPPING_MODE_EXCLUDED => 'Не участвует',
                    ]),
            ])
            ->defaultSort('id', 'desc')
            ->recordUrl(fn (TenantContract $record): ?string => static::canEdit($record)
                ? static::getUrl('edit', ['record' => $record])
                : null);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTenantContracts::route('/'),
            'edit' => Pages\EditTenantContract::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->with([
                'market:id,name',
                'tenant:id,name,short_name',
                'marketSpace:id,number,display_name',
                'spaceMappingUpdatedBy:id,name',
            ]);

        $user = Filament::auth()->user();

        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->isSuperAdmin()) {
            $selectedMarketId = static::selectedMarketIdFromSession();

            return filled($selectedMarketId)
                ? $query->where('market_id', (int) $selectedMarketId)
                : $query;
        }

        if ($user->hasAnyRole(['market-admin', 'market-manager']) && $user->market_id) {
            return $query->where('market_id', (int) $user->market_id);
        }

        return $query->whereRaw('1 = 0');
    }

    public static function canViewAny(): bool
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        return $user->hasAnyRole(['market-admin', 'market-manager'])
            && (bool) $user->market_id;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        $user = Filament::auth()->user();

        if (! $user || ! ($record instanceof TenantContract)) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        return $user->hasAnyRole(['market-admin', 'market-manager'])
            && $user->market_id
            && (int) $record->market_id === (int) $user->market_id;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    /**
     * @return array{
     *   category: string,
     *   label: string,
     *   actionable: bool,
     *   normalized: string,
     *   matched_rule: ?string,
     *   place_token: ?string,
     *   document_date: ?string
     * }
     */
    private static function classificationForRecord(?TenantContract $record): array
    {
        if (! $record) {
            return app(ContractDocumentClassifier::class)->classify('');
        }

        $cacheKey = implode(':', [
            (string) $record->getKey(),
            md5((string) ($record->number ?? '')),
        ]);

        if (! isset(static::$classificationCache[$cacheKey])) {
            static::$classificationCache[$cacheKey] = app(ContractDocumentClassifier::class)
                ->classify((string) ($record->number ?? ''));
        }

        return static::$classificationCache[$cacheKey];
    }

    /**
     * @return array{is_composite:bool,group_token:?string,group_segments:?string}
     */
    private static function spaceGroupMetaForRecord(?TenantContract $record): array
    {
        if (! $record) {
            return [
                'is_composite' => false,
                'group_token' => null,
                'group_segments' => null,
            ];
        }

        $cacheKey = implode(':', [
            (string) $record->getKey(),
            md5((string) ($record->number ?? '')),
        ]);

        if (! isset(static::$spaceGroupMetaCache[$cacheKey])) {
            static::$spaceGroupMetaCache[$cacheKey] = app(SpaceGroupResolver::class)
                ->forContractClassification(static::classificationForRecord($record));
        }

        return static::$spaceGroupMetaCache[$cacheKey];
    }

    /**
     * @return list<string>
     */
    private static function explodeGroupSegments(?string $segments): array
    {
        $segments = trim((string) $segments);
        if ($segments === '') {
            return [];
        }

        $items = preg_split('/\s*,\s*/u', $segments) ?: [];

        return array_values(array_filter(array_map(
            static fn (string $value): ?string => static::normalizeGroupSlot($value),
            $items,
        )));
    }

    private static function normalizeGroupSlot(?string $value): ?string
    {
        $slot = trim((string) $value);
        if ($slot === '') {
            return null;
        }

        $slot = preg_replace('/\s*([,-])\s*/u', '$1', $slot) ?? $slot;
        $slot = preg_replace('/\s+/u', ' ', $slot) ?? $slot;

        return $slot !== '' ? $slot : null;
    }

    /**
     * @return array{chain_count:int,chain_position:int,overlap_count:int}
     */
    private static function chainStatsFor(TenantContract $record): array
    {
        $marketId = (int) $record->market_id;
        static::warmChainStatsForMarket($marketId);

        return static::$chainStatsCache[$marketId][(int) $record->getKey()] ?? [
            'chain_count' => 0,
            'chain_position' => 0,
            'overlap_count' => 0,
        ];
    }

    private static function isInLatestDebtSnapshot(TenantContract $record): bool
    {
        $marketId = (int) $record->market_id;
        $externalId = trim((string) ($record->external_id ?? ''));

        if ($marketId <= 0 || $externalId === '') {
            return false;
        }

        static::warmLatestDebtContractIdsForMarket($marketId);

        return static::$latestDebtContractIdsCache[$marketId][$externalId] ?? false;
    }

    private static function isInLatestAccrualSnapshot(TenantContract $record): bool
    {
        $marketId = (int) $record->market_id;
        $contractId = (int) $record->getKey();

        if ($marketId <= 0 || $contractId <= 0) {
            return false;
        }

        static::warmLatestAccrualContractIdsForMarket($marketId);

        return static::$latestAccrualContractIdsCache[$marketId][$contractId] ?? false;
    }

    private static function isInDebtHistory(TenantContract $record): bool
    {
        $marketId = (int) $record->market_id;
        $externalId = trim((string) ($record->external_id ?? ''));

        if ($marketId <= 0 || $externalId === '') {
            return false;
        }

        static::warmDebtHistoryContractIdsForMarket($marketId);

        return static::$debtHistoryContractIdsCache[$marketId][$externalId] ?? false;
    }

    private static function isInAccrualHistory(TenantContract $record): bool
    {
        $marketId = (int) $record->market_id;
        $contractId = (int) $record->getKey();

        if ($marketId <= 0 || $contractId <= 0) {
            return false;
        }

        static::warmAccrualHistoryContractIdsForMarket($marketId);

        return static::$accrualHistoryContractIdsCache[$marketId][$contractId] ?? false;
    }

    private static function warmLatestDebtContractIdsForMarket(int $marketId): void
    {
        if ($marketId <= 0 || isset(static::$latestDebtContractIdsCache[$marketId])) {
            return;
        }

        $latestCalculatedAt = DB::table('contract_debts')
            ->where('market_id', $marketId)
            ->max('calculated_at');

        if (! $latestCalculatedAt) {
            static::$latestDebtContractIdsCache[$marketId] = [];

            return;
        }

        $externalIds = DB::table('contract_debts')
            ->where('market_id', $marketId)
            ->where('calculated_at', $latestCalculatedAt)
            ->whereNotNull('contract_external_id')
            ->pluck('contract_external_id')
            ->map(static fn (mixed $value): string => trim((string) $value))
            ->filter(static fn (string $value): bool => $value !== '')
            ->unique()
            ->values()
            ->all();

        static::$latestDebtContractIdsCache[$marketId] = array_fill_keys($externalIds, true);
    }

    private static function warmLatestAccrualContractIdsForMarket(int $marketId): void
    {
        if ($marketId <= 0 || isset(static::$latestAccrualContractIdsCache[$marketId])) {
            return;
        }

        $latestPeriod = DB::table('tenant_accruals')
            ->where('market_id', $marketId)
            ->max('period');

        if (! $latestPeriod) {
            static::$latestAccrualContractIdsCache[$marketId] = [];

            return;
        }

        $contractIds = DB::table('tenant_accruals')
            ->where('market_id', $marketId)
            ->where('period', $latestPeriod)
            ->whereNotNull('tenant_contract_id')
            ->pluck('tenant_contract_id')
            ->map(static fn (mixed $value): int => (int) $value)
            ->filter(static fn (int $value): bool => $value > 0)
            ->unique()
            ->values()
            ->all();

        static::$latestAccrualContractIdsCache[$marketId] = array_fill_keys($contractIds, true);
    }

    private static function warmDebtHistoryContractIdsForMarket(int $marketId): void
    {
        if ($marketId <= 0 || isset(static::$debtHistoryContractIdsCache[$marketId])) {
            return;
        }

        $externalIds = DB::table('contract_debts')
            ->where('market_id', $marketId)
            ->whereNotNull('contract_external_id')
            ->pluck('contract_external_id')
            ->map(static fn (mixed $value): string => trim((string) $value))
            ->filter(static fn (string $value): bool => $value !== '')
            ->unique()
            ->values()
            ->all();

        static::$debtHistoryContractIdsCache[$marketId] = array_fill_keys($externalIds, true);
    }

    private static function warmAccrualHistoryContractIdsForMarket(int $marketId): void
    {
        if ($marketId <= 0 || isset(static::$accrualHistoryContractIdsCache[$marketId])) {
            return;
        }

        $contractIds = DB::table('tenant_accruals')
            ->where('market_id', $marketId)
            ->whereNotNull('tenant_contract_id')
            ->pluck('tenant_contract_id')
            ->map(static fn (mixed $value): int => (int) $value)
            ->filter(static fn (int $value): bool => $value > 0)
            ->unique()
            ->values()
            ->all();

        static::$accrualHistoryContractIdsCache[$marketId] = array_fill_keys($contractIds, true);
    }

    private static function applyLatestDebtSnapshotFilter(Builder $query, bool $inLatestSnapshot): Builder
    {
        $method = $inLatestSnapshot ? 'whereExists' : 'whereNotExists';

        return $query->{$method}(static function ($subQuery): void {
            $subQuery
                ->selectRaw('1')
                ->from('contract_debts as cd')
                ->whereColumn('cd.market_id', 'tenant_contracts.market_id')
                ->whereColumn('cd.contract_external_id', 'tenant_contracts.external_id')
                ->whereRaw(
                    'cd.calculated_at = (
                        select max(cd2.calculated_at)
                        from contract_debts as cd2
                        where cd2.market_id = tenant_contracts.market_id
                    )'
                );
        });
    }

    private static function applyLatestAccrualSnapshotFilter(Builder $query, bool $inLatestSnapshot): Builder
    {
        $method = $inLatestSnapshot ? 'whereExists' : 'whereNotExists';

        return $query->{$method}(static function ($subQuery): void {
            $subQuery
                ->selectRaw('1')
                ->from('tenant_accruals as ta')
                ->whereColumn('ta.market_id', 'tenant_contracts.market_id')
                ->whereColumn('ta.tenant_contract_id', 'tenant_contracts.id')
                ->whereRaw(
                    'ta.period = (
                        select max(ta2.period)
                        from tenant_accruals as ta2
                        where ta2.market_id = tenant_contracts.market_id
                    )'
                );
        });
    }

    private static function applyDebtHistoryFilter(Builder $query, bool $inHistory): Builder
    {
        $method = $inHistory ? 'whereExists' : 'whereNotExists';

        return $query->{$method}(static function ($subQuery): void {
            $subQuery
                ->selectRaw('1')
                ->from('contract_debts as cd')
                ->whereColumn('cd.market_id', 'tenant_contracts.market_id')
                ->whereColumn('cd.contract_external_id', 'tenant_contracts.external_id');
        });
    }

    private static function applyAccrualHistoryFilter(Builder $query, bool $inHistory): Builder
    {
        $method = $inHistory ? 'whereExists' : 'whereNotExists';

        return $query->{$method}(static function ($subQuery): void {
            $subQuery
                ->selectRaw('1')
                ->from('tenant_accruals as ta')
                ->whereColumn('ta.market_id', 'tenant_contracts.market_id')
                ->whereColumn('ta.tenant_contract_id', 'tenant_contracts.id');
        });
    }

    public static function applyLatestDebtSnapshotScope(Builder $query, bool $inLatestSnapshot = true): Builder
    {
        return static::applyLatestDebtSnapshotFilter($query, $inLatestSnapshot);
    }

    public static function applyLatestAccrualSnapshotScope(Builder $query, bool $inLatestSnapshot = true): Builder
    {
        return static::applyLatestAccrualSnapshotFilter($query, $inLatestSnapshot);
    }

    public static function applyDebtHistoryScope(Builder $query, bool $inHistory = true): Builder
    {
        return static::applyDebtHistoryFilter($query, $inHistory);
    }

    public static function applyAccrualHistoryScope(Builder $query, bool $inHistory = true): Builder
    {
        return static::applyAccrualHistoryFilter($query, $inHistory);
    }

    public static function applyOperationalContractsScope(Builder $query, bool $onlyOperational = true): Builder
    {
        if (! $onlyOperational) {
            return $query;
        }

        return $query->where(function (Builder $query): void {
            static::applyDebtHistoryFilter($query, true)
                ->orWhere(function (Builder $query): void {
                    static::applyAccrualHistoryFilter($query, true);
                });
        });
    }

    /**
     * @return array<string, string>
     */
    private static function contractCategoryOptions(): array
    {
        return [
            'primary_contract' => 'Основной договор аренды',
            'supplemental_document' => 'Доп. соглашение',
            'service_document' => 'Служебный договорный документ',
            'penalty_document' => 'Пени / штрафы',
            'non_rent_document' => 'Неарендный документ',
            'placeholder_document' => 'Без договора',
            'unknown' => 'Не классифицировано',
        ];
    }

    private static function applyWorkbenchIdsFilter(Builder $query, string $bucket, bool $include): Builder
    {
        $ids = static::workbenchIdsFor(static::currentWorkbenchMarketId())[$bucket] ?? [];

        if ($ids === []) {
            return $include
                ? $query->whereRaw('1 = 0')
                : $query;
        }

        return $include
            ? $query->whereIn('tenant_contracts.id', $ids)
            : $query->whereNotIn('tenant_contracts.id', $ids);
    }

    public static function applyWorkbenchBucketScope(Builder $query, string $bucket, bool $include = true): Builder
    {
        return static::applyWorkbenchIdsFilter($query, $bucket, $include);
    }

    private static function currentWorkbenchMarketId(): ?int
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return null;
        }

        if ($user->isSuperAdmin()) {
            $selectedMarketId = static::selectedMarketIdFromSession();

            return filled($selectedMarketId)
                ? (int) $selectedMarketId
                : null;
        }

        return $user->market_id
            ? (int) $user->market_id
            : null;
    }

    /**
     * @return array<string, list<int>>
     */
    private static function workbenchIdsFor(?int $marketId): array
    {
        $cacheKey = $marketId !== null ? 'market:' . $marketId : 'all';

        if (isset(static::$workbenchIdsCache[$cacheKey])) {
            return static::$workbenchIdsCache[$cacheKey];
        }

        $buckets = [
            'operational' => [],
            'primary_contract' => [],
            'supplemental_document' => [],
            'service_document' => [],
            'penalty_document' => [],
            'non_rent_document' => [],
            'placeholder_document' => [],
            'unknown' => [],
            'has_place_token' => [],
            'has_document_date' => [],
            'has_chain' => [],
            'has_overlap' => [],
            'needs_mapping' => [],
            'needs_review' => [],
        ];

        $query = TenantContract::query();
        if ($marketId !== null) {
            $query->where('market_id', $marketId);
        }

        $records = $query->get([
            'id',
            'market_id',
            'market_space_id',
            'space_mapping_mode',
            'number',
            'starts_at',
            'ends_at',
            'signed_at',
            'status',
            'is_active',
        ]);

        $marketIds = $records
            ->pluck('market_id')
            ->filter()
            ->map(static fn (mixed $value): int => (int) $value)
            ->unique()
            ->values()
            ->all();

        foreach ($marketIds as $currentMarketId) {
            static::warmChainStatsForMarket($currentMarketId);
            static::warmLatestDebtContractIdsForMarket($currentMarketId);
            static::warmLatestAccrualContractIdsForMarket($currentMarketId);
            static::warmDebtHistoryContractIdsForMarket($currentMarketId);
            static::warmAccrualHistoryContractIdsForMarket($currentMarketId);
        }

        foreach ($records as $record) {
            $recordId = (int) $record->getKey();
            $classified = static::classificationForRecord($record);
            $category = (string) ($classified['category'] ?? 'unknown');
            $hasPlaceToken = filled($classified['place_token'] ?? null);
            $hasDocumentDate = filled($classified['document_date'] ?? null);
            $actionable = (bool) ($classified['actionable'] ?? false);
            $excluded = $record->excludesFromSpaceMapping();
            $hasSpace = filled($record->market_space_id);
            $stats = static::chainStatsFor($record);
            $isOperational = static::isInDebtHistory($record) || static::isInAccrualHistory($record);

            if ($isOperational) {
                $buckets['operational'][] = $recordId;
            }

            if (array_key_exists($category, $buckets)) {
                $buckets[$category][] = $recordId;
            }

            if ($hasPlaceToken) {
                $buckets['has_place_token'][] = $recordId;
            }

            if ($hasDocumentDate) {
                $buckets['has_document_date'][] = $recordId;
            }

            if ($isOperational && $stats['chain_count'] > 1) {
                $buckets['has_chain'][] = $recordId;
            }

            if ($isOperational && $stats['overlap_count'] > 0) {
                $buckets['has_overlap'][] = $recordId;
            }

            if ($isOperational && $category === 'primary_contract' && ! $excluded && $hasPlaceToken && $hasDocumentDate && ! $hasSpace) {
                $buckets['needs_mapping'][] = $recordId;
            }

            if ($isOperational && $actionable && ! $excluded && (! $hasPlaceToken || ! $hasDocumentDate)) {
                $buckets['needs_review'][] = $recordId;
            }
        }

        static::$workbenchIdsCache[$cacheKey] = $buckets;

        return static::$workbenchIdsCache[$cacheKey];
    }

    private static function warmChainStatsForMarket(int $marketId): void
    {
        if ($marketId <= 0 || isset(static::$chainStatsCache[$marketId])) {
            return;
        }

        $records = TenantContract::query()
            ->where('market_id', $marketId)
            ->get(['id', 'market_id', 'number', 'starts_at', 'ends_at', 'signed_at', 'status', 'is_active']);

        $grouped = [];
        foreach ($records as $contract) {
            $classified = static::classificationForRecord($contract);
            $token = (string) ($classified['place_token'] ?? '');

            if ($contract->excludesFromSpaceMapping() || ! ($classified['actionable'] ?? false) || $token === '') {
                continue;
            }

            $grouped[$token][] = [
                'id' => (int) $contract->id,
                'order_date' => static::resolveOrderDate($contract, $classified['document_date'] ?? null),
                'range_start' => static::resolveRangeStart($contract, $classified['document_date'] ?? null),
                'range_end' => static::resolveRangeEnd($contract),
            ];
        }

        $stats = [];
        foreach ($grouped as $items) {
            usort($items, static function (array $left, array $right): int {
                $dateCompare = strcmp($left['order_date'], $right['order_date']);
                if ($dateCompare !== 0) {
                    return $dateCompare;
                }

                return $left['id'] <=> $right['id'];
            });

            $count = count($items);
            foreach ($items as $index => $item) {
                $overlapCount = 0;
                foreach ($items as $other) {
                    if ($other['id'] === $item['id']) {
                        continue;
                    }

                    if (static::rangesOverlap($item['range_start'], $item['range_end'], $other['range_start'], $other['range_end'])) {
                        $overlapCount++;
                    }
                }

                $stats[(int) $item['id']] = [
                    'chain_count' => $count,
                    'chain_position' => $index + 1,
                    'overlap_count' => $overlapCount,
                ];
            }
        }

        static::$chainStatsCache[$marketId] = $stats;
    }

    /**
     * @return array{label: string, color: string}
     */
    private static function orderingMeta(TenantContract $record): array
    {
        $classified = static::classificationForRecord($record);
        $hasToken = filled($classified['place_token'] ?? null);
        $hasDate = filled($classified['document_date'] ?? null);
        $hasSpace = filled($record->market_space_id);

        if ($record->excludesFromSpaceMapping()) {
            return [
                'label' => 'Исключен из привязки',
                'color' => 'gray',
            ];
        }

        if (! (bool) ($classified['actionable'] ?? false)) {
            return [
                'label' => 'Вне цепочки',
                'color' => 'gray',
            ];
        }

        if ($hasToken && $hasDate && $hasSpace) {
            return [
                'label' => 'Готов',
                'color' => 'success',
            ];
        }

        if ($hasToken && $hasDate) {
            return [
                'label' => 'Ждёт привязки',
                'color' => 'warning',
            ];
        }

        if ($hasToken || $hasDate) {
            return [
                'label' => 'Частично',
                'color' => 'warning',
            ];
        }

        return [
            'label' => 'Нужно разобрать',
            'color' => 'danger',
        ];
    }

    private static function documentTypeColor(string $category): string
    {
        return match ($category) {
            'primary_contract' => 'success',
            'supplemental_document', 'service_document' => 'warning',
            'penalty_document', 'non_rent_document' => 'danger',
            'placeholder_document' => 'gray',
            default => 'gray',
        };
    }

    private static function formatClassifierDate(?string $value): string
    {
        if (! filled($value)) {
            return '—';
        }

        if (preg_match('/^(?<y>\d{4})-(?<m>\d{2})-(?<d>\d{2})$/', (string) $value, $matches) === 1) {
            return "{$matches['d']}.{$matches['m']}.{$matches['y']}";
        }

        return (string) $value;
    }

    private static function effectiveOrderDateLabel(TenantContract $record): string
    {
        $classified = static::classificationForRecord($record);

        return static::formatClassifierDate(
            static::resolveOrderDate($record, $classified['document_date'] ?? null)
        );
    }

    private static function dateConsistencyLabel(TenantContract $record): string
    {
        $classifierDate = (string) (static::classificationForRecord($record)['document_date'] ?? '');
        $signedAt = $record->signed_at?->format('Y-m-d');
        $startsAt = $record->starts_at?->format('Y-m-d');

        if ($classifierDate === '') {
            return 'Нет даты';
        }

        if ($signedAt === $classifierDate) {
            return 'Совпадает';
        }

        if ($signedAt !== null && $signedAt !== '') {
            return 'Расходится';
        }

        if ($startsAt === $classifierDate) {
            return 'Совпадает с тех. датой 1С';
        }

        if ($startsAt !== null && $startsAt !== '') {
            return 'Только тех. дата 1С';
        }

        return 'Только в номере';
    }

    private static function dateConsistencyColor(TenantContract $record): string
    {
        return match (static::dateConsistencyLabel($record)) {
            'Совпадает' => 'success',
            'Только в номере', 'Совпадает с тех. датой 1С', 'Только тех. дата 1С' => 'warning',
            'Нет даты' => 'gray',
            default => 'danger',
        };
    }

    private static function contractStatusLabel(?string $state): string
    {
        return match (trim((string) $state)) {
            '' => '—',
            'draft' => 'Черновик',
            'active' => 'Активен',
            'paused' => 'Приостановлен',
            'terminated' => 'Расторгнут',
            'archived' => 'Архив',
            default => (string) $state,
        };
    }

    private static function contractStatusColor(?string $state): string
    {
        return match (trim((string) $state)) {
            'active' => 'success',
            'paused' => 'warning',
            'terminated' => 'danger',
            'archived' => 'gray',
            default => 'gray',
        };
    }

    private static function spaceMappingModeLabel(?string $state): string
    {
        return match (trim((string) $state)) {
            TenantContract::SPACE_MAPPING_MODE_MANUAL => 'Ручная',
            TenantContract::SPACE_MAPPING_MODE_EXCLUDED => 'Не участвует',
            default => 'Авто',
        };
    }

    private static function spaceMappingModeColor(?string $state): string
    {
        return match (trim((string) $state)) {
            TenantContract::SPACE_MAPPING_MODE_MANUAL => 'warning',
            TenantContract::SPACE_MAPPING_MODE_EXCLUDED => 'gray',
            default => 'gray',
        };
    }

    private static function spaceMappingAuditLabel(?TenantContract $record): string
    {
        if (! $record) {
            return '—';
        }

        if (! $record->space_mapping_updated_at) {
            if ($record->usesManualSpaceMapping()) {
                return 'Ручная фиксация без истории';
            }

            if ($record->excludesFromSpaceMapping()) {
                return 'Исключен из привязки без истории';
            }

            return 'История локальной фиксации отсутствует';
        }

        $parts = [$record->space_mapping_updated_at->format('d.m.Y H:i')];

        $userName = trim((string) ($record->spaceMappingUpdatedBy?->name ?? ''));
        if ($userName !== '') {
            $parts[] = $userName;
        }

        return implode(' · ', $parts);
    }

    private static function spaceLabel(TenantContract $record): string
    {
        $displayName = trim((string) ($record->marketSpace?->display_name ?? ''));
        $number = trim((string) ($record->marketSpace?->number ?? ''));

        if ($displayName !== '' && $number !== '' && $displayName !== $number) {
            return $displayName . ' · ' . $number;
        }

        if ($displayName !== '') {
            return $displayName;
        }

        if ($number !== '') {
            return $number;
        }

        return '—';
    }

    private static function tenantLabel(?TenantContract $record): string
    {
        if (! $record) {
            return '—';
        }

        $short = trim((string) ($record->tenant?->short_name ?? ''));
        $name = trim((string) ($record->tenant?->name ?? ''));

        if ($short !== '' && $name !== '' && $short !== $name) {
            return $short . ' · ' . $name;
        }

        return $short !== '' ? $short : ($name !== '' ? $name : '—');
    }

    private static function historyChainPreview(?TenantContract $record): string
    {
        if (! $record) {
            return 'Нет данных.';
        }

        $classified = static::classificationForRecord($record);
        $token = trim((string) ($classified['place_token'] ?? ''));

        if ($record->excludesFromSpaceMapping()) {
            return 'Договор явно исключен из привязки к месту и не участвует в исторической цепочке по месту.';
        }

        if (! ($classified['actionable'] ?? false) || $token === '') {
            return 'Для этого документа цепочка по месту не строится: нет надёжного токена места.';
        }

        $contracts = TenantContract::query()
            ->where('market_id', (int) $record->market_id)
            ->with(['tenant:id,name,short_name', 'marketSpace:id,number,display_name'])
            ->get(['id', 'market_id', 'tenant_id', 'market_space_id', 'number', 'starts_at', 'ends_at', 'signed_at', 'status', 'is_active', 'external_id']);

        $items = [];

        foreach ($contracts as $candidate) {
            $candidateClassification = static::classificationForRecord($candidate);
            if ($candidate->excludesFromSpaceMapping()) {
                continue;
            }

            if (! ($candidateClassification['actionable'] ?? false)) {
                continue;
            }

            if (trim((string) ($candidateClassification['place_token'] ?? '')) !== $token) {
                continue;
            }

            $items[] = [
                'record' => $candidate,
                'document_date' => $candidateClassification['document_date'] ?? null,
                'order_date' => static::resolveOrderDate($candidate, $candidateClassification['document_date'] ?? null),
            ];
        }

        usort($items, static function (array $left, array $right): int {
            $dateCompare = strcmp((string) $left['order_date'], (string) $right['order_date']);
            if ($dateCompare !== 0) {
                return $dateCompare;
            }

            /** @var TenantContract $leftRecord */
            $leftRecord = $left['record'];
            /** @var TenantContract $rightRecord */
            $rightRecord = $right['record'];

            return (int) $leftRecord->id <=> (int) $rightRecord->id;
        });

        if ($items === []) {
            return 'Цепочка не найдена.';
        }

        $lines = [];

        foreach ($items as $index => $item) {
            /** @var TenantContract $chainRecord */
            $chainRecord = $item['record'];

            $parts = [];
            $parts[] = sprintf('%d.', $index + 1);
            $parts[] = static::formatClassifierDate($item['document_date']);
            $parts[] = trim((string) ($chainRecord->number ?? '')) !== '' ? (string) $chainRecord->number : 'Без номера';

            $tenantName = trim((string) ($chainRecord->tenant?->display_name ?? $chainRecord->tenant?->name ?? ''));
            if ($tenantName !== '') {
                $parts[] = '• ' . $tenantName;
            }

            if ($chainRecord->market_space_id) {
                $parts[] = '• место: ' . static::spaceLabel($chainRecord);
            }

            if (static::isInLatestDebtSnapshot($chainRecord)) {
                $parts[] = '• в последней задолженности';
            }

            if ((int) $chainRecord->id === (int) $record->id) {
                $parts[] = '• текущий';
            }

            $lines[] = implode(' ', array_filter($parts, static fn (string $part): bool => trim($part) !== ''));
        }

        return implode(PHP_EOL, $lines);
    }

    private static function chainDisplay(TenantContract $record): string
    {
        $stats = static::chainStatsFor($record);

        if ($stats['chain_count'] <= 0) {
            return '—';
        }

        return $stats['chain_position'] . '/' . $stats['chain_count'];
    }

    private static function chainColor(TenantContract $record): string
    {
        $stats = static::chainStatsFor($record);

        if ($stats['chain_count'] <= 0) {
            return 'gray';
        }

        return $stats['chain_count'] > 1 ? 'warning' : 'success';
    }

    private static function overlapDisplay(TenantContract $record): string
    {
        $stats = static::chainStatsFor($record);

        if ($stats['chain_count'] <= 0) {
            return '—';
        }

        return $stats['overlap_count'] > 0 ? 'Есть' : 'Нет';
    }

    private static function overlapColor(TenantContract $record): string
    {
        $stats = static::chainStatsFor($record);

        if ($stats['chain_count'] <= 0) {
            return 'gray';
        }

        return $stats['overlap_count'] > 0 ? 'danger' : 'success';
    }

    private static function resolveOrderDate(TenantContract $record, ?string $documentDate): string
    {
        $resolved = static::resolveRangeStart($record, $documentDate);

        return $resolved ?? sprintf('9999-12-31:%010d', (int) $record->id);
    }

    private static function resolveRangeStart(TenantContract $record, ?string $documentDate): ?string
    {
        if (filled($documentDate)) {
            return (string) $documentDate;
        }

        if ($record->signed_at instanceof Carbon) {
            return $record->signed_at->format('Y-m-d');
        }

        return null;
    }

    private static function resolveRangeEnd(TenantContract $record): ?string
    {
        if ($record->ends_at instanceof Carbon) {
            return $record->ends_at->format('Y-m-d');
        }

        return null;
    }

    private static function rangesOverlap(?string $leftStart, ?string $leftEnd, ?string $rightStart, ?string $rightEnd): bool
    {
        if (! filled($leftStart) || ! filled($rightStart)) {
            return false;
        }

        $leftEnd = filled($leftEnd) ? (string) $leftEnd : '9999-12-31';
        $rightEnd = filled($rightEnd) ? (string) $rightEnd : '9999-12-31';

        return max((string) $leftStart, (string) $rightStart) <= min($leftEnd, $rightEnd);
    }

    private static function spaceOptionLabel(
        ?string $displayName,
        ?string $number,
        ?string $code,
        ?string $groupToken = null,
        ?string $groupSlot = null
    ): string
    {
        $parts = array_values(array_filter([
            trim((string) $displayName),
            trim((string) $number),
            trim((string) $code),
        ], static fn (string $value): bool => $value !== ''));

        $normalizedGroupToken = trim((string) $groupToken);
        $normalizedGroupSlot = trim((string) $groupSlot);

        if ($normalizedGroupToken !== '' || $normalizedGroupSlot !== '') {
            $groupLabel = trim($normalizedGroupToken . ($normalizedGroupSlot !== '' ? ' / ' . $normalizedGroupSlot : ''));
            if ($groupLabel !== '') {
                $parts[] = 'Группа ' . $groupLabel;
            }
        }

        if ($parts === []) {
            return 'Без названия';
        }

        return implode(' · ', array_values(array_unique($parts)));
    }
}
