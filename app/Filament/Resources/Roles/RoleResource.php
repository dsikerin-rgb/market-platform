<?php

namespace App\Filament\Resources\Roles;

use App\Filament\Resources\Roles\Pages\CreateRole;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\Roles\Pages\ListRoles;
use App\Filament\Resources\Roles\Schemas\RoleForm;
use App\Filament\Resources\Roles\Tables\RolesTable;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class RoleResource extends Resource
{
    protected static ?string $model = \Spatie\Permission\Models\Role::class;

    protected static ?string $modelLabel = 'Роль';
    protected static ?string $pluralModelLabel = 'Роли';

    /**
     * ВАЖНО: убираем из левого меню.
     * Роли должны открываться со страницы-хаба "Настройки рынка".
     * Сам ресурс остаётся доступным по URL, доступ контролируется canViewAny/canCreate/etc.
     */
    protected static bool $shouldRegisterNavigation = false;

    // Метаданные оставляем (на меню не влияют при shouldRegisterNavigation=false)
    protected static ?string $navigationLabel = 'Роли';
    protected static \UnitEnum|string|null $navigationGroup = 'Настройки';
    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return RoleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RolesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRoles::route('/'),
            'create' => CreateRole::route('/create'),
            'edit' => EditRole::route('/{record}/edit'),
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
