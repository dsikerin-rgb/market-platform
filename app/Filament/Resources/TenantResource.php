<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TenantResource\Pages;
use App\Models\Tenant;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TenantResource extends Resource
{
    protected static ?string $model = Tenant::class;

    protected static ?string $modelLabel = 'Арендатор';

    protected static ?string $pluralModelLabel = 'Арендаторы';

    protected static ?string $navigationLabel = 'Арендаторы';

    protected static \UnitEnum|string|null $navigationGroup = 'Рынки';

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-users';

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
                ->default($user?->market_id)
                ->disabled(fn () => $user && ! $user->isSuperAdmin())
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
                ]),
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
                ]),
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
        return $table
            ->columns([
                TextColumn::make('market.name')
                    ->label('Рынок')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('name')
                    ->label('Название арендатора')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('short_name')
                    ->label('Краткое название')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('inn')
                    ->label('ИНН')
                    ->searchable(),
                TextColumn::make('phone')
                    ->label('Телефон')
                    ->searchable(),
                TextColumn::make('status')
                    ->label('Статус')
                    ->formatStateUsing(fn (?string $state) => match ($state) {
                        'active' => 'В аренде',
                        'paused' => 'Приостановлено',
                        'finished' => 'Завершён договор',
                        default => $state,
                    })
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label('Активен')
                    ->boolean(),
                TextColumn::make('created_at')
                    ->label('Создан')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                //
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

    public static function getRelations(): array
    {
        return [];
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
            return $query;
        }

        if ($user->market_id) {
            return $query->where('market_id', $user->market_id);
        }

        return $query->whereRaw('1 = 0');
    }

    public static function canViewAny(): bool
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return false;
        }

        return $user->isSuperAdmin() || (bool) $user->market_id;
    }

    public static function canCreate(): bool
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return false;
        }

        return $user->isSuperAdmin() || (bool) $user->market_id;
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
