<?php
# app/Filament/Resources/TenantResource.php

namespace App\Filament\Resources;

use App\Filament\Resources\TenantResource\Pages;
use App\Filament\Resources\TenantResource\RelationManagers\ContractsRelationManager;
use App\Filament\Resources\TenantResource\RelationManagers\RequestsRelationManager;
use App\Models\Tenant;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Resources\Resource;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema as DbSchema;
use Illuminate\Support\HtmlString;
use Throwable;

class TenantResource extends Resource
{
    protected static ?string $model = Tenant::class;

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
                        Section::make('Сводка')
                            ->description('Ключевые показатели по начислениям (берём из tenant_accruals).')
                            ->schema([
                                // KPI-сводку рендерим через Blade partial, чтобы получить нормальную иерархию (бейджи/карточки),
                                // а не "анкетный" вид Placeholder’ов.
                                Forms\Components\Placeholder::make('accruals_summary_view')
                                    ->hiddenLabel()
                                    ->dehydrated(false)
                                    ->content(function (?Tenant $record): HtmlString {
                                        if (! $record) {
                                            return new HtmlString('<div style="font-size:13px;opacity:.85;">Сводка появится после сохранения арендатора.</div>');
                                        }

                                        $summary = static::accrualSummaryData($record);
                                        $summary['is_active'] = (bool) $record->is_active;

                                        return new HtmlString(
                                            view('filament.tenants.tenant-summary', ['summary' => $summary])->render()
                                        );
                                    })
                                    ->columnSpanFull(),
                            ]),

                        Section::make('Статус задолженности')
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
                    ]),

                Tab::make('Площади')
                    ->schema([
                        Section::make('Арендуемые площади')
                            ->description('Список мест по последнему периоду начислений. Это “истина” для финансов, даже если market_spaces.tenant_id не проставлен.')
                            ->schema([
                                Forms\Components\Placeholder::make('spaces_last_period')
                                    ->hiddenLabel()
                                    ->dehydrated(false)
                                    ->content(fn (?Tenant $record) => static::renderSpacesLastPeriod($record))
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
                    ->searchable()
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
                    ->label('Активен'),

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
            ->recordUrl(fn (Tenant $record): string => static::getUrl('edit', ['record' => $record]));
    }

    public static function getRelations(): array
    {
        return [
            ContractsRelationManager::class,
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
        $spacesLast = 0;
        $withoutSpace = 0;

        if ($lastPeriod) {
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

        $msHasStatus = static::hasColumn('market_spaces', 'status');
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
            : 'COALESCE(ta.source_place_name, "")';

        $placeStatusExpr = $msHasStatus ? 'COALESCE(ms.status, "")' : '""';

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
            "{$placeStatusExpr} as place_status",
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
                ...($msHasStatus ? ['ms.status'] : []),
            ])
            ->orderByRaw($placeCodeExpr . ' ASC')
            ->limit(500)
            ->get();

        $periodLabel = Carbon::parse((string) $lastPeriod)->format('Y-m');

        $totalRent = 0.0;
        $totalWithVat = 0.0;
        $totalArea = 0.0;

        $tableRows = '';
        foreach ($rows as $r) {
            $totalRent += (float) $r->rent_sum;
            $totalWithVat += (float) $r->total_with_vat_sum;

            $code = trim((string) ($r->place_code ?? ''));
            $name = (string) ($r->place_name ?? '');
            $status = (string) ($r->place_status ?? '');

            $codeLabel = $code !== '' ? $code : '—';

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
                    <td class="tenant-spaces__status">' . static::renderSpaceStatusBadge($status) . '</td>
                    ' . $areaCell . '
                    <td class="tenant-spaces__num">' . e(static::formatRub((float) $r->rent_sum)) . '</td>
                    <td class="tenant-spaces__num">' . e(static::formatRub((float) $r->total_with_vat_sum)) . '</td>
                </tr>
            ';
        }

        $areaHeader = $hasArea ? '<th class="tenant-spaces__num">Площадь</th>' : '';
        $colspan = $hasArea ? 6 : 5;

        $meta = [
            '<span>Период: <strong>' . e($periodLabel) . '</strong></span>',
            '<span class="tenant-spaces__dot">•</span>',
            '<span>Мест: <strong>' . e((string) $rows->count()) . '</strong></span>',
        ];

        if ($hasArea) {
            $meta[] = '<span class="tenant-spaces__dot">•</span>';
            $meta[] = '<span>Площадь: <strong>' . e(static::formatArea($totalArea)) . '</strong></span>';
        }

        $meta[] = '<span class="tenant-spaces__dot">•</span>';
        $meta[] = '<span>Итого аренда: <strong>' . e(static::formatRub($totalRent)) . '</strong></span>';
        $meta[] = '<span class="tenant-spaces__dot">•</span>';
        $meta[] = '<span>Итого с НДС: <strong>' . e(static::formatRub($totalWithVat)) . '</strong></span>';

        $style = '
<style>
.tenant-spaces{display:flex;flex-direction:column;gap:12px}
.tenant-spaces__meta{display:flex;flex-wrap:wrap;gap:10px;align-items:center;font-size:13px;line-height:1.35;opacity:.92}
.tenant-spaces__dot{opacity:.55}

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
.tenant-spaces__status{white-space:nowrap}
.tenant-spaces__name{min-width:240px}

.tenant-badge{display:inline-flex;align-items:center;gap:6px;padding:5px 9px;border-radius:999px;border:1px solid rgba(0,0,0,.10);background:rgba(0,0,0,.04);font-size:12px;font-weight:700;line-height:1}
.dark .tenant-badge{border-color:rgba(255,255,255,.12);background:rgba(255,255,255,.04)}
.tenant-badge--success{border-color:rgba(16,185,129,.30);background:rgba(16,185,129,.10)}
.dark .tenant-badge--success{border-color:rgba(16,185,129,.35);background:rgba(16,185,129,.12)}
.tenant-badge--warning{border-color:rgba(245,158,11,.35);background:rgba(245,158,11,.12)}
.dark .tenant-badge--warning{border-color:rgba(245,158,11,.40);background:rgba(245,158,11,.14)}
</style>';

        $html = $style . '
<div class="tenant-spaces">
    <div class="tenant-spaces__meta">' . implode('', $meta) . '</div>

    <div class="tenant-spaces__table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Место</th>
                    <th>Название</th>
                    <th>Статус</th>
                    ' . $areaHeader . '
                    <th class="tenant-spaces__num">Аренда</th>
                    <th class="tenant-spaces__num">Итого с НДС</th>
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

    private static function renderSpaceStatusBadge(?string $status): string
    {
        $s = trim((string) $status);

        if ($s === '') {
            return '<span class="tenant-badge">—</span>';
        }

        $label = match ($s) {
            'occupied' => 'Занято',
            'free' => 'Свободно',
            'reserved' => 'Бронь',
            default => $s,
        };

        $class = match ($s) {
            'occupied' => 'tenant-badge tenant-badge--success',
            'reserved' => 'tenant-badge tenant-badge--warning',
            default => 'tenant-badge',
        };

        return '<span class="' . e($class) . '">' . e($label) . '</span>';
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
