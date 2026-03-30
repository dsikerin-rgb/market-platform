<?php

declare(strict_types=1);

namespace App\Filament\Resources\TenantResource\RelationManagers;

use App\Filament\Resources\MarketSpaceResource;
use App\Models\MarketLocation;
use App\Models\MarketSpace;
use Filament\Facades\Filament;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SpacesRelationManager extends RelationManager
{
    protected static string $relationship = 'spaces';

    protected static ?string $title = 'Торговые места';

    protected static ?string $recordTitleAttribute = 'number';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        $owner = $this->getOwnerRecord();
        $marketId = $owner?->market_id ? (int) $owner->market_id : null;

        return $table
            ->defaultSort('number')
            ->columns([
                TextColumn::make('location.name')
                    ->label('Локация')
                    ->sortable()
                    ->searchable()
                    ->placeholder('—'),

                TextColumn::make('number')
                    ->label('Место')
                    ->sortable()
                    ->searchable()
                    ->placeholder('—')
                    ->url(fn (MarketSpace $record): ?string => MarketSpaceResource::canEdit($record)
                        ? MarketSpaceResource::getUrl('edit', ['record' => $record])
                        : null),

                TextColumn::make('display_name')
                    ->label('Название')
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('code')
                    ->label('Код')
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('activity_type')
                    ->label('Деятельность')
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('area_sqm')
                    ->label('Площадь, м²')
                    ->numeric(decimalPlaces: 2)
                    ->sortable()
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('rent_rate_value')
                    ->label('Ставка')
                    ->numeric(decimalPlaces: 2)
                    ->sortable()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): ?string => match ($state) {
                        'occupied' => 'Занято',
                        'vacant', 'free' => 'Свободно',
                        'reserved' => 'Зарезервировано',
                        'maintenance' => 'На обслуживании',
                        default => $state ?: '—',
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        'occupied' => 'success',
                        'vacant', 'free' => 'danger',
                        'reserved' => 'warning',
                        'maintenance' => 'gray',
                        default => 'gray',
                    })
                    ->toggleable(),

                IconColumn::make('is_active')
                    ->label('Активно')
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('location_id')
                    ->label('Локация')
                    ->options(fn (): array => $marketId
                        ? MarketLocation::query()
                            ->where('market_id', $marketId)
                            ->where('is_active', true)
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all()
                        : []),

                SelectFilter::make('status')
                    ->label('Статус')
                    ->options([
                        'occupied' => 'Занято',
                        'vacant' => 'Свободно',
                        'free' => 'Свободно (legacy)',
                        'reserved' => 'Зарезервировано',
                        'maintenance' => 'На обслуживании',
                    ]),

                TernaryFilter::make('is_active')
                    ->label('Активность')
                    ->trueLabel('Только активные')
                    ->falseLabel('Только неактивные')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->where('is_active', true),
                        false: fn (Builder $query): Builder => $query->where('is_active', false),
                        blank: fn (Builder $query): Builder => $query,
                    ),
            ])
            ->recordUrl(fn ($record): ?string => $record && MarketSpaceResource::canEdit($record)
                ? MarketSpaceResource::getUrl('edit', ['record' => $record])
                : null)
            ->recordActions([
                static::openAction(),
            ])
            ->headerActions([])
            ->bulkActions([])
            ->emptyStateHeading('Торговых мест пока нет')
            ->emptyStateDescription('Закрепленные за арендатором торговые места появятся здесь.');
    }

    public function getTableQuery(): Builder
    {
        $user = Filament::auth()->user();

        /** @var Builder $query */
        $query = $this->getRelationship()
            ->getQuery()
            ->with(['location']);

        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        $owner = $this->getOwnerRecord();
        if (! $owner) {
            return $query->whereRaw('1 = 0');
        }

        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return $query->where('market_id', (int) $owner->market_id);
        }

        if ($user->market_id && (int) $user->market_id === (int) $owner->market_id) {
            return $query->where('market_id', (int) $owner->market_id);
        }

        return $query->whereRaw('1 = 0');
    }

    private static function openAction()
    {
        if (class_exists(\Filament\Tables\Actions\Action::class)) {
            return \Filament\Tables\Actions\Action::make('open')
                ->label('')
                ->tooltip('Открыть')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->iconButton()
                ->url(fn (MarketSpace $record): string => MarketSpaceResource::getUrl('edit', ['record' => $record]));
        }

        return \Filament\Actions\Action::make('open')
            ->label('')
            ->tooltip('Открыть')
            ->icon('heroicon-o-arrow-top-right-on-square')
            ->iconButton()
            ->url(fn (MarketSpace $record): string => MarketSpaceResource::getUrl('edit', ['record' => $record]));
    }
}
