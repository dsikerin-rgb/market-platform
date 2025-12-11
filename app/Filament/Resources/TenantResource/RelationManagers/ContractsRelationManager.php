<?php

namespace App\Filament\Resources\TenantResource\RelationManagers;

use App\Models\MarketSpace;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ContractsRelationManager extends RelationManager
{
    protected static string $relationship = 'contracts';

    protected static ?string $title = 'Договоры';

    protected static ?string $recordTitleAttribute = 'number';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Components\Hidden::make('market_id')
                ->default(fn (RelationManager $livewire) => $livewire->getOwnerRecord()->market_id)
                ->dehydrated(true),
            Forms\Components\Hidden::make('tenant_id')
                ->default(fn (RelationManager $livewire) => $livewire->getOwnerRecord()->id)
                ->dehydrated(true),
            Forms\Components\Select::make('market_space_id')
                ->label('Торговое место')
                ->options(function (RelationManager $livewire) {
                    $marketId = $livewire->getOwnerRecord()->market_id;

                    return MarketSpace::where('market_id', $marketId)
                        ->orderBy('number')
                        ->pluck('number', 'id');
                })
                ->searchable()
                ->preload()
                ->nullable(),
            Forms\Components\TextInput::make('number')
                ->label('Номер договора')
                ->required()
                ->maxLength(50),
            Forms\Components\Select::make('status')
                ->label('Статус')
                ->options([
                    'draft' => 'Черновик',
                    'active' => 'Активен',
                    'paused' => 'Приостановлен',
                    'terminated' => 'Расторгнут',
                    'archived' => 'Архив',
                ])
                ->default('draft'),
            Forms\Components\DatePicker::make('starts_at')
                ->label('Дата начала')
                ->required(),
            Forms\Components\DatePicker::make('ends_at')
                ->label('Дата окончания')
                ->nullable(),
            Forms\Components\DatePicker::make('signed_at')
                ->label('Дата подписания')
                ->nullable(),
            Forms\Components\TextInput::make('monthly_rent')
                ->label('Арендная ставка в месяц')
                ->numeric()
                ->step('0.01')
                ->nullable(),
            Forms\Components\Select::make('currency')
                ->label('Валюта')
                ->options([
                    'RUB' => '₽',
                    'USD' => '$',
                    'EUR' => '€',
                ])
                ->nullable(),
            Forms\Components\Toggle::make('is_active')
                ->label('Активен')
                ->default(true),
            Forms\Components\Textarea::make('notes')
                ->label('Примечания')
                ->columnSpanFull()
                ->nullable(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('market.name')
                    ->label('Рынок'),
                TextColumn::make('number')
                    ->label('Номер договора')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('marketSpace.number')
                    ->label('Торговое место')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('status')
                    ->label('Статус')
                    ->formatStateUsing(fn (?string $state) => match ($state) {
                        'draft' => 'Черновик',
                        'active' => 'Активен',
                        'paused' => 'Приостановлен',
                        'terminated' => 'Расторгнут',
                        'archived' => 'Архив',
                        default => $state,
                    }),
                TextColumn::make('starts_at')
                    ->label('Начало')
                    ->date(),
                TextColumn::make('ends_at')
                    ->label('Окончание')
                    ->date(),
                TextColumn::make('monthly_rent')
                    ->label('Аренда в месяц')
                    ->numeric(decimalPlaces: 2),
                IconColumn::make('is_active')
                    ->label('Активен')
                    ->boolean(),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->label('Редактировать'),
                Tables\Actions\DeleteAction::make()
                    ->label('Удалить'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->label('Удалить выбранные'),
                ]),
            ]);
    }

    public function getTableQuery(): Builder
    {
        $query = parent::getTableQuery();
        $user = Filament::auth()->user();

        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->isSuperAdmin()) {
            return $query;
        }

        if ($user->market_id) {
            return $query->where('market_id', $user->market_id);
        }

        return $query->whereRaw('1 = 0');
    }
}
