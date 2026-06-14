<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\TenantContractResource\Pages;
use App\Models\ContractDebt;
use App\Models\MarketSpace;
use App\Models\TenantContract;
use App\Services\MarketSpaces\SpaceGroupResolver;
use App\Services\TenantContracts\ContractDocumentClassifier;
use App\Support\AdminCapabilities;
use App\Support\MarketSpaces\MarketSpaceGroupEpisodeResolver;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Components\Placeholder;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema as DbSchema;
use Illuminate\Support\HtmlString;
use Throwable;

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

    /** @var array<int, array<string, string>> */
    private static array $latestDebtOrganizationsCache = [];

    /** @var array<int, array<string, string>> */
    private static array $latestDebtAccountsCache = [];

    /** @var array<string, array<string, list<int>>> */
    private static array $workbenchIdsCache = [];

    /** @var array<string, list<string>> */
    private static array $tableColumnsCache = [];

    public static function shouldRegisterNavigation(): bool
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        return AdminCapabilities::canViewTenantContracts($user);
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

    protected static function canViewTechnicalFields(): bool
    {
        $user = Filament::auth()->user();

        return (bool) $user
            && method_exists($user, 'isSuperAdmin')
            && $user->isSuperAdmin();
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

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        /** @var TenantContract $record */
        $spaceLabel = trim((string) ($record->marketSpace?->number ?? ''));
        $spaceName = trim((string) ($record->marketSpace?->display_name ?? ''));

        return static::compactGlobalSearchTitle(
            trim((string) ($record->number ?? '')),
            $spaceLabel !== '' ? $spaceLabel : $spaceName,
            'Договор'
        );
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        /** @var TenantContract $record */
        $spaceLabel = trim((string) ($record->marketSpace?->number ?? ''));

        if ($spaceLabel === '') {
            $spaceLabel = trim((string) ($record->marketSpace?->display_name ?? ''));
        }

        return static::compactGlobalSearchDetails([
            'Арендатор' => static::tenantLabel($record),
            'Место' => $spaceLabel,
            'Статус' => static::contractStatusLabel($record->status),
            'Начало' => $record->starts_at instanceof Carbon ? $record->starts_at->format('d.m.Y') : '',
        ]);
    }

    public static function form(Schema $schema): Schema
    {
        $tabs = Tabs::make('tenant_contract_tabs')
            ->columnSpanFull();

        if (method_exists($tabs, 'persistTabInQueryString')) {
            $tabs->persistTabInQueryString();
        }

        return $schema->components([
            $tabs->tabs([
                Tab::make('Сводка')
                    ->schema([
                        Section::make('Паспорт договора')
                            ->description('Ключевые признаки договора, связь с местом и свежесть данных 1С.')
                            ->schema([
                                Placeholder::make('contract_card_overview')
                                    ->hiddenLabel()
                                    ->content(fn (?TenantContract $record): HtmlString => static::renderContractCardOverview($record))
                                    ->columnSpanFull(),
                            ])
                            ->columns(1)
                            ->columnSpanFull(),
                    ]),

                Tab::make('Финансы 1С')
                    ->schema([
                        Section::make('Финансы по договору')
                            ->description('ОСВ, начисления и оплаты, которые 1С передала по этому договору.')
                            ->schema([
                                Placeholder::make('contract_finance_1c')
                                    ->hiddenLabel()
                                    ->content(fn (?TenantContract $record): HtmlString => static::renderContractFinance1C($record))
                                    ->columnSpanFull(),
                            ])
                            ->columns(1)
                            ->columnSpanFull(),
                    ]),

                Tab::make('1С')
                    ->schema([
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
                                    ->visible(fn (): bool => static::canViewTechnicalFields())
                                    ->disabled()
                                    ->dehydrated(false),

                                Forms\Components\TextInput::make('space_group_display')
                                    ->label('Группа мест')
                                    ->formatStateUsing(fn (?TenantContract $record): string => (string) (static::spaceGroupMetaForRecord($record)['group_token'] ?: '—'))
                                    ->visible(fn (): bool => static::canViewTechnicalFields())
                                    ->disabled()
                                    ->dehydrated(false),

                                Forms\Components\TextInput::make('space_group_segments_display')
                                    ->label('Сегменты в группе')
                                    ->formatStateUsing(fn (?TenantContract $record): string => (string) (static::spaceGroupMetaForRecord($record)['group_segments'] ?: '—'))
                                    ->visible(fn (): bool => static::canViewTechnicalFields())
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
                                    ->hintIcon('heroicon-m-question-mark-circle')
                                    ->hintIconTooltip('Не используется как основная дата договора. Для истории приоритет у даты из номера договора.')
                                    ->formatStateUsing(fn (?TenantContract $record): string => $record?->starts_at?->format('d.m.Y') ?: '—')
                                    ->visible(fn (): bool => static::canViewTechnicalFields())
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
                                    ->visible(fn (): bool => static::canViewTechnicalFields())
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
                                    ->label('Документов по этому месту')
                                    ->formatStateUsing(fn (?TenantContract $record): string => $record ? static::chainDisplay($record) : '—')
                                    ->hintIcon('heroicon-m-question-mark-circle')
                                    ->hintIconTooltip('Сколько договоров найдено по тому же токену места из номера договора. Формат 1/2 означает: текущий договор первый из двух в истории этого места.')
                                    ->disabled()
                                    ->dehydrated(false),

                                Forms\Components\TextInput::make('overlap_display')
                                    ->label('Пересечения периодов')
                                    ->formatStateUsing(fn (?TenantContract $record): string => $record ? static::overlapDisplay($record) : '—')
                                    ->hintIcon('heroicon-m-question-mark-circle')
                                    ->hintIconTooltip('Показывает, есть ли у договоров по этому месту пересекающиеся периоды действия. Если есть пересечение, историю нужно проверить вручную.')
                                    ->disabled()
                                    ->dehydrated(false),
                            ])
                            ->columns(2),

                    ]),

                Tab::make('Привязка')
                    ->schema([
                        Section::make('Связь с местом')
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
                                    ->visible(fn (): bool => static::hasTenantContractColumn('space_mapping_mode'))
                                    ->hintIcon('heroicon-m-question-mark-circle')
                                    ->hintIconTooltip('При изменении места режим станет ручным автоматически. В авто-режиме 1С может перезаписать привязку. Режим "Не участвует" исключает договор из привязки и очищает место.'),

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
                                    ->hintIcon('heroicon-m-question-mark-circle')
                                    ->hintIconTooltip('Здесь задаётся только локальная привязка договора к торговому месту. Список можно ограничить арендатором договора и, если договор составной, его группой мест.'),

                                Forms\Components\Checkbox::make('limit_spaces_to_contract_tenant')
                                    ->label('Только места этого арендатора')
                                    ->default(true)
                                    ->live()
                                    ->dehydrated(false)
                                    ->hintIcon('heroicon-m-question-mark-circle')
                                    ->hintIconTooltip('Фильтр списка "Торговое место". По умолчанию список ограничен арендатором договора. Снимите галочку, если раньше у этого места был другой арендатор и нужен поиск по всему рынку.'),

                                Forms\Components\Checkbox::make('limit_spaces_to_place_group')
                                    ->label('Только места этой группы')
                                    ->default(true)
                                    ->live()
                                    ->dehydrated(false)
                                    ->visible(fn (?TenantContract $record): bool => filled(static::spaceGroupMetaForRecord($record)['group_token'] ?? null))
                                    ->hintIcon('heroicon-m-question-mark-circle')
                                    ->hintIconTooltip('Фильтр списка "Торговое место". Если система распознала группу мест из номера договора, список будет ограничен этой группой. Снимите галочку, если нужно искать место по всему рынку.'),

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

                    ]),

                Tab::make('История')
                    ->schema([
                        Section::make('Состав группы на дату договора')
                            ->description('Показывает, из каких физических мест состояла группа на дату документа. Если исторический эпизод ещё не заведен, показывается текущий состав с предупреждением.')
                            ->schema([
                                Placeholder::make('group_episode_display')
                                    ->hiddenLabel()
                                    ->content(fn (?TenantContract $record): HtmlString => static::groupEpisodePreview($record))
                                    ->columnSpanFull(),
                            ])
                            ->columns(1),

                        Section::make('Историческая цепочка по месту')
                            ->description('Показывает документы по тому же токену места в порядке даты из номера договора. Именно эта дата считается основной для истории.')
                            ->schema([
                                Placeholder::make('history_chain_display')
                                    ->label('Договоры по этому месту')
                                    ->content(fn (?TenantContract $record): HtmlString => static::historyChainPreview($record))
                                    ->columnSpanFull(),
                            ])
                            ->columns(1),
                    ]),
            ]),
        ]);
    }

    private static function renderContractCardOverview(?TenantContract $record): HtmlString
    {
        if (! $record) {
            return new HtmlString('<div class="text-sm text-gray-500">Данные появятся после сохранения договора.</div>');
        }

        $classified = static::classificationForRecord($record);
        $debtSummary = static::latestDebtSummaryForRecord($record);
        $accrualSummary = static::latestAccrualSummaryForRecord($record);

        $number = trim((string) ($record->number ?? ''));
        $documentTitle = $number !== '' ? $number : ('Договор #'.(int) $record->id);
        $externalId = trim((string) ($record->external_id ?? ''));
        $tenantLabel = static::tenantLabel($record);
        $tenantUrl = $record->tenant_id ? TenantResource::getUrl('edit', ['record' => (int) $record->tenant_id]) : null;
        $spaceInfo = static::contractSpaceOverview($record);
        $spaceUrl = $record->market_space_id ? MarketSpaceResource::getUrl('edit', ['record' => (int) $record->market_space_id]) : null;
        $movementStatus = static::oneCMovementStatus($record);
        $movementLabel = static::oneCMovementLabel($record);

        $movementClass = match ($movementStatus) {
            'fresh' => 'tenant-contract-card__chip--success',
            'stale' => 'tenant-contract-card__chip--warning',
            default => 'tenant-contract-card__chip--muted',
        };
        $statusClass = match (trim((string) $record->status)) {
            'active' => 'tenant-contract-card__chip--success',
            'paused' => 'tenant-contract-card__chip--warning',
            'terminated' => 'tenant-contract-card__chip--danger',
            default => 'tenant-contract-card__chip--muted',
        };
        $mappingClass = match (trim((string) $record->space_mapping_mode)) {
            TenantContract::SPACE_MAPPING_MODE_MANUAL => 'tenant-contract-card__chip--warning',
            TenantContract::SPACE_MAPPING_MODE_EXCLUDED => 'tenant-contract-card__chip--muted',
            default => 'tenant-contract-card__chip--neutral',
        };
        $documentClass = static::documentTypeColor((string) ($classified['category'] ?? ''));
        $documentChipClass = match ($documentClass) {
            'success' => 'tenant-contract-card__chip--success',
            'warning' => 'tenant-contract-card__chip--warning',
            'danger' => 'tenant-contract-card__chip--danger',
            default => 'tenant-contract-card__chip--muted',
        };

        $debtAmount = $debtSummary['debt'] ?? null;
        $debtClass = is_numeric($debtAmount) && (float) $debtAmount > 0.009
            ? 'tenant-contract-card__metric-value--debt'
            : (is_numeric($debtAmount) && (float) $debtAmount < -0.009 ? 'tenant-contract-card__metric-value--credit' : '');
        $debtLabel = is_numeric($debtAmount) && (float) $debtAmount < -0.009
            ? 'Переплата '.static::formatRubForContractCard(abs((float) $debtAmount))
            : (is_numeric($debtAmount) ? static::formatRubForContractCard((float) $debtAmount) : '—');

        $tenantHtml = $tenantUrl
            ? '<a class="tenant-contract-card__link" href="'.e($tenantUrl).'">'.e($tenantLabel).'</a>'
            : e($tenantLabel);
        $spaceHtml = $spaceUrl
            ? '<a class="tenant-contract-card__link" href="'.e($spaceUrl).'">'.e($spaceInfo['label']).'</a>'
            : e($spaceInfo['label']);

        $facts = [
            ['label' => 'Арендатор', 'value' => $tenantHtml],
            ['label' => 'Место', 'value' => $spaceHtml.($spaceInfo['parent_label'] !== null ? '<div class="tenant-contract-card__subtext">Основное место: '.e($spaceInfo['parent_label']).'</div>' : '')],
            ['label' => 'Код 1С', 'value' => e($externalId !== '' ? $externalId : '—')],
            ['label' => 'Период', 'value' => e(static::contractDateRangeLabel($record, $classified))],
        ];

        $factsHtml = '';
        foreach ($facts as $fact) {
            $factsHtml .= '<div class="tenant-contract-card__fact">'
                .'<div class="tenant-contract-card__fact-label">'.e($fact['label']).'</div>'
                .'<div class="tenant-contract-card__fact-value">'.$fact['value'].'</div>'
                .'</div>';
        }

        $metrics = [
            ['label' => 'Начислено 1С', 'value' => is_numeric($debtSummary['accrued'] ?? null) ? static::formatRubForContractCard((float) $debtSummary['accrued']) : '—', 'class' => ''],
            ['label' => 'Оплачено 1С', 'value' => is_numeric($debtSummary['paid'] ?? null) ? static::formatRubForContractCard((float) $debtSummary['paid']) : '—', 'class' => ''],
            ['label' => 'Долг / переплата', 'value' => $debtLabel, 'class' => $debtClass],
            ['label' => 'Последнее начисление', 'value' => static::latestAccrualMetricLabel($accrualSummary), 'class' => ''],
        ];

        $metricsHtml = '';
        foreach ($metrics as $metric) {
            $metricsHtml .= '<div class="tenant-contract-card__metric">'
                .'<div class="tenant-contract-card__metric-label">'.e($metric['label']).'</div>'
                .'<div class="tenant-contract-card__metric-value '.e($metric['class']).'">'.e($metric['value']).'</div>'
                .'</div>';
        }

        $snapshotParts = array_filter([
            $debtSummary['snapshot_label'] ?? null ? 'долг: '.$debtSummary['snapshot_label'] : null,
            $accrualSummary['period_label'] ?? null ? 'начисления: '.$accrualSummary['period_label'] : null,
            $debtSummary['organization_label'] ?? null ? 'организация: '.$debtSummary['organization_label'] : null,
            $debtSummary['account_label'] ?? null ? 'счёт: '.$debtSummary['account_label'] : null,
        ]);

        return new HtmlString(
            '<style>
                .tenant-contract-card{display:grid;gap:14px}
                .tenant-contract-card__top{display:grid;grid-template-columns:minmax(260px,.9fr) minmax(420px,1.6fr);gap:18px;align-items:start}
                .tenant-contract-card__eyebrow{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0;color:#64748b}
                .tenant-contract-card__title{margin-top:6px;font-size:20px;font-weight:750;line-height:1.25;color:#0f172a;overflow-wrap:anywhere}
                .dark .tenant-contract-card__title{color:#f8fafc}
                .tenant-contract-card__chips{display:flex;flex-wrap:wrap;gap:8px;margin-top:12px}
                .tenant-contract-card__chip{display:inline-flex;align-items:center;border-radius:999px;border:1px solid transparent;padding:4px 9px;font-size:12px;font-weight:700;line-height:1.2}
                .tenant-contract-card__chip--success{border-color:#bbf7d0;background:#dcfce7;color:#166534}
                .tenant-contract-card__chip--warning{border-color:#fde68a;background:#fef3c7;color:#92400e}
                .tenant-contract-card__chip--danger{border-color:#fecaca;background:#fee2e2;color:#991b1b}
                .tenant-contract-card__chip--neutral{border-color:#bfdbfe;background:#dbeafe;color:#1e40af}
                .tenant-contract-card__chip--muted{border-color:#e2e8f0;background:#f1f5f9;color:#475569}
                .tenant-contract-card__body{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}
                .tenant-contract-card__fact{min-width:0;border:1px solid rgba(148,163,184,.24);border-radius:8px;padding:10px;background:#fff}
                .dark .tenant-contract-card__fact{background:rgba(255,255,255,.04);border-color:rgba(148,163,184,.2)}
                .tenant-contract-card__fact-label,.tenant-contract-card__metric-label{font-size:12px;color:#64748b;line-height:1.2}
                .tenant-contract-card__fact-value{margin-top:4px;font-size:14px;font-weight:650;color:#0f172a;line-height:1.35;overflow-wrap:anywhere}
                .dark .tenant-contract-card__fact-value{color:#e5e7eb}
                .tenant-contract-card__link{color:#1d4ed8;text-decoration:underline;text-underline-offset:2px}
                .dark .tenant-contract-card__link{color:#93c5fd}
                .tenant-contract-card__subtext{margin-top:3px;font-size:12px;font-weight:500;color:#64748b}
                .tenant-contract-card__metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}
                .tenant-contract-card__metric{min-width:0;border:1px solid rgba(148,163,184,.25);border-radius:8px;padding:10px;background:#f8fafc}
                .dark .tenant-contract-card__metric{background:rgba(255,255,255,.04);border-color:rgba(148,163,184,.2)}
                .tenant-contract-card__metric-value{margin-top:5px;font-size:17px;font-weight:750;line-height:1.2;color:#0f172a;overflow-wrap:anywhere}
                .dark .tenant-contract-card__metric-value{color:#f8fafc}
                .tenant-contract-card__metric-value--debt{color:#b91c1c}
                .tenant-contract-card__metric-value--credit{color:#15803d}
                .tenant-contract-card__snapshot{font-size:12px;line-height:1.45;color:#64748b}
                @media (max-width:1200px){.tenant-contract-card__top{grid-template-columns:1fr}.tenant-contract-card__metrics,.tenant-contract-card__body{grid-template-columns:repeat(2,minmax(0,1fr))}}
                @media (max-width:700px){.tenant-contract-card__metrics,.tenant-contract-card__body{grid-template-columns:1fr}}
            </style>
            <div class="tenant-contract-card">
                <div class="tenant-contract-card__top">
                    <div>
                        <div class="tenant-contract-card__eyebrow">Договор 1С</div>
                        <div class="tenant-contract-card__title">'.e($documentTitle).'</div>
                        <div class="tenant-contract-card__chips">
                            <span class="tenant-contract-card__chip '.e($documentChipClass).'">'.e((string) ($classified['label'] ?? 'Договор')).'</span>
                            <span class="tenant-contract-card__chip '.e($statusClass).'">'.e(static::contractStatusLabel($record->status)).'</span>
                            <span class="tenant-contract-card__chip '.e($movementClass).'">'.e($movementLabel).'</span>
                            <span class="tenant-contract-card__chip '.e($mappingClass).'">Привязка: '.e(static::spaceMappingModeLabel($record->space_mapping_mode)).'</span>
                        </div>
                    </div>
                    <div>
                        <div class="tenant-contract-card__eyebrow">Финансы и движение 1С</div>
                        <div class="tenant-contract-card__metrics">'.$metricsHtml.'</div>
                    </div>
                </div>
                <div class="tenant-contract-card__body">'.$factsHtml.'</div>
                <div class="tenant-contract-card__snapshot">'.e($snapshotParts !== [] ? implode(' · ', $snapshotParts) : 'Свежих финансовых данных 1С по договору нет.').'</div>
            </div>'
        );
    }

    /**
     * @return array{label:string,parent_label:?string}
     */
    private static function contractSpaceOverview(TenantContract $record): array
    {
        $space = $record->marketSpace;
        if (! $space) {
            return [
                'label' => 'Не привязан',
                'parent_label' => null,
            ];
        }

        $label = static::spaceLabel($record);
        $parentLabel = null;

        $parentId = (int) ($space->space_group_parent_id ?? 0);
        if ($parentId > 0 && $parentId !== (int) $space->id) {
            $parent = MarketSpace::query()
                ->whereKey($parentId)
                ->first(['id', 'number', 'code', 'display_name']);

            if ($parent) {
                $parentLabel = static::spaceOptionLabel(
                    $parent->display_name,
                    $parent->number,
                    $parent->code,
                );
            }
        }

        return [
            'label' => $label,
            'parent_label' => $parentLabel,
        ];
    }

    /**
     * @return array{accrued:?float,paid:?float,debt:?float,snapshot_label:?string,organization_label:?string,account_label:?string}
     */
    private static function latestDebtSummaryForRecord(TenantContract $record): array
    {
        $empty = [
            'accrued' => null,
            'paid' => null,
            'debt' => null,
            'snapshot_label' => null,
            'organization_label' => null,
            'account_label' => null,
        ];

        $externalId = trim((string) ($record->external_id ?? ''));
        if ($externalId === '' || ! DbSchema::hasTable('contract_debts') || ! static::hasTableColumn('contract_debts', 'contract_external_id')) {
            return $empty;
        }

        $hasAccrued = static::hasTableColumn('contract_debts', 'accrued_amount');
        $hasPaid = static::hasTableColumn('contract_debts', 'paid_amount');
        $hasDebt = static::hasTableColumn('contract_debts', 'debt_amount');
        $hasCalculatedAt = static::hasTableColumn('contract_debts', 'calculated_at');
        $hasCreatedAt = static::hasTableColumn('contract_debts', 'created_at');
        $hasOrganizationName = static::hasTableColumn('contract_debts', 'organization_name');
        $hasOrganizationExternalId = static::hasTableColumn('contract_debts', 'organization_external_id');
        $hasAccount = static::hasTableColumn('contract_debts', 'account');

        $base = DB::query()
            ->fromSub(ContractDebt::latestContractStateQuery((int) $record->market_id), 'cd')
            ->where('cd.contract_external_id', $externalId);

        if ((int) ($record->tenant_id ?? 0) > 0 && static::hasTableColumn('contract_debts', 'tenant_id')) {
            $base->where('cd.tenant_id', (int) $record->tenant_id);
        }

        $rows = (clone $base)->get([
            $hasAccrued ? 'cd.accrued_amount' : DB::raw('NULL as accrued_amount'),
            $hasPaid ? 'cd.paid_amount' : DB::raw('NULL as paid_amount'),
            $hasDebt ? 'cd.debt_amount' : DB::raw('NULL as debt_amount'),
            $hasCalculatedAt ? 'cd.calculated_at' : DB::raw('NULL as calculated_at'),
            $hasCreatedAt ? 'cd.created_at' : DB::raw('NULL as created_at'),
            $hasOrganizationName ? 'cd.organization_name' : DB::raw('NULL as organization_name'),
            $hasOrganizationExternalId ? 'cd.organization_external_id' : DB::raw('NULL as organization_external_id'),
            $hasAccount ? 'cd.account' : DB::raw('NULL as account'),
        ]);

        if ($rows->isEmpty()) {
            return $empty;
        }

        $accrued = $hasAccrued ? (float) $rows->sum(fn ($row): float => is_numeric($row->accrued_amount ?? null) ? (float) $row->accrued_amount : 0.0) : null;
        $paid = $hasPaid ? (float) $rows->sum(fn ($row): float => is_numeric($row->paid_amount ?? null) ? (float) $row->paid_amount : 0.0) : null;
        $debt = $hasDebt ? (float) $rows->sum(fn ($row): float => is_numeric($row->debt_amount ?? null) ? (float) $row->debt_amount : 0.0) : null;

        if ($debt === null && $accrued !== null && $paid !== null) {
            $debt = $accrued - $paid;
        }

        $snapshotValue = $rows
            ->map(fn ($row) => $row->calculated_at ?? $row->created_at ?? null)
            ->filter()
            ->sort()
            ->last();

        $organizations = $rows
            ->map(fn ($row): string => trim((string) ($row->organization_name ?? $row->organization_external_id ?? '')))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $accounts = $rows
            ->map(fn ($row): string => trim((string) ($row->account ?? '')))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return [
            'accrued' => $accrued,
            'paid' => $paid,
            'debt' => $debt,
            'snapshot_label' => static::formatContractCardDateTime($snapshotValue),
            'organization_label' => $organizations !== [] ? implode(', ', array_slice($organizations, 0, 3)) : null,
            'account_label' => $accounts !== [] ? implode(', ', array_slice($accounts, 0, 3)) : null,
        ];
    }

    /**
     * @return array{amount_label:?string,period_label:?string}
     */
    private static function latestAccrualSummaryForRecord(TenantContract $record): array
    {
        if (! DbSchema::hasTable('tenant_accruals') || ! static::hasTableColumn('tenant_accruals', 'tenant_contract_id')) {
            return [
                'amount_label' => null,
                'period_label' => null,
            ];
        }

        $hasPeriod = static::hasTableColumn('tenant_accruals', 'period');
        $hasAccrualDate = static::hasTableColumn('tenant_accruals', 'accrual_date');
        $hasTotalWithVat = static::hasTableColumn('tenant_accruals', 'total_with_vat');
        $hasTotalNoVat = static::hasTableColumn('tenant_accruals', 'total_no_vat');
        $hasAmount = static::hasTableColumn('tenant_accruals', 'amount');

        $base = DB::table('tenant_accruals')
            ->where('market_id', (int) $record->market_id)
            ->where('tenant_contract_id', (int) $record->id);

        $periodColumn = $hasPeriod ? 'period' : ($hasAccrualDate ? 'accrual_date' : null);
        $latestPeriod = $periodColumn ? (clone $base)->max($periodColumn) : null;

        if ($latestPeriod !== null && $periodColumn !== null) {
            $base->where($periodColumn, $latestPeriod);
        }

        $amountExpression = match (true) {
            $hasTotalWithVat && $hasTotalNoVat => 'COALESCE(total_with_vat, total_no_vat, 0)',
            $hasTotalWithVat => 'COALESCE(total_with_vat, 0)',
            $hasTotalNoVat => 'COALESCE(total_no_vat, 0)',
            $hasAmount => 'COALESCE(amount, 0)',
            default => '0',
        };

        $amount = (float) ((clone $base)->sum(DB::raw($amountExpression)) ?? 0);

        return [
            'amount_label' => static::formatRubForContractCard($amount),
            'period_label' => $latestPeriod !== null ? static::formatContractCardPeriod((string) $latestPeriod) : null,
        ];
    }

    private static function renderContractFinance1C(?TenantContract $record): HtmlString
    {
        if (! $record) {
            return new HtmlString('<div class="text-sm text-gray-500">Финансы появятся после сохранения договора.</div>');
        }

        $settlement = static::contractSettlementFinance($record);
        $accruals = static::contractAccrualFinance($record);
        $payments = static::contractPaymentFinance($record);

        $balance = $settlement['balance'];
        $statusLabel = match (true) {
            $balance === null => 'Нет данных ОСВ',
            $balance > 0.009 => 'Есть задолженность',
            $balance < -0.009 => 'Переплата',
            default => 'Нет задолженности',
        };
        $statusClass = match (true) {
            $balance === null => 'contract-finance-1c__status--muted',
            $balance > 0.009 => 'contract-finance-1c__status--danger',
            $balance < -0.009 => 'contract-finance-1c__status--success',
            default => 'contract-finance-1c__status--success',
        };

        $summaryCards = [
            ['label' => 'Статус ОСВ', 'value' => $statusLabel, 'class' => $statusClass],
            ['label' => 'Итог по ОСВ', 'value' => $balance !== null ? static::formatRubForContractCard(abs($balance)) : '—', 'class' => $balance !== null && $balance > 0.009 ? 'contract-finance-1c__value--danger' : ''],
            ['label' => 'Начислено за период ОСВ', 'value' => $settlement['accrued'] !== null ? static::formatRubForContractCard($settlement['accrued']) : '—', 'class' => ''],
            ['label' => 'Оплачено за период ОСВ', 'value' => $settlement['paid'] !== null ? static::formatRubForContractCard($settlement['paid']) : '—', 'class' => ''],
            ['label' => 'Период ОСВ', 'value' => $settlement['period_label'] ?: '—', 'class' => ''],
            ['label' => 'Обновлено', 'value' => $settlement['imported_label'] ?: '—', 'class' => ''],
            ['label' => 'Начислений в журнале', 'value' => (string) $accruals['count'], 'class' => ''],
            ['label' => 'Оплат в журнале', 'value' => (string) $payments['count'], 'class' => ''],
        ];

        $summaryHtml = '';
        foreach ($summaryCards as $card) {
            $summaryHtml .= '<div class="contract-finance-1c__card">'
                . '<div class="contract-finance-1c__label">' . e((string) $card['label']) . '</div>'
                . '<div class="contract-finance-1c__value ' . e((string) $card['class']) . '">' . e((string) $card['value']) . '</div>'
                . '</div>';
        }

        $settlementRowsHtml = static::renderContractFinanceSettlementRows($settlement['rows']);
        $accrualRowsHtml = static::renderContractFinanceAccrualRows($accruals['rows']);
        $paymentRowsHtml = static::renderContractFinancePaymentRows($payments['rows']);

        $accrualLimitNote = $accruals['count'] > count($accruals['rows'])
            ? '<div class="contract-finance-1c__note">Показаны последние ' . e((string) count($accruals['rows'])) . ' начислений из ' . e((string) $accruals['count']) . '.</div>'
            : '';
        $paymentLimitNote = $payments['count'] > count($payments['rows'])
            ? '<div class="contract-finance-1c__note">Показаны последние ' . e((string) count($payments['rows'])) . ' оплат из ' . e((string) $payments['count']) . '.</div>'
            : '';

        $emptyHint = $settlement['count'] === 0 && $accruals['count'] === 0 && $payments['count'] === 0
            ? '<div class="contract-finance-1c__empty">По этому договору пока нет строк ОСВ, начислений или оплат из 1С.</div>'
            : '';

        return new HtmlString(
            '<style>
                .contract-finance-1c{display:grid;gap:16px}
                .contract-finance-1c__summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:10px}
                .contract-finance-1c__card{border:1px solid rgba(148,163,184,.35);border-radius:10px;background:rgba(248,250,252,.78);padding:11px 12px;min-width:0}
                .dark .contract-finance-1c__card{background:rgba(15,23,42,.45);border-color:rgba(148,163,184,.25)}
                .contract-finance-1c__label{font-size:12px;line-height:1.25;color:#64748b}
                .dark .contract-finance-1c__label{color:#94a3b8}
                .contract-finance-1c__value{margin-top:5px;font-size:18px;font-weight:780;line-height:1.2;color:#0f172a;overflow-wrap:anywhere}
                .dark .contract-finance-1c__value{color:#e2e8f0}
                .contract-finance-1c__value--danger{color:#b91c1c}
                .contract-finance-1c__status--danger{color:#b91c1c}
                .contract-finance-1c__status--success{color:#15803d}
                .contract-finance-1c__status--muted{color:#64748b}
                .contract-finance-1c__section{display:grid;gap:9px}
                .contract-finance-1c__section-head{display:flex;align-items:flex-end;justify-content:space-between;gap:12px}
                .contract-finance-1c__section-title{font-size:15px;font-weight:760;line-height:1.25;color:#0f172a}
                .dark .contract-finance-1c__section-title{color:#f8fafc}
                .contract-finance-1c__section-meta{font-size:12px;color:#64748b}
                .dark .contract-finance-1c__section-meta{color:#94a3b8}
                .contract-finance-1c__table-wrap{overflow:auto;border:1px solid rgba(148,163,184,.32);border-radius:10px;background:#fff}
                .dark .contract-finance-1c__table-wrap{background:rgba(15,23,42,.4);border-color:rgba(148,163,184,.24)}
                .contract-finance-1c table{width:100%;min-width:860px;border-collapse:collapse;font-size:12px;line-height:1.35}
                .contract-finance-1c th{position:sticky;top:0;background:#f8fafc;color:#475569;font-weight:760;text-align:left}
                .dark .contract-finance-1c th{background:#111827;color:#cbd5e1}
                .contract-finance-1c th,.contract-finance-1c td{padding:9px 10px;border-bottom:1px solid rgba(148,163,184,.22);vertical-align:top}
                .contract-finance-1c tr:last-child td{border-bottom:0}
                .contract-finance-1c__amount{text-align:right;white-space:nowrap;font-weight:760}
                .contract-finance-1c__muted{color:#64748b}
                .dark .contract-finance-1c__muted{color:#94a3b8}
                .contract-finance-1c__note,.contract-finance-1c__empty{font-size:12px;line-height:1.4;color:#64748b}
                .dark .contract-finance-1c__note,.dark .contract-finance-1c__empty{color:#94a3b8}
                @media (max-width:760px){.contract-finance-1c__section-head{display:grid}.contract-finance-1c table{min-width:760px}}
            </style>
            <div class="contract-finance-1c">
                ' . $emptyHint . '
                <div class="contract-finance-1c__summary">' . $summaryHtml . '</div>
                <div class="contract-finance-1c__section">
                    <div class="contract-finance-1c__section-head">
                        <div class="contract-finance-1c__section-title">ОСВ по договору</div>
                        <div class="contract-finance-1c__section-meta">' . e($settlement['count'] > 0 ? (string) $settlement['count'] . ' строк' : 'строк нет') . '</div>
                    </div>
                    <div class="contract-finance-1c__table-wrap">
                        <table>
                            <thead><tr><th>Период</th><th>Организация</th><th>Счет</th><th class="contract-finance-1c__amount">Начислено</th><th class="contract-finance-1c__amount">Оплачено</th><th class="contract-finance-1c__amount">Итог</th></tr></thead>
                            <tbody>' . $settlementRowsHtml . '</tbody>
                        </table>
                    </div>
                </div>
                <div class="contract-finance-1c__section">
                    <div class="contract-finance-1c__section-head">
                        <div class="contract-finance-1c__section-title">Начисления</div>
                        <div class="contract-finance-1c__section-meta">Всего: ' . e((string) $accruals['count']) . ' · сумма: ' . e(static::formatRubForContractCard((float) $accruals['sum'])) . '</div>
                    </div>
                    ' . $accrualLimitNote . '
                    <div class="contract-finance-1c__table-wrap">
                        <table>
                            <thead><tr><th>Период</th><th>Документ</th><th>За что</th><th>Место из 1С</th><th class="contract-finance-1c__amount">Сумма</th><th>Импорт</th></tr></thead>
                            <tbody>' . $accrualRowsHtml . '</tbody>
                        </table>
                    </div>
                </div>
                <div class="contract-finance-1c__section">
                    <div class="contract-finance-1c__section-head">
                        <div class="contract-finance-1c__section-title">Оплаты</div>
                        <div class="contract-finance-1c__section-meta">Всего: ' . e((string) $payments['count']) . ' · сумма: ' . e(static::formatRubForContractCard((float) $payments['sum'])) . '</div>
                    </div>
                    ' . $paymentLimitNote . '
                    <div class="contract-finance-1c__table-wrap">
                        <table>
                            <thead><tr><th>Период</th><th>Дата</th><th>Документ</th><th>Назначение</th><th class="contract-finance-1c__amount">Сумма</th><th>Импорт</th></tr></thead>
                            <tbody>' . $paymentRowsHtml . '</tbody>
                        </table>
                    </div>
                </div>
            </div>'
        );
    }

    /**
     * @return array{count:int,accrued:?float,paid:?float,balance:?float,period_label:?string,imported_label:?string,rows:\Illuminate\Support\Collection<int, object>}
     */
    private static function contractSettlementFinance(TenantContract $record): array
    {
        $empty = [
            'count' => 0,
            'accrued' => null,
            'paid' => null,
            'balance' => null,
            'period_label' => null,
            'imported_label' => null,
            'rows' => collect(),
        ];

        if (! DbSchema::hasTable('tenant_settlement_balances')) {
            return $empty;
        }

        $base = DB::table('tenant_settlement_balances as tsb');
        static::applyContractFinanceMatch($base, 'tenant_settlement_balances', 'tsb', $record);

        $count = (int) (clone $base)->count();
        if ($count === 0) {
            return $empty;
        }

        $latestPeriodTo = (clone $base)->max('tsb.period_to');
        $latestBase = clone $base;
        if ($latestPeriodTo !== null) {
            $latestBase->where('tsb.period_to', $latestPeriodTo);
        }

        $rows = (clone $latestBase)
            ->select([
                'tsb.period_from',
                'tsb.period_to',
                'tsb.organization_name',
                'tsb.organization_external_id',
                'tsb.account',
                'tsb.turnover_debit',
                'tsb.turnover_credit',
                'tsb.closing_debit',
                'tsb.closing_credit',
                'tsb.imported_at',
            ])
            ->orderBy('tsb.account')
            ->orderBy('tsb.organization_name')
            ->get();

        $periodFrom = $rows->pluck('period_from')->filter()->sort()->first();
        $periodTo = $rows->pluck('period_to')->filter()->sort()->last();
        $importedAt = $rows->pluck('imported_at')->filter()->sort()->last();
        $accrued = (float) $rows->sum(fn (object $row): float => (float) ($row->turnover_debit ?? 0));
        $paid = (float) $rows->sum(fn (object $row): float => (float) ($row->turnover_credit ?? 0));
        $balance = (float) $rows->sum(fn (object $row): float => (float) ($row->closing_debit ?? 0) - (float) ($row->closing_credit ?? 0));

        return [
            'count' => $count,
            'accrued' => $accrued,
            'paid' => $paid,
            'balance' => $balance,
            'period_label' => static::formatContractFinancePeriodRange($periodFrom, $periodTo),
            'imported_label' => static::formatContractCardDateTime($importedAt),
            'rows' => $rows,
        ];
    }

    /**
     * @return array{count:int,sum:float,rows:\Illuminate\Support\Collection<int, object>}
     */
    private static function contractAccrualFinance(TenantContract $record): array
    {
        if (! DbSchema::hasTable('tenant_accruals')) {
            return ['count' => 0, 'sum' => 0.0, 'rows' => collect()];
        }

        $amountExpression = static::contractAccrualAmountExpression();
        $base = DB::table('tenant_accruals as ta');
        static::applyContractFinanceMatch($base, 'tenant_accruals', 'ta', $record);

        $count = (int) (clone $base)->count();
        $sum = (float) ((clone $base)->sum(DB::raw($amountExpression)) ?? 0);

        $rows = (clone $base)
            ->select([
                'ta.period',
                static::hasTableColumn('tenant_accruals', 'document_date') ? 'ta.document_date' : DB::raw('NULL as document_date'),
                static::hasTableColumn('tenant_accruals', 'document_number') ? 'ta.document_number' : DB::raw('NULL as document_number'),
                static::hasTableColumn('tenant_accruals', 'document_name') ? 'ta.document_name' : DB::raw('NULL as document_name'),
                static::hasTableColumn('tenant_accruals', 'service_name') ? 'ta.service_name' : DB::raw('NULL as service_name'),
                static::hasTableColumn('tenant_accruals', 'line_description') ? 'ta.line_description' : DB::raw('NULL as line_description'),
                static::hasTableColumn('tenant_accruals', 'purpose') ? 'ta.purpose' : DB::raw('NULL as purpose'),
                static::hasTableColumn('tenant_accruals', 'source_place_code') ? 'ta.source_place_code' : DB::raw('NULL as source_place_code'),
                static::hasTableColumn('tenant_accruals', 'source_place_name') ? 'ta.source_place_name' : DB::raw('NULL as source_place_name'),
                static::hasTableColumn('tenant_accruals', 'imported_at') ? 'ta.imported_at' : DB::raw('NULL as imported_at'),
                DB::raw($amountExpression . ' as amount_value'),
            ])
            ->orderByDesc('ta.period')
            ->orderByDesc(static::hasTableColumn('tenant_accruals', 'document_date') ? 'ta.document_date' : 'ta.id')
            ->orderByDesc('ta.id')
            ->limit(300)
            ->get();

        return ['count' => $count, 'sum' => $sum, 'rows' => $rows];
    }

    /**
     * @return array{count:int,sum:float,rows:\Illuminate\Support\Collection<int, object>}
     */
    private static function contractPaymentFinance(TenantContract $record): array
    {
        if (! DbSchema::hasTable('tenant_payments')) {
            return ['count' => 0, 'sum' => 0.0, 'rows' => collect()];
        }

        $base = DB::table('tenant_payments as tp');
        static::applyContractFinanceMatch($base, 'tenant_payments', 'tp', $record);

        $count = (int) (clone $base)->count();
        $sum = (float) ((clone $base)->sum('tp.amount') ?? 0);

        $rows = (clone $base)
            ->select([
                'tp.period',
                'tp.payment_date',
                'tp.document_number',
                'tp.payment_external_id',
                'tp.amount',
                'tp.purpose',
                'tp.imported_at',
            ])
            ->orderByDesc('tp.payment_date')
            ->orderByDesc('tp.id')
            ->limit(300)
            ->get();

        return ['count' => $count, 'sum' => $sum, 'rows' => $rows];
    }

    private static function applyContractFinanceMatch($query, string $table, string $alias, TenantContract $record): void
    {
        $contractId = (int) $record->id;
        $externalId = trim((string) ($record->external_id ?? ''));
        $tenantId = (int) ($record->tenant_id ?? 0);

        $query->where($alias . '.market_id', (int) $record->market_id);

        if ($tenantId > 0 && static::hasTableColumn($table, 'tenant_id')) {
            $query->where($alias . '.tenant_id', $tenantId);
        }

        $hasContractId = static::hasTableColumn($table, 'tenant_contract_id');
        $hasExternalId = static::hasTableColumn($table, 'contract_external_id') && $externalId !== '';

        $query->where(function ($query) use ($alias, $contractId, $externalId, $hasContractId, $hasExternalId): void {
            if ($hasContractId) {
                $query->orWhere($alias . '.tenant_contract_id', $contractId);
            }

            if ($hasExternalId) {
                $query->orWhere($alias . '.contract_external_id', $externalId);
            }

            if (! $hasContractId && ! $hasExternalId) {
                $query->whereRaw('1 = 0');
            }
        });
    }

    private static function contractAccrualAmountExpression(): string
    {
        $parts = [];

        foreach (['total_with_vat', 'total_no_vat', 'amount', 'rent_amount'] as $column) {
            if (static::hasTableColumn('tenant_accruals', $column)) {
                $parts[] = $column;
            }
        }

        return $parts !== [] ? 'COALESCE(' . implode(', ', $parts) . ', 0)' : '0';
    }

    private static function renderContractFinanceSettlementRows(\Illuminate\Support\Collection $rows): string
    {
        if ($rows->isEmpty()) {
            return '<tr><td colspan="6" class="contract-finance-1c__muted">По договору нет строк ОСВ 1С.</td></tr>';
        }

        $html = '';
        foreach ($rows as $row) {
            $periodLabel = static::formatContractFinancePeriodRange($row->period_from ?? null, $row->period_to ?? null) ?: '—';
            $organization = trim((string) ($row->organization_name ?? $row->organization_external_id ?? ''));
            $account = trim((string) ($row->account ?? ''));
            $balance = (float) ($row->closing_debit ?? 0) - (float) ($row->closing_credit ?? 0);

            $html .= '<tr>'
                . '<td>' . e($periodLabel) . '</td>'
                . '<td>' . e($organization !== '' ? $organization : '—') . '</td>'
                . '<td>' . e($account !== '' ? $account : '—') . '</td>'
                . '<td class="contract-finance-1c__amount">' . e(static::formatRubForContractCard((float) ($row->turnover_debit ?? 0))) . '</td>'
                . '<td class="contract-finance-1c__amount">' . e(static::formatRubForContractCard((float) ($row->turnover_credit ?? 0))) . '</td>'
                . '<td class="contract-finance-1c__amount">' . e(($balance < -0.009 ? 'Переплата ' : '') . static::formatRubForContractCard(abs($balance))) . '</td>'
                . '</tr>';
        }

        return $html;
    }

    private static function renderContractFinanceAccrualRows(\Illuminate\Support\Collection $rows): string
    {
        if ($rows->isEmpty()) {
            return '<tr><td colspan="6" class="contract-finance-1c__muted">По договору нет начислений из 1С.</td></tr>';
        }

        $html = '';
        foreach ($rows as $row) {
            $document = static::firstFilledString($row->document_number ?? null, $row->document_name ?? null);
            $reason = static::firstFilledString($row->service_name ?? null, $row->line_description ?? null, $row->purpose ?? null);
            $place = trim(implode(' · ', array_filter([
                trim((string) ($row->source_place_code ?? '')),
                trim((string) ($row->source_place_name ?? '')),
            ])));

            $html .= '<tr>'
                . '<td>' . e(static::formatContractCardPeriod((string) ($row->period ?? ''))) . '</td>'
                . '<td>' . e(($document !== '' ? $document : '—') . (filled($row->document_date ?? null) ? ' · ' . static::formatContractFinanceDate($row->document_date) : '')) . '</td>'
                . '<td>' . e($reason !== '' ? $reason : '—') . '</td>'
                . '<td>' . e($place !== '' ? $place : '—') . '</td>'
                . '<td class="contract-finance-1c__amount">' . e(static::formatRubForContractCard((float) ($row->amount_value ?? 0))) . '</td>'
                . '<td>' . e(static::formatContractCardDateTime($row->imported_at ?? null) ?? '—') . '</td>'
                . '</tr>';
        }

        return $html;
    }

    private static function renderContractFinancePaymentRows(\Illuminate\Support\Collection $rows): string
    {
        if ($rows->isEmpty()) {
            return '<tr><td colspan="6" class="contract-finance-1c__muted">По договору нет оплат из 1С.</td></tr>';
        }

        $html = '';
        foreach ($rows as $row) {
            $document = static::firstFilledString($row->document_number ?? null, $row->payment_external_id ?? null);
            $purpose = trim((string) ($row->purpose ?? ''));

            $html .= '<tr>'
                . '<td>' . e(static::formatContractCardPeriod((string) ($row->period ?? ''))) . '</td>'
                . '<td>' . e(static::formatContractFinanceDate($row->payment_date ?? null)) . '</td>'
                . '<td>' . e($document !== '' ? $document : '—') . '</td>'
                . '<td>' . e($purpose !== '' ? $purpose : '—') . '</td>'
                . '<td class="contract-finance-1c__amount">' . e(static::formatRubForContractCard((float) ($row->amount ?? 0))) . '</td>'
                . '<td>' . e(static::formatContractCardDateTime($row->imported_at ?? null) ?? '—') . '</td>'
                . '</tr>';
        }

        return $html;
    }

    private static function firstFilledString(mixed ...$values): string
    {
        foreach ($values as $value) {
            $string = trim((string) ($value ?? ''));
            if ($string !== '') {
                return $string;
            }
        }

        return '';
    }

    private static function formatContractFinancePeriodRange(mixed $from, mixed $to): ?string
    {
        $fromLabel = static::formatContractFinanceDate($from);
        $toLabel = static::formatContractFinanceDate($to);

        if ($fromLabel === '—' && $toLabel === '—') {
            return null;
        }

        if ($fromLabel !== '—' && $toLabel !== '—') {
            return $fromLabel . ' — ' . $toLabel;
        }

        return $fromLabel !== '—' ? $fromLabel : $toLabel;
    }

    private static function formatContractFinanceDate(mixed $value): string
    {
        if (! $value) {
            return '—';
        }

        try {
            return Carbon::parse((string) $value)->format('d.m.Y');
        } catch (Throwable) {
            return (string) $value;
        }
    }

    private static function contractDateRangeLabel(TenantContract $record, array $classified): string
    {
        $parts = [];
        $documentDate = static::formatClassifierDate($classified['document_date'] ?? null);
        if ($documentDate !== '—') {
            $parts[] = 'дата договора '.$documentDate;
        }

        if ($record->starts_at instanceof Carbon) {
            $parts[] = 'с '.$record->starts_at->format('d.m.Y');
        }

        if ($record->ends_at instanceof Carbon) {
            $parts[] = 'до '.$record->ends_at->format('d.m.Y');
        }

        if ($record->signed_at instanceof Carbon) {
            $parts[] = 'подписан '.$record->signed_at->format('d.m.Y');
        }

        return $parts !== [] ? implode(' · ', $parts) : '—';
    }

    private static function formatRubForContractCard(float $value): string
    {
        return number_format($value, 2, ',', ' ').' ₽';
    }

    /**
     * @param  array{amount_label:?string,period_label:?string}  $summary
     */
    private static function latestAccrualMetricLabel(array $summary): string
    {
        $amount = trim((string) ($summary['amount_label'] ?? ''));
        $period = trim((string) ($summary['period_label'] ?? ''));

        if ($amount === '' && $period === '') {
            return '—';
        }

        if ($amount !== '' && $period !== '') {
            return "{$amount} · {$period}";
        }

        return $amount !== '' ? $amount : $period;
    }

    private static function formatContractCardDateTime(mixed $value): ?string
    {
        if (! $value) {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->format('d.m.Y H:i');
        } catch (Throwable) {
            return (string) $value;
        }
    }

    private static function formatContractCardPeriod(string $value): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value) === 1) {
            try {
                return Carbon::parse($value)->format('m.Y');
            } catch (Throwable) {
                return $value;
            }
        }

        if (preg_match('/^\d{4}-\d{2}$/', $value) === 1) {
            return substr($value, 5, 2).'.'.substr($value, 0, 4);
        }

        return $value;
    }

    private static function hasTableColumn(string $table, string $column): bool
    {
        try {
            if (! DbSchema::hasTable($table)) {
                return false;
            }

            $columns = static::$tableColumnsCache[$table] ?? null;
            if ($columns === null) {
                $columns = DbSchema::getColumnListing($table);
                static::$tableColumnsCache[$table] = $columns;
            }

            return in_array($column, $columns, true);
        } catch (Throwable) {
            return false;
        }
    }

    public static function table(Table $table): Table
    {
        $user = Filament::auth()->user();
        $isSuperAdmin = (bool) ($user && $user->isSuperAdmin());

        return $table
            ->columns([
                TextColumn::make('market.name')
                    ->label('Рынок')
                    ->tooltip('Рынок, к которому относится договор.')
                    ->headerTooltip('Рынок, к которому относится договор.')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->visible(fn () => (bool) $user && $user->isSuperAdmin()),

                TextColumn::make('tenant.name')
                    ->label('Арендатор')
                    ->tooltip('Арендатор по договору.')
                    ->headerTooltip('Арендатор по договору.')
                    ->sortable()
                    ->searchable()
                    ->placeholder('—')
                    ->description(fn (TenantContract $record): ?string => filled($record->tenant?->short_name) ? (string) $record->tenant?->short_name : null)
                    ->wrap(),

                TextColumn::make('number')
                    ->label('Номер документа')
                    ->tooltip('Номер договора из 1С.')
                    ->headerTooltip('Номер договора из 1С.')
                    ->sortable()
                    ->searchable()
                    ->placeholder('—')
                    ->wrap(),

                TextColumn::make('document_type')
                    ->label('Тип документа')
                    ->state(fn (TenantContract $record): string => (string) static::classificationForRecord($record)['label'])
                    ->tooltip('Тип документа, определяемый по признакам 1С.')
                    ->headerTooltip('Тип документа, определяемый по признакам 1С.')
                    ->badge()
                    ->color(fn (TenantContract $record): string => static::documentTypeColor((string) static::classificationForRecord($record)['category'])),

                TextColumn::make('document_date')
                    ->label('Дата договора')
                    ->state(fn (TenantContract $record): string => static::effectiveOrderDateLabel($record))
                    ->tooltip('Дата из номера договора. Если её нет, используется дата подписания из 1С.')
                    ->headerTooltip('Дата из номера договора. Если её нет, используется дата подписания из 1С.')
                    ->sortable(query: fn (Builder $query, string $direction): Builder => static::applyContractDateSort($query, $direction))
                    ->toggleable(),

                TextColumn::make('market_space_link')
                    ->label('Текущее место')
                    ->state(fn (TenantContract $record): string => static::spaceLabel($record))
                    ->tooltip('Торговое место, привязанное к договору сейчас.')
                    ->headerTooltip('Торговое место, привязанное к договору сейчас.')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('ordering_status')
                    ->label('Готовность к привязке')
                    ->state(fn (TenantContract $record): string => static::orderingMeta($record)['label'])
                    ->tooltip('Показывает, насколько договор уже готов к привязке к месту.')
                    ->headerTooltip('Показывает, насколько договор уже готов к привязке к месту.')
                    ->badge()
                    ->color(fn (TenantContract $record): string => static::orderingMeta($record)['color']),

                TextColumn::make('chain_position')
                    ->label('Цепочка')
                    ->state(fn (TenantContract $record): string => static::chainDisplay($record))
                    ->tooltip('Позиция договора в истории одного места. Формат 2/5 означает: второй из пяти.')
                    ->headerTooltip('Позиция договора в истории одного места. Формат 2/5 означает: второй из пяти.')
                    ->badge()
                    ->color(fn (TenantContract $record): string => static::chainColor($record))
                    ->toggleable(),

                TextColumn::make('overlap_status')
                    ->label('Наложение')
                    ->state(fn (TenantContract $record): string => static::overlapDisplay($record))
                    ->tooltip('Показывает, есть ли пересечение этого договора по срокам с другим договором в цепочке.')
                    ->headerTooltip('Показывает, есть ли пересечение этого договора по срокам с другим договором в цепочке.')
                    ->badge()
                    ->color(fn (TenantContract $record): string => static::overlapColor($record))
                    ->toggleable(),

                TextColumn::make('place_token')
                    ->label('Токен места')
                    ->state(fn (TenantContract $record): string => (string) (static::classificationForRecord($record)['place_token'] ?: '—'))
                    ->tooltip('Технический токен места, который помогает собрать цепочку договоров.')
                    ->headerTooltip('Технический токен места, который помогает собрать цепочку договоров.')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->visible(fn () => $isSuperAdmin),

                TextColumn::make('effective_order_date')
                    ->label('Дата для цепочки')
                    ->state(fn (TenantContract $record): string => static::effectiveOrderDateLabel($record))
                    ->tooltip('Дата, по которой строится цепочка по месту.')
                    ->headerTooltip('Дата, по которой строится цепочка по месту.')
                    ->sortable(query: fn (Builder $query, string $direction): Builder => static::applyContractDateSort($query, $direction))
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->visible(fn () => $isSuperAdmin),

                TextColumn::make('date_consistency')
                    ->label('Дата 1С / БД')
                    ->state(fn (TenantContract $record): string => static::dateConsistencyLabel($record))
                    ->tooltip('Сравнение даты из номера договора с датой из 1С и технической датой начала.')
                    ->headerTooltip('Сравнение даты из номера договора с датой из 1С и технической датой начала.')
                    ->badge()
                    ->color(fn (TenantContract $record): string => static::dateConsistencyColor($record))
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->visible(fn () => $isSuperAdmin),

                TextColumn::make('latest_debt_snapshot')
                    ->label('Есть в выгрузке долга')
                    ->state(fn (TenantContract $record): string => static::isInLatestDebtSnapshot($record) ? 'Да' : 'Нет')
                    ->tooltip('Показывает, попал ли договор в последнюю выгрузку задолженности.')
                    ->headerTooltip('Показывает, попал ли договор в последнюю выгрузку задолженности.')
                    ->badge()
                    ->color(fn (TenantContract $record): string => static::isInLatestDebtSnapshot($record) ? 'success' : 'gray')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('one_c_movement')
                    ->label('Движение 1С')
                    ->state(fn (TenantContract $record): string => static::oneCMovementLabel($record))
                    ->tooltip('Показывает, есть ли по договору начисления или задолженность в последнем срезе 1С. Это не меняет юридический статус договора.')
                    ->headerTooltip('Показывает, есть ли по договору начисления или задолженность в последнем срезе 1С. Это не меняет юридический статус договора.')
                    ->badge()
                    ->color(fn (TenantContract $record): string => static::oneCMovementColor($record))
                    ->toggleable(),

                TextColumn::make('latest_debt_organization')
                    ->label('Организация долга 1С')
                    ->state(fn (TenantContract $record): string => static::latestDebtOrganizationLabel($record))
                    ->placeholder('—')
                    ->tooltip('Организация из последнего снимка задолженности 1С.')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->visible(fn (): bool => static::canViewTechnicalFields()),

                TextColumn::make('latest_debt_account')
                    ->label('Счет долга 1С')
                    ->state(fn (TenantContract $record): string => static::latestDebtAccountLabel($record))
                    ->placeholder('—')
                    ->tooltip('Счет из последнего снимка задолженности 1С.')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->visible(fn (): bool => static::canViewTechnicalFields()),

                TextColumn::make('space_mapping_mode')
                    ->label('Режим привязки')
                    ->state(fn (TenantContract $record): string => static::spaceMappingModeLabel($record->space_mapping_mode))
                    ->tooltip('Авто: 1С может обновлять привязку. Ручная: привязка зафиксирована вручную. Не участвует: договор исключён из привязки.')
                    ->headerTooltip('Авто: 1С может обновлять привязку. Ручная: привязка зафиксирована вручную. Не участвует: договор исключён из привязки.')
                    ->badge()
                    ->color(fn (TenantContract $record): string => static::spaceMappingModeColor($record->space_mapping_mode))
                    ->toggleable()
                    ->visible(fn (): bool => static::hasTenantContractColumn('space_mapping_mode')),

                TextColumn::make('starts_at')
                    ->label('Техническая дата 1С')
                    ->date('d.m.Y')
                    ->tooltip('Техническая дата начала договора из 1С.')
                    ->headerTooltip('Техническая дата начала договора из 1С.')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->visible(fn () => $isSuperAdmin)
                    ->placeholder('—'),

                TextColumn::make('ends_at')
                    ->label('Окончание')
                    ->date('d.m.Y')
                    ->tooltip('Дата окончания договора.')
                    ->headerTooltip('Дата окончания договора.')
                    ->sortable()
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('status')
                    ->label('Статус договора')
                    ->formatStateUsing(fn (?string $state): string => static::contractStatusLabel($state))
                    ->tooltip('Статус договора в 1С.')
                    ->headerTooltip('Статус договора в 1С.')
                    ->badge()
                    ->color(fn (?string $state): string => static::contractStatusColor($state))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->visible(fn () => $isSuperAdmin),

                IconColumn::make('is_active')
                    ->label('Активен')
                    ->tooltip(fn (bool $state): string => $state ? 'Договор активен.' : 'Договор неактивен.')
                    ->headerTooltip('Признак активности договора.')
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

                SelectFilter::make('one_c_movement')
                    ->label('Движение 1С')
                    ->options([
                        'fresh' => 'Есть свежее движение',
                        'stale' => 'Без свежего движения',
                        'none' => 'Нет данных 1С',
                    ])
                    ->query(static function (Builder $query, array $data): Builder {
                        $value = trim((string) ($data['value'] ?? ''));

                        return match ($value) {
                            'fresh' => static::applyOneCMovementFilter($query, 'fresh'),
                            'stale' => static::applyOneCMovementFilter($query, 'stale'),
                            'none' => static::applyOneCMovementFilter($query, 'none'),
                            default => $query,
                        };
                    }),

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
                    ])
                    ->query(static function (Builder $query, array $data): Builder {
                        if (! static::hasTenantContractColumn('space_mapping_mode')) {
                            return $query;
                        }

                        $value = trim((string) ($data['value'] ?? ''));

                        return $value !== ''
                            ? $query->where('space_mapping_mode', $value)
                            : $query;
                    })
                    ->visible(fn (): bool => static::hasTenantContractColumn('space_mapping_mode')),
            ])
            ->actions([
                tap(\Filament\Actions\EditAction::make()
                    ->tooltip('Быстрое редактирование')
                    ->icon('heroicon-o-pencil-square')
                    ->hiddenLabel()
                    ->iconButton()
                    ->color('gray'), function ($action): void {
                        if (method_exists($action, 'slideOver')) {
                            $action->slideOver();
                        }

                        if (method_exists($action, 'modalWidth')) {
                            $action->modalWidth('7xl');
                        }
                    }),
                \Filament\Actions\Action::make('open_card')
                    ->tooltip('Открыть карточку')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->hiddenLabel()
                    ->iconButton()
                    ->color('primary')
                    ->url(fn (TenantContract $record): string => static::getUrl('edit', ['record' => $record])),
            ])
            ->defaultSort(fn (Builder $query): Builder => static::applyContractDateSort($query, 'desc'))
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

        $marketSpaceId = static::selectedMarketSpaceIdFromQuery();

        $user = Filament::auth()->user();

        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->isSuperAdmin()) {
            $selectedMarketId = static::selectedMarketIdFromSession();

            $query = filled($selectedMarketId)
                ? $query->where('market_id', (int) $selectedMarketId)
                : $query;

            return $marketSpaceId
                ? $query->where('market_space_id', $marketSpaceId)
                : $query;
        }

        if (AdminCapabilities::canViewTenantContracts($user)) {
            $query->where('market_id', (int) $user->market_id);

            return $marketSpaceId
                ? $query->where('market_space_id', $marketSpaceId)
                : $query;
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

        return AdminCapabilities::canViewTenantContracts($user);
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

        return AdminCapabilities::canViewTenantContracts($user, (int) $record->market_id);
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    private static function selectedMarketSpaceIdFromQuery(): ?int
    {
        $value = request()->query('marketSpaceId');

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private static function applyContractDateSort(Builder $query, string $direction): Builder
    {
        $direction = strtolower($direction) === 'asc' ? 'asc' : 'desc';

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return $query
                ->orderBy('tenant_contracts.signed_at', $direction)
                ->orderBy('tenant_contracts.id', $direction);
        }

        $documentDateExpression = static::postgresDocumentDateExpression();

        return $query
            ->orderByRaw(
                'COALESCE('.$documentDateExpression.', tenant_contracts.signed_at) '.$direction.' NULLS LAST'
            )
            ->orderBy('tenant_contracts.id', $direction);
    }

    private static function postgresDocumentDateExpression(): string
    {
        $dateMatch = "substring(upper(tenant_contracts.number) from '\\mОТ\\s+([0-9]{2}\\.[0-9]{2}\\.[0-9]{2,4})\\M')";

        return "CASE
            WHEN {$dateMatch} IS NULL THEN NULL
            WHEN {$dateMatch} ~ '^[0-9]{2}\\.[0-9]{2}\\.[0-9]{2}$' THEN to_date({$dateMatch}, 'DD.MM.YY')
            ELSE to_date({$dateMatch}, 'DD.MM.YYYY')
        END";
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

    private static function oneCMovementStatus(TenantContract $record): string
    {
        if (static::isInLatestDebtSnapshot($record) || static::isInLatestAccrualSnapshot($record)) {
            return 'fresh';
        }

        if (static::isInDebtHistory($record) || static::isInAccrualHistory($record)) {
            return 'stale';
        }

        return 'none';
    }

    private static function oneCMovementLabel(TenantContract $record): string
    {
        return match (static::oneCMovementStatus($record)) {
            'fresh' => 'Есть свежее движение',
            'stale' => 'Без свежего движения',
            default => 'Нет данных 1С',
        };
    }

    private static function oneCMovementColor(TenantContract $record): string
    {
        return match (static::oneCMovementStatus($record)) {
            'fresh' => 'success',
            'stale' => 'warning',
            default => 'gray',
        };
    }

    private static function latestDebtOrganizationLabel(TenantContract $record): string
    {
        $marketId = (int) $record->market_id;
        $externalId = trim((string) ($record->external_id ?? ''));

        if ($marketId <= 0 || $externalId === '') {
            return '—';
        }

        static::warmLatestDebtMetaForMarket($marketId);

        return static::$latestDebtOrganizationsCache[$marketId][$externalId] ?? '—';
    }

    private static function latestDebtAccountLabel(TenantContract $record): string
    {
        $marketId = (int) $record->market_id;
        $externalId = trim((string) ($record->external_id ?? ''));

        if ($marketId <= 0 || $externalId === '') {
            return '—';
        }

        static::warmLatestDebtMetaForMarket($marketId);

        return static::$latestDebtAccountsCache[$marketId][$externalId] ?? '—';
    }

    private static function warmLatestDebtContractIdsForMarket(int $marketId): void
    {
        if ($marketId <= 0 || isset(static::$latestDebtContractIdsCache[$marketId])) {
            return;
        }

        $externalIds = \Illuminate\Support\Facades\DB::query()
            ->fromSub(ContractDebt::latestContractStateQuery($marketId), 'cd')
            ->whereNotNull('contract_external_id')
            ->pluck('contract_external_id')
            ->map(static fn (mixed $value): string => trim((string) $value))
            ->filter(static fn (string $value): bool => $value !== '')
            ->unique()
            ->values()
            ->all();

        static::$latestDebtContractIdsCache[$marketId] = array_fill_keys($externalIds, true);
    }

    private static function warmLatestDebtMetaForMarket(int $marketId): void
    {
        if ($marketId <= 0 || isset(static::$latestDebtOrganizationsCache[$marketId], static::$latestDebtAccountsCache[$marketId])) {
            return;
        }

        $rows = DB::query()
            ->fromSub(ContractDebt::latestContractStateQuery($marketId), 'cd')
            ->select(['contract_external_id', 'organization_name', 'account'])
            ->whereNotNull('contract_external_id')
            ->get();

        $organizationsByContract = [];
        $accountsByContract = [];

        foreach ($rows as $row) {
            $externalId = trim((string) ($row->contract_external_id ?? ''));

            if ($externalId === '') {
                continue;
            }

            $organizationName = trim((string) ($row->organization_name ?? ''));
            $account = trim((string) ($row->account ?? ''));

            if ($organizationName !== '') {
                $organizationsByContract[$externalId][] = $organizationName;
            }

            if ($account !== '') {
                $accountsByContract[$externalId][] = $account;
            }
        }

        $organizations = [];
        foreach ($organizationsByContract as $externalId => $names) {
            $uniqueNames = array_values(array_unique($names));
            $organizations[$externalId] = $uniqueNames === [] ? '—' : implode(', ', $uniqueNames);
        }

        $accounts = [];
        foreach ($accountsByContract as $externalId => $values) {
            $uniqueAccounts = array_values(array_unique($values));
            $accounts[$externalId] = $uniqueAccounts === [] ? '—' : implode(', ', $uniqueAccounts);
        }

        static::$latestDebtOrganizationsCache[$marketId] = $organizations;
        static::$latestDebtAccountsCache[$marketId] = $accounts;
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

        $externalIds = ContractDebt::query()
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

    private static function applyOneCMovementFilter(Builder $query, string $status): Builder
    {
        return match ($status) {
            'fresh' => $query->where(function (Builder $query): void {
                static::applyLatestDebtSnapshotFilter($query, true)
                    ->orWhere(function (Builder $query): void {
                        static::applyLatestAccrualSnapshotFilter($query, true);
                    });
            }),
            'stale' => $query
                ->where(function (Builder $query): void {
                    static::applyDebtHistoryFilter($query, true)
                        ->orWhere(function (Builder $query): void {
                            static::applyAccrualHistoryFilter($query, true);
                        });
                })
                ->where(function (Builder $query): void {
                    static::applyLatestDebtSnapshotFilter($query, false);
                })
                ->where(function (Builder $query): void {
                    static::applyLatestAccrualSnapshotFilter($query, false);
                }),
            'none' => $query
                ->where(function (Builder $query): void {
                    static::applyDebtHistoryFilter($query, false);
                })
                ->where(function (Builder $query): void {
                    static::applyAccrualHistoryFilter($query, false);
                }),
            default => $query,
        };
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
        $cacheKey = $marketId !== null ? 'market:'.$marketId : 'all';

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

        $recordColumns = [
            'id',
            'market_id',
            'market_space_id',
            'number',
            'starts_at',
            'ends_at',
            'signed_at',
            'status',
            'is_active',
        ];

        if (static::hasTenantContractColumn('space_mapping_mode')) {
            $recordColumns[] = 'space_mapping_mode';
        }

        $records = $query->get($recordColumns);

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
            ->with(['marketSpace:id,market_id,number,display_name,code,space_group_role,space_group_parent_id'])
            ->get(['id', 'market_id', 'tenant_id', 'market_space_id', 'number', 'starts_at', 'ends_at', 'signed_at', 'status', 'is_active', 'space_mapping_mode']);

        $grouped = [];
        foreach ($records as $contract) {
            $classified = static::classificationForRecord($contract);
            $token = (string) ($classified['place_token'] ?? '');

            if ($contract->excludesFromSpaceMapping() || ! ($classified['actionable'] ?? false) || $token === '') {
                continue;
            }

            $chainKey = static::contractHistoryChainKey($contract, $classified);
            if ($chainKey === null) {
                continue;
            }

            $grouped[$chainKey][] = [
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
            return $displayName.' · '.$number;
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
            return $short.' · '.$name;
        }

        return $short !== '' ? $short : ($name !== '' ? $name : '—');
    }

    private static function groupEpisodePreview(?TenantContract $record): HtmlString
    {
        if (! $record) {
            return new HtmlString('<div class="text-sm text-gray-500">Нет данных.</div>');
        }

        $classified = static::classificationForRecord($record);
        $documentDate = (string) (static::resolveOrderDate($record, $classified['document_date'] ?? null) ?? '');
        $resolution = app(\App\Support\MarketSpaces\MarketSpaceGroupEpisodeResolver::class)
            ->forContract($record, $documentDate);

        if (! ($resolution['applies'] ?? false)) {
            return new HtmlString('<div class="text-sm text-gray-500">'.e((string) ($resolution['message'] ?? 'Договор не относится к группе мест.')).'</div>');
        }

        /** @var MarketSpace|null $parent */
        $parent = $resolution['parent'] ?? null;
        $episode = $resolution['episode'] ?? null;
        $children = $resolution['children'] ?? collect();
        $source = (string) ($resolution['source'] ?? 'none');
        $sourceLabel = match ($source) {
            'episode' => 'Исторический эпизод',
            'current' => 'Текущий состав',
            default => 'Нет данных',
        };
        $sourceClass = $source === 'episode'
            ? 'contract-group-episode__badge--success'
            : 'contract-group-episode__badge--warning';

        $parentLabel = $parent instanceof MarketSpace
            ? static::spaceOptionLabel($parent->display_name, $parent->number, $parent->code)
            : '—';
        $asOfLabel = static::formatClassifierDate($resolution['as_of'] ?? null);
        $periodLabel = '—';

        if ($episode instanceof \App\Models\MarketSpaceGroupEpisode) {
            $from = $episode->valid_from ? $episode->valid_from->format('d.m.Y') : '—';
            $to = $episode->valid_to ? $episode->valid_to->format('d.m.Y') : '—';
            $periodLabel = $from.' - '.$to;
        }

        $rowsHtml = '';
        foreach ($children as $index => $child) {
            if (! $child instanceof MarketSpace) {
                continue;
            }

            $slot = trim((string) ($child->space_group_slot ?? ''));
            $label = static::spaceOptionLabel($child->display_name, $child->number, $child->code);
            $area = is_numeric($child->area_sqm ?? null)
                ? number_format((float) $child->area_sqm, 2, ',', ' ').' м²'
                : '—';

            $rowsHtml .= '<tr>'
                .'<td>'.e((string) ($index + 1)).'</td>'
                .'<td>'.e($slot !== '' ? $slot : '—').'</td>'
                .'<td>'.e($label).'</td>'
                .'<td>'.e($area).'</td>'
                .'</tr>';
        }

        if ($rowsHtml === '') {
            $rowsHtml = '<tr><td colspan="4" class="contract-group-episode__empty">Состав группы не указан.</td></tr>';
        }

        $message = (string) ($resolution['message'] ?? '');

        return new HtmlString(
            '<style>
                .contract-group-episode{display:grid;gap:12px}
                .contract-group-episode__summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}
                .contract-group-episode__fact{border:1px solid rgba(148,163,184,.25);border-radius:8px;padding:10px;background:#fff}
                .dark .contract-group-episode__fact{background:rgba(255,255,255,.04);border-color:rgba(148,163,184,.2)}
                .contract-group-episode__label{font-size:12px;color:#64748b;line-height:1.2}
                .contract-group-episode__value{margin-top:4px;font-size:14px;font-weight:650;color:#0f172a;line-height:1.35;overflow-wrap:anywhere}
                .dark .contract-group-episode__value{color:#e5e7eb}
                .contract-group-episode__badge{display:inline-flex;align-items:center;border-radius:999px;border:1px solid transparent;padding:3px 8px;font-size:12px;font-weight:700}
                .contract-group-episode__badge--success{border-color:#bbf7d0;background:#dcfce7;color:#166534}
                .contract-group-episode__badge--warning{border-color:#fde68a;background:#fef3c7;color:#92400e}
                .contract-group-episode__note{font-size:13px;line-height:1.45;color:#64748b}
                .contract-group-episode__table-wrap{overflow-x:auto;border:1px solid rgba(148,163,184,.25);border-radius:8px}
                .contract-group-episode__table{width:100%;min-width:560px;border-collapse:collapse;background:#fff}
                .dark .contract-group-episode__table{background:rgba(255,255,255,.03)}
                .contract-group-episode__table th{padding:9px 10px;text-align:left;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0;color:#64748b;background:#f8fafc;border-bottom:1px solid rgba(148,163,184,.25)}
                .dark .contract-group-episode__table th{background:rgba(255,255,255,.05)}
                .contract-group-episode__table td{padding:9px 10px;font-size:13px;color:#334155;border-bottom:1px solid rgba(148,163,184,.16)}
                .dark .contract-group-episode__table td{color:#e5e7eb}
                .contract-group-episode__table tr:last-child td{border-bottom:0}
                .contract-group-episode__empty{text-align:center;color:#64748b}
                @media (max-width:1100px){.contract-group-episode__summary{grid-template-columns:repeat(2,minmax(0,1fr))}}
                @media (max-width:640px){.contract-group-episode__summary{grid-template-columns:1fr}}
            </style>
            <div class="contract-group-episode">
                <div class="contract-group-episode__summary">
                    <div class="contract-group-episode__fact">
                        <div class="contract-group-episode__label">Группа</div>
                        <div class="contract-group-episode__value">'.e($parentLabel).'</div>
                    </div>
                    <div class="contract-group-episode__fact">
                        <div class="contract-group-episode__label">Дата проверки</div>
                        <div class="contract-group-episode__value">'.e($asOfLabel).'</div>
                    </div>
                    <div class="contract-group-episode__fact">
                        <div class="contract-group-episode__label">Источник состава</div>
                        <div class="contract-group-episode__value"><span class="contract-group-episode__badge '.e($sourceClass).'">'.e($sourceLabel).'</span></div>
                    </div>
                    <div class="contract-group-episode__fact">
                        <div class="contract-group-episode__label">Период эпизода</div>
                        <div class="contract-group-episode__value">'.e($periodLabel).'</div>
                    </div>
                </div>
                <div class="contract-group-episode__note">'.e($message).'</div>
                <div class="contract-group-episode__table-wrap">
                    <table class="contract-group-episode__table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Слот</th>
                                <th>Место</th>
                                <th>Площадь</th>
                            </tr>
                        </thead>
                        <tbody>'.$rowsHtml.'</tbody>
                    </table>
                </div>
            </div>'
        );
    }

    private static function historyChainPreview(?TenantContract $record): HtmlString
    {
        if (! $record) {
            return new HtmlString('<div class="text-sm text-gray-500">Нет данных.</div>');
        }

        $classified = static::classificationForRecord($record);
        $token = trim((string) ($classified['place_token'] ?? ''));
        $chainKey = static::contractHistoryChainKey($record, $classified);

        if ($record->excludesFromSpaceMapping()) {
            return new HtmlString('<div class="rounded-xl border border-dashed border-gray-300 bg-gray-50 px-4 py-3 text-sm text-gray-600">Договор явно исключен из привязки к месту и не участвует в исторической цепочке по месту.</div>');
        }

        if (! ($classified['actionable'] ?? false) || $token === '' || $chainKey === null) {
            return new HtmlString('<div class="rounded-xl border border-dashed border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-800">Для этого документа цепочка по месту не строится: нет надежного токена места.</div>');
        }

        $contracts = TenantContract::query()
            ->where('market_id', (int) $record->market_id)
            ->with([
                'tenant:id,name,short_name',
                'marketSpace:id,market_id,number,display_name,code,space_group_role,space_group_parent_id',
            ])
            ->get(['id', 'market_id', 'tenant_id', 'market_space_id', 'number', 'starts_at', 'ends_at', 'signed_at', 'status', 'is_active', 'external_id', 'space_mapping_mode']);

        $items = [];

        foreach ($contracts as $candidate) {
            $candidateClassification = static::classificationForRecord($candidate);
            if ($candidate->excludesFromSpaceMapping()) {
                continue;
            }

            if (! ($candidateClassification['actionable'] ?? false)) {
                continue;
            }

            if (static::contractHistoryChainKey($candidate, $candidateClassification) !== $chainKey) {
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
            return new HtmlString('<div class="rounded-xl border border-dashed border-gray-300 bg-gray-50 px-4 py-3 text-sm text-gray-600">Цепочка не найдена.</div>');
        }

        $html = '<style>
            .contract-history{display:grid;gap:10px;padding-bottom:72px}
            .contract-history__item{display:grid;grid-template-columns:minmax(0,1.2fr) minmax(0,1fr) minmax(190px,.7fr);gap:14px;align-items:start;border:1px solid #e2e8f0;border-radius:8px;background:#fff;padding:12px 14px}
            .contract-history__item--current{border-color:#bae6fd;background:#f0f9ff}
            .contract-history__number{display:flex;flex-wrap:wrap;gap:8px;align-items:center;font-size:14px;font-weight:700;color:#0f172a;line-height:1.35;overflow-wrap:anywhere}
            .contract-history__index{font-size:12px;font-weight:700;color:#64748b}
            .contract-history__meta{margin-top:4px;font-size:12px;color:#64748b;line-height:1.4}
            .contract-history__tenant{font-size:14px;font-weight:650;color:#111827;line-height:1.35;overflow-wrap:anywhere}
            .contract-history__space{margin-top:4px;font-size:12px;color:#64748b;line-height:1.4;overflow-wrap:anywhere}
            .contract-history__chips{display:flex;flex-wrap:wrap;gap:6px;justify-content:flex-end}
            .contract-history__chip{display:inline-flex;align-items:center;border-radius:999px;border:1px solid #e2e8f0;background:#f8fafc;color:#334155;padding:4px 8px;font-size:12px;font-weight:650;line-height:1.2}
            .contract-history__chip--sky{border-color:#bae6fd;background:#e0f2fe;color:#075985}
            .contract-history__chip--emerald{border-color:#bbf7d0;background:#dcfce7;color:#166534}
            .contract-history__chip--violet{border-color:#ddd6fe;background:#ede9fe;color:#5b21b6}
            .contract-history__chip--amber{border-color:#fde68a;background:#fef3c7;color:#92400e}
            .contract-history__chip--gray{border-color:#e2e8f0;background:#f8fafc;color:#475569}
            .dark .contract-history__item{background:rgba(15,23,42,.72);border-color:rgba(148,163,184,.24)}
            .dark .contract-history__item--current{background:rgba(8,47,73,.35);border-color:rgba(125,211,252,.35)}
            .dark .contract-history__number,.dark .contract-history__tenant{color:#f8fafc}
            @media (max-width:900px){.contract-history__item{grid-template-columns:1fr}.contract-history__chips{justify-content:flex-start}}
        </style><div class="contract-history">';

        foreach ($items as $index => $item) {
            /** @var TenantContract $chainRecord */
            $chainRecord = $item['record'];

            $tenantName = trim((string) ($chainRecord->tenant?->display_name ?? $chainRecord->tenant?->name ?? ''));
            $tenantShort = trim((string) ($chainRecord->tenant?->short_name ?? ''));
            $spaceLabel = $chainRecord->market_space_id ? static::spaceLabel($chainRecord) : '—';
            $isCurrent = (int) $chainRecord->id === (int) $record->id;
            $number = trim((string) ($chainRecord->number ?? '')) !== '' ? (string) $chainRecord->number : 'Без номера';
            $statusParts = [];

            if (static::isInLatestDebtSnapshot($chainRecord)) {
                $statusParts[] = static::historyChainChip('В последней задолженности', 'violet');
            }

            $statusParts[] = static::historyChainChip(
                static::contractStatusLabel($chainRecord->status),
                trim((string) $chainRecord->status) === 'active' ? 'emerald' : 'gray'
            );

            if ($isCurrent) {
                $statusParts[] = static::historyChainChip('Текущий', 'sky');
            }

            if ($statusParts === []) {
                $statusParts[] = static::historyChainChip('—', 'gray');
            }

            $html .= '<div class="contract-history__item'.($isCurrent ? ' contract-history__item--current' : '').'">';
            $html .= '<div>';
            $html .= '<div class="contract-history__number">';
            $html .= '<span class="contract-history__index">#'.e((string) ($index + 1)).'</span>';
            $html .= '<span>'.e($number).'</span>';
            $html .= '</div>';
            $html .= '<div class="contract-history__meta">Дата документа: '.e(static::formatClassifierDate($item['document_date'])).' · начало: '.e($chainRecord->starts_at?->format('d.m.Y') ?? '—').' · окончание: '.e($chainRecord->ends_at?->format('d.m.Y') ?? '—').'</div>';
            $html .= '</div>';
            $html .= '<div>';
            $html .= '<div class="contract-history__tenant">'.e($tenantName !== '' ? $tenantName : '—').'</div>';
            if ($tenantShort !== '' && $tenantShort !== $tenantName) {
                $html .= '<div class="contract-history__space">'.e($tenantShort).'</div>';
            }
            $html .= '<div class="contract-history__space">Место: '.e($spaceLabel).' · режим: '.e(static::spaceMappingModeLabel($chainRecord->space_mapping_mode)).'</div>';
            $html .= '</div>';
            $html .= '<div class="contract-history__chips">'.implode('', $statusParts).'</div>';
            $html .= '</div>';
        }

        $html .= '</div>';

        return new HtmlString($html);
    }

    private static function historyChainChip(string $label, string $variant = 'gray'): string
    {
        return '<span class="contract-history__chip contract-history__chip--'.e($variant).'">'.e($label).'</span>';
    }

    /**
     * @param array<string, mixed> $classified
     */
    private static function contractHistoryChainKey(TenantContract $record, array $classified): ?string
    {
        if ($record->market_space_id) {
            $documentDate = static::resolveRangeStart($record, is_string($classified['document_date'] ?? null) ? $classified['document_date'] : null);
            $resolution = app(MarketSpaceGroupEpisodeResolver::class)->forContract($record, $documentDate);
            $parent = $resolution['parent'] ?? null;

            if (($resolution['applies'] ?? false) && $parent instanceof MarketSpace) {
                return 'group-parent:'.(int) $parent->id.'|tenant:'.(int) ($record->tenant_id ?? 0);
            }
        }

        return static::contractPlaceTenantChainKey($record, trim((string) ($classified['place_token'] ?? '')));
    }

    private static function contractPlaceTenantChainKey(TenantContract $record, ?string $placeToken = null): ?string
    {
        $token = trim((string) ($placeToken ?? static::classificationForRecord($record)['place_token'] ?? ''));
        if ($token === '') {
            return null;
        }

        // Shared-use spaces can have several tenants on the same physical place.
        // Contract history must stay tenant-specific instead of merging all tenants by place token.
        $tenantId = (int) ($record->tenant_id ?? 0);

        return $token.'|tenant:'.$tenantId;
    }

    private static function chainDisplay(TenantContract $record): string
    {
        $stats = static::chainStatsFor($record);

        if ($stats['chain_count'] <= 0) {
            return '—';
        }

        return $stats['chain_position'].' из '.$stats['chain_count'];
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
    ): string {
        $parts = array_values(array_filter([
            trim((string) $displayName),
            trim((string) $number),
            trim((string) $code),
        ], static fn (string $value): bool => $value !== ''));

        $normalizedGroupToken = trim((string) $groupToken);
        $normalizedGroupSlot = trim((string) $groupSlot);

        if ($normalizedGroupToken !== '' || $normalizedGroupSlot !== '') {
            $groupLabel = trim($normalizedGroupToken.($normalizedGroupSlot !== '' ? ' / '.$normalizedGroupSlot : ''));
            if ($groupLabel !== '') {
                $parts[] = 'Группа '.$groupLabel;
            }
        }

        if ($parts === []) {
            return 'Без названия';
        }

        return implode(' · ', array_values(array_unique($parts)));
    }

    public static function hasTenantContractColumn(string $column): bool
    {
        try {
            if (! DbSchema::hasTable('tenant_contracts')) {
                return false;
            }

            $columns = static::$tableColumnsCache['tenant_contracts'] ?? null;
            if ($columns === null) {
                $columns = DbSchema::getColumnListing('tenant_contracts');
                static::$tableColumnsCache['tenant_contracts'] = $columns;
            }

            return in_array($column, $columns, true);
        } catch (Throwable) {
            return false;
        }
    }
}
