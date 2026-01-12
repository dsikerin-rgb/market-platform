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

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'name';

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

    protected static function selectedMarketIdFromSession(): ?int
    {
        $panelId = Filament::getCurrentPanel()?->getId() ?? 'admin';
        $key = "filament_{$panelId}_market_id";

        $value = session($key);

        return filled($value) ? (int) $value : null;
    }

    /**
     * Пункт меню видят те, у кого есть staff.viewAny
     */
    public static function shouldRegisterNavigation(): bool
    {
        $user = Filament::auth()->user();

        return (bool) $user && $user->can('staff.viewAny');
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

    /**
     * Мультитенант-фильтр: super-admin может смотреть по выбранному рынку,
     * остальные — только в рамках своего market_id.
     */
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
     * Доступ к ресурсу: строго по permissions
     */
    public static function canViewAny(): bool
    {
        $user = Filament::auth()->user();

        return (bool) $user && $user->can('staff.viewAny');
    }

    public static function canCreate(): bool
    {
        $user = Filament::auth()->user();

        return (bool) $user && $user->can('staff.create');
    }

    public static function canEdit($record): bool
    {
        /** @var \App\Models\User|null $user */
        $user = Filament::auth()->user();

        if (! $user) {
            return false;
        }

        if (! $user->can('staff.update')) {
            return false;
        }

        // Никто кроме super-admin не должен редактировать super-admin
        if (
            ! (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin())
            && method_exists($record, 'hasRole')
            && $record->hasRole('super-admin')
        ) {
            return false;
        }

        // super-admin — можно (EloquentQuery уже режет по выбранному рынку, если выбран)
        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return true;
        }

        // market-admin и прочие — только в рамках своего рынка
        if (! $user->market_id) {
            return false;
        }

        return (int) $record->market_id === (int) $user->market_id;
    }

    public static function canDelete($record): bool
    {
        /** @var \App\Models\User|null $user */
        $user = Filament::auth()->user();

        if (! $user) {
            return false;
        }

        if (! $user->can('staff.delete')) {
            return false;
        }

        // Запрет на удаление самого себя
        if (isset($record->id) && (int) $record->id === (int) $user->id) {
            return false;
        }

        // Никто кроме super-admin не должен удалять super-admin
        if (
            ! (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin())
            && method_exists($record, 'hasRole')
            && $record->hasRole('super-admin')
        ) {
            return false;
        }

        // super-admin — можно
        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return true;
        }

        // остальные — только в рамках своего рынка
        if (! $user->market_id) {
            return false;
        }

        return (int) $record->market_id === (int) $user->market_id;
    }
}
