<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ReportRunResource\Pages;
use App\Models\ReportRun;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ReportRunResource extends Resource
{
    protected static ?string $model = ReportRun::class;

    protected static ?string $modelLabel = 'Запуск отчёта';
    protected static ?string $pluralModelLabel = 'Запуски отчётов';

    /**
     * Главное: скрываем из левого меню.
     * Доступ остаётся по URL и через ReportsHub.
     */
    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $navigationLabel = 'Запуски отчётов';
    protected static \UnitEnum|string|null $navigationGroup = 'Отчёты';
    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-play';

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
                ->preload(),

            Forms\Components\DateTimePicker::make('started_at')
                ->label('Начало')
                ->required(),

            Forms\Components\DateTimePicker::make('finished_at')
                ->label('Завершение'),

            Forms\Components\TextInput::make('status')
                ->label('Статус')
                ->maxLength(255),

            Forms\Components\TextInput::make('file_path')
                ->label('Файл')
                ->maxLength(255),

            Forms\Components\Textarea::make('error')
                ->label('Ошибка')
                ->rows(3),
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

        $table = $table
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
                    ->sortable(),

                TextColumn::make('started_at')
                    ->label('Начало')
                    ->dateTime()
                    ->sortable(),

                TextColumn::make('finished_at')
                    ->label('Завершение')
                    ->dateTime()
                    ->sortable(),

                IconColumn::make('file_path')
                    ->label('Файл')
                    ->boolean(fn (?string $state) => filled($state)),
            ])
            ->recordUrl(fn (ReportRun $record): ?string => static::canEdit($record)
                ? static::getUrl('edit', ['record' => $record])
                : null);

        $actions = [];

        if (class_exists(\Filament\Actions\EditAction::class)) {
            $actions[] = \Filament\Actions\EditAction::make()->label('Редактировать');
        } elseif (class_exists(\Filament\Tables\Actions\EditAction::class)) {
            $actions[] = \Filament\Tables\Actions\EditAction::make()->label('Редактировать');
        }

        if (class_exists(\Filament\Actions\DeleteAction::class)) {
            $actions[] = \Filament\Actions\DeleteAction::make()->label('Удалить');
        } elseif (class_exists(\Filament\Tables\Actions\DeleteAction::class)) {
            $actions[] = \Filament\Tables\Actions\DeleteAction::make()->label('Удалить');
        }

        if (! empty($actions)) {
            $table = $table->actions($actions);
        }

        return $table;
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListReportRuns::route('/'),
            'create' => Pages\CreateReportRun::route('/create'),
            'edit' => Pages\EditReportRun::route('/{record}/edit'),
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

        $report = $record->report;

        return $report && $user->market_id && $report->market_id === $user->market_id;
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

        $report = $record->report;

        return $report && $user->market_id && $report->market_id === $user->market_id;
    }
}
