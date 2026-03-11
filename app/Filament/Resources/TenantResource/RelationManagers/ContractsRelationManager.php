<?php

declare(strict_types=1);

namespace App\Filament\Resources\TenantResource\RelationManagers;

use App\Filament\Resources\TenantContractResource;
use Filament\Facades\Filament;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ContractsRelationManager extends RelationManager
{
    protected static string $relationship = 'contracts';

    protected static ?string $title = 'Договоры';

    protected static ?string $recordTitleAttribute = 'number';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        $user = Filament::auth()->user();

        return $table
            ->columns([
                TextColumn::make('market.name')
                    ->label('Рынок')
                    ->visible(fn () => (bool) $user && method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()),

                TextColumn::make('number')
                    ->label('Номер договора')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('marketSpace.number')
                    ->label('Торговое место')
                    ->sortable()
                    ->searchable()
                    ->placeholder('—'),

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
                    ->date()
                    ->placeholder('—'),

                TextColumn::make('monthly_rent')
                    ->label('Аренда в месяц')
                    ->numeric(decimalPlaces: 2)
                    ->placeholder('—'),

                IconColumn::make('is_active')
                    ->label('Активен')
                    ->boolean(),
            ])
            ->headerActions([])
            ->actions([])
            ->bulkActions([])
            ->recordUrl(function ($record): ?string {
                return $record && TenantContractResource::canEdit($record)
                    ? TenantContractResource::getUrl('edit', ['record' => $record])
                    : null;
            });
    }

    public function getTableQuery(): Builder
    {
        $user = Filament::auth()->user();

        /** @var Builder $query */
        $query = $this->getRelationship()->getQuery();

        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return $query;
        }

        if ($user->market_id) {
            return $query->where('market_id', $user->market_id);
        }

        return $query->whereRaw('1 = 0');
    }
}
