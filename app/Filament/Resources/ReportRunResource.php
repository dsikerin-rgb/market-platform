<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ReportRunResource\Pages;
use App\Models\ReportRun;
use Filament\Facades\Filament;
use Filament\Forms;
use App\Filament\Resources\BaseResource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class ReportRunResource extends BaseResource
{
    protected static ?string $slug = 'report-runs';

    protected static ?string $model = ReportRun::class;

    protected static ?string $recordTitleAttribute = 'status';

    protected static ?string $modelLabel = 'Запуск отчёта';
    protected static ?string $pluralModelLabel = 'Запуски отчётов';

    /**
     * Главное: скрываем из левого меню.
     * Доступ остаётся по URL и через ReportsHub.
     */
    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $navigationLabel = 'Запуски отчётов';
    protected static \UnitEnum|string|null $navigationGroup = null;
    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-play';

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
            'status',
            'report.type',
            'report.market.name',
        ];
    }

    public static function form(Schema $schema): Schema
    {
        $user = Filament::auth()->user();
        $selectedMarketId = static::selectedMarketIdFromSession();

        $formFields = [
            Forms\Components\Select::make('report_id')
                ->label('Отчёт')
                ->relationship('report', 'type', function (Builder $query) use ($user, $selectedMarketId) {
                    if (! $user) {
                        return $query->whereRaw('1 = 0');
                    }

                    if ($user->isSuperAdmin()) {
                        return filled($selectedMarketId)
                            ? $query->where('market_id', (int) $selectedMarketId)
                            : $query;
                    }

                    if ($user->market_id) {
                        return $query->where('market_id', $user->market_id);
                    }

                    return $query->whereRaw('1 = 0');
                })
                ->required()
                ->searchable()
                ->preload()
                ->disabled(),

            Forms\Components\DateTimePicker::make('started_at')
                ->label('Начало')
                ->required()
                ->disabled(),

            Forms\Components\DateTimePicker::make('finished_at')
                ->label('Завершение')
                ->disabled(),

            Forms\Components\TextInput::make('status')
                ->label('Статус')
                ->maxLength(255)
                ->disabled(),

            Forms\Components\TextInput::make('file_path')
                ->label('Файл')
                ->maxLength(255)
                ->disabled(),

            Forms\Components\Textarea::make('error')
                ->label('Ошибка')
                ->rows(3)
                ->disabled(),
        ];

        if (class_exists(Forms\Components\Grid::class)) {
            return $schema->components([
                Forms\Components\Grid::make(2)->components($formFields),
            ]);
        }

        return $schema->components($formFields);
    }

    public static function table(Table $table): Table
    {
        $user = Filament::auth()->user();

        return $table
            ->columns([
                TextColumn::make('report.market.name')
                    ->label('Рынок')
                    ->sortable()
                    ->searchable()
                    ->visible(fn () => (bool) $user && $user->isSuperAdmin()),

                TextColumn::make('report.type')
                    ->label('Отчёт')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('status')
                    ->label('Статус')
                    ->sortable()
                    ->badge(),

                TextColumn::make('started_at')
                    ->label('Начало')
                    ->dateTime()
                    ->sortable(),

                TextColumn::make('finished_at')
                    ->label('Завершение')
                    ->dateTime()
                    ->sortable(),

                TextColumn::make('file_path')
                    ->label('Файл')
                    ->formatStateUsing(fn (?string $state): string => filled($state) ? basename($state) : '—')
                    ->tooltip(fn (?string $state): ?string => filled($state) ? $state : null)
                    ->toggleable(),

                TextColumn::make('error')
                    ->label('Ошибка')
                    ->formatStateUsing(fn (?string $state): string => filled($state) ? Str::limit($state, 80) : '—')
                    ->tooltip(fn (?string $state): ?string => filled($state) ? $state : null)
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordUrl(null)
            ->emptyStateHeading('Запусков отчётов ещё нет')
            ->emptyStateDescription('Это служебный журнал выполнений. Записи появляются автоматически после запусков отчётов.');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListReportRuns::route('/'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = Filament::auth()->user();

        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        $selectedMarketId = static::selectedMarketIdFromSession();

        return $query->whereHas('report', function (Builder $query) use ($user, $selectedMarketId) {
            if ($user->isSuperAdmin()) {
                return filled($selectedMarketId)
                    ? $query->where('market_id', (int) $selectedMarketId)
                    : $query;
            }

            if ($user->market_id) {
                return $query->where('market_id', $user->market_id);
            }

            return $query->whereRaw('1 = 0');
        });
    }

    public static function canViewAny(): bool
    {
        $user = Filament::auth()->user();

        return (bool) $user && ($user->isSuperAdmin() || (bool) $user->market_id);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }
}
