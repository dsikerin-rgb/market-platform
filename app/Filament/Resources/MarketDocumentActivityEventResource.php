<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\MarketDocumentActivityEventResource\Pages;
use App\Models\MarketDocumentActivityEvent;
use App\Support\MarketContext;
use Filament\Facades\Filament;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MarketDocumentActivityEventResource extends BaseResource
{
    protected static ?string $model = MarketDocumentActivityEvent::class;

    protected static ?string $slug = 'market-document-activity';

    protected static ?string $navigationLabel = 'Журнал диска';

    protected static ?string $modelLabel = 'событие диска';

    protected static ?string $pluralModelLabel = 'Журнал диска';

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-clock';

    protected static ?int $navigationSort = 76;

    protected static bool $shouldRegisterNavigation = false;

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        $user = Filament::auth()->user();

        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('Когда')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->width('132px'),

                TextColumn::make('action')
                    ->label('Действие')
                    ->formatStateUsing(fn (?string $state, MarketDocumentActivityEvent $record): string => $record->actionLabel())
                    ->badge()
                    ->sortable(),

                TextColumn::make('document_name')
                    ->label('Файл')
                    ->searchable()
                    ->wrap()
                    ->grow(),

                TextColumn::make('actor.name')
                    ->label('Кто')
                    ->placeholder('Система')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('targetUser.name')
                    ->label('Кому')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('market.name')
                    ->label('Рынок')
                    ->visible(fn (): bool => (bool) $user && $user->isSuperAdmin())
                    ->sortable()
                    ->searchable(),

                TextColumn::make('visibility')
                    ->label('Раздел')
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'shared' => 'Общий',
                        'personal' => 'Личный',
                        default => '—',
                    })
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('action')
                    ->label('Действие')
                    ->options(MarketDocumentActivityEvent::actionOptions()),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateIcon('heroicon-o-clock')
            ->emptyStateHeading('Событий пока нет')
            ->emptyStateDescription('Здесь появятся загрузки, переносы, удаления, восстановления и выдача доступа к файлам.');
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->with(['market', 'actor', 'targetUser']);

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

        return $query->visibleFor($user);
    }

    public static function canViewAny(): bool
    {
        return MarketDocumentResource::canViewActivityLog();
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

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMarketDocumentActivityEvents::route('/'),
        ];
    }

    private static function selectedMarketIdFromSession(): ?int
    {
        return app(MarketContext::class)->selectedMarketIdFromSession();
    }
}
