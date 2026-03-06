<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PermissionResource\Pages;
use App\Support\PermissionDisplayCatalog;
use Filament\Facades\Filament;
use Filament\Forms;
use App\Filament\Resources\BaseResource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PermissionResource extends BaseResource
{
    protected static ?string $model = \Spatie\Permission\Models\Permission::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'Право';

    protected static ?string $pluralModelLabel = 'Права';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $navigationLabel = 'Права';

    protected static \UnitEnum|string|null $navigationGroup = 'Настройки';

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-key';

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function getGloballySearchableAttributes(): array
    {
        return [
            'name',
            'guard_name',
        ];
    }

    public static function form(Schema $schema): Schema
    {
        $nameField = Forms\Components\TextInput::make('name')
            ->label('Код права')
            ->required()
            ->maxLength(255);

        $guardField = Forms\Components\Hidden::make('guard_name')
            ->default('web')
            ->dehydrated(true);

        if (class_exists(Forms\Components\Grid::class)) {
            return $schema->components([
                Forms\Components\Grid::make(2)->components([
                    $nameField,
                ]),
                $guardField,
            ]);
        }

        return $schema->components([
            $nameField,
            $guardField,
        ]);
    }

    public static function table(Table $table): Table
    {
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

        return $table
            ->columns([
                TextColumn::make('display_group')
                    ->label('Группа')
                    ->getStateUsing(fn ($record): string => PermissionDisplayCatalog::group((string) $record->name))
                    ->badge()
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('name', $direction)),

                TextColumn::make('display_label')
                    ->label('Название')
                    ->getStateUsing(fn ($record): string => PermissionDisplayCatalog::label((string) $record->name))
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->where('name', 'like', '%' . $search . '%');
                    }),

                TextColumn::make('name')
                    ->label('Код права')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('guard_name')
                    ->label('Guard')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('Создано')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions($actions);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPermissions::route('/'),
            'create' => Pages\CreatePermission::route('/create'),
            'edit' => Pages\EditPermission::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = Filament::auth()->user();

        if (! $user || ! method_exists($user, 'isSuperAdmin') || ! $user->isSuperAdmin()) {
            return $query->whereRaw('1 = 0');
        }

        return $query;
    }

    public static function canViewAny(): bool
    {
        $user = Filament::auth()->user();

        return (bool) $user && method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin();
    }

    public static function canCreate(): bool
    {
        $user = Filament::auth()->user();

        return (bool) $user && method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin();
    }

    public static function canEdit($record): bool
    {
        $user = Filament::auth()->user();

        return (bool) $user && method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin();
    }

    public static function canDelete($record): bool
    {
        $user = Filament::auth()->user();

        return (bool) $user && method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin();
    }
}
