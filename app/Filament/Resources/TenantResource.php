<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TenantResource\Pages;
use App\Filament\Resources\TenantResource\RelationManagers\ContractsRelationManager;
use App\Filament\Resources\TenantResource\RelationManagers\RequestsRelationManager;
use App\Models\Tenant;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class TenantResource extends Resource
{
    protected static ?string $model = Tenant::class;

    protected static ?string $modelLabel = 'Арендатор';
    protected static ?string $pluralModelLabel = 'Арендаторы';

    protected static ?string $navigationLabel = 'Арендаторы';

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

        return $schema->components([
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

            Forms\Components\TextInput::make('inn')
                ->label('ИНН')
                ->maxLength(20),

            Forms\Components\TextInput::make('ogrn')
                ->label('ОГРН / ОГРНИП')
                ->maxLength(20),

            Forms\Components\TextInput::make('phone')
                ->label('Телефон'),

            Forms\Components\TextInput::make('email')
                ->label('Email'),

            Forms\Components\TextInput::make('contact_person')
                ->label('Контактное лицо'),

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

            Forms\Components\Textarea::make('notes')
                ->label('Примечания')
                ->rows(3)
                ->columnSpanFull(),
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
                            default => $s, // поддержка исторических значений типа "ИП/ООО/АО"
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
                        // исторические значения из импорта
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
        // Коррелированные сабквери: безопасно для SQLite и не требует отношений в модели.
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
