<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\TenantContractResource\Pages;
use App\Models\MarketSpace;
use App\Models\TenantContract;
use App\Services\TenantContracts\ContractDocumentClassifier;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

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

    /** @var array<int, array<int, array{chain_count:int,chain_position:int,overlap_count:int}>> */
    private static array $chainStatsCache = [];

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
                        ->label('Начало')
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
                ->description('Редактируются только локальные поля mapping. Канонические данные договора остаются под управлением 1С.')
                ->schema([
                    Forms\Components\Select::make('market_space_id')
                        ->label('Торговое место')
                        ->options(function (?TenantContract $record): array {
                            if (! $record) {
                                return [];
                            }

                            $spaces = MarketSpace::query()
                                ->where('market_id', (int) $record->market_id)
                                ->orderByRaw('COALESCE(display_name, number, code)')
                                ->get(['id', 'display_name', 'number', 'code']);

                            $options = [];
                            foreach ($spaces as $space) {
                                $options[(int) $space->id] = static::spaceOptionLabel($space->display_name, $space->number, $space->code);
                            }

                            return $options;
                        })
                        ->searchable()
                        ->preload()
                        ->nullable()
                        ->helperText('Здесь задаётся только локальная привязка договора к торговому месту.'),

                    Forms\Components\Toggle::make('is_active')
                        ->label('Активен')
                        ->helperText('Локальный признак активности карточки договора в сервисе.'),

                    Forms\Components\Textarea::make('notes')
                        ->label('Заметки по mapping')
                        ->rows(5)
                        ->columnSpanFull(),
                ])
                ->columns(2),
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

                TextColumn::make('market_space_link')
                    ->label('Текущее место')
                    ->state(fn (TenantContract $record): string => static::spaceLabel($record))
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('starts_at')
                    ->label('Начало')
                    ->date('d.m.Y')
                    ->sortable()
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

            if (! ($classified['actionable'] ?? false) || $token === '') {
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

        if ($record->starts_at instanceof Carbon) {
            return $record->starts_at->format('Y-m-d');
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

    private static function spaceOptionLabel(?string $displayName, ?string $number, ?string $code): string
    {
        $parts = array_values(array_filter([
            trim((string) $displayName),
            trim((string) $number),
            trim((string) $code),
        ], static fn (string $value): bool => $value !== ''));

        if ($parts === []) {
            return 'Без названия';
        }

        return implode(' · ', array_values(array_unique($parts)));
    }
}
