<?php

namespace App\Filament\Resources\Staff;

use App\Filament\Resources\Staff\Pages\CreateStaff;
use App\Filament\Resources\Staff\Pages\EditStaff;
use App\Filament\Resources\Staff\Pages\ListStaff;
use App\Filament\Resources\Staff\Schemas\StaffForm;
use App\Filament\Resources\Staff\Tables\StaffTable;
use App\Models\User;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class StaffResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $modelLabel = 'Сотрудник';
    protected static ?string $pluralModelLabel = 'Сотрудники';
    protected static ?string $navigationLabel = 'Сотрудники';

    /**
     * Группа динамическая:
     * super-admin -> "Рынки"
     * остальные -> "Рынок"
     */
    public static function getNavigationGroup(): ?string
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return null;
        }

        return (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin())
            ? 'Рынки'
            : 'Рынок';
    }

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'name';

    protected static function selectedMarketIdFromSession(): ?int
    {
        $panelId = Filament::getCurrentPanel()?->getId() ?? 'admin';
        $key = "filament_{$panelId}_market_id";

        $value = session($key);

        return filled($value) ? (int) $value : null;
    }

    /**
     * Пункт меню видят: super-admin и market-admin
     */
    public static function shouldRegisterNavigation(): bool
    {
        $user = Filament::auth()->user();

        return (bool) $user
            && (
                (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin())
                || (method_exists($user, 'hasRole') && $user->hasRole('market-admin'))
            );
    }

    public static function form(Schema $schema): Schema
    {
        return StaffForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return StaffTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStaff::route('/'),
            'create' => CreateStaff::route('/create'),
            'edit' => EditStaff::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = Filament::auth()->user();

        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            $selectedMarketId = static::selectedMarketIdFromSession();

            return filled($selectedMarketId)
                ? $query->where('market_id', (int) $selectedMarketId)
                : $query;
        }

        if ($user->market_id) {
            return $query->where('market_id', (int) $user->market_id);
        }

        return $query->whereRaw('1 = 0');
    }

    /**
     * Доступ к ресурсу: super-admin и market-admin
     */
    public static function canViewAny(): bool
    {
        $user = Filament::auth()->user();

        return (bool) $user
            && (
                (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin())
                || $user->hasRole('market-admin')
            );
    }

    public static function canCreate(): bool
    {
        $user = Filament::auth()->user();

        return (bool) $user
            && (
                (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin())
                || $user->hasRole('market-admin')
            );
    }

    public static function canEdit($record): bool
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return false;
        }

        if (
            ! (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin())
            && method_exists($record, 'hasRole')
            && $record->hasRole('super-admin')
        ) {
            return false;
        }

        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return true;
        }

        return (bool) $user->market_id && (int) $record->market_id === (int) $user->market_id;
    }

    public static function canDelete($record): bool
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return false;
        }

        if (
            ! (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin())
            && method_exists($record, 'hasRole')
            && $record->hasRole('super-admin')
        ) {
            return false;
        }

        return method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin();
    }
}
