<?php
# app/Filament/Resources/TenantAccruals/TenantAccrualResource.php

namespace App\Filament\Resources\TenantAccruals;

use App\Filament\Resources\TenantAccruals\Pages\EditTenantAccrual;
use App\Filament\Resources\TenantAccruals\Pages\ListTenantAccruals;
use App\Filament\Resources\TenantAccruals\Schemas\TenantAccrualForm;
use App\Filament\Resources\TenantAccruals\Tables\TenantAccrualsTable;
use App\Models\TenantAccrual;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TenantAccrualResource extends Resource
{
    protected static ?string $model = TenantAccrual::class;

    protected static ?string $modelLabel = 'Начисление';
    protected static ?string $pluralModelLabel = 'Начисления';
    protected static ?string $navigationLabel = 'Начисления';

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-document-text';

    public static function shouldRegisterNavigation(): bool
    {
        $user = Filament::auth()->user();

        return (bool) $user && ($user->isSuperAdmin() || (bool) $user->market_id);
    }

    public static function getNavigationGroup(): ?string
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return null;
        }

        return $user->isSuperAdmin() ? 'Рынки' : 'Рынок';
    }

    public static function getNavigationSort(): int
    {
        return 35;
    }

    protected static function selectedMarketIdFromSession(): ?int
    {
        $panelId = Filament::getCurrentPanel()?->getId() ?? 'admin';
        $key = "filament_{$panelId}_market_id";

        $value = session($key);

        return filled($value) ? (int) $value : null;
    }

    public static function form(Schema $schema): Schema
    {
        return TenantAccrualForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TenantAccrualsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        // Создание начислений вручную отключаем: источник — импорт.
        return [
            'index' => ListTenantAccruals::route('/'),
            'edit' => EditTenantAccrual::route('/{record}/edit'),
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
            $selectedMarketId = static::selectedMarketIdFromSession();

            return filled($selectedMarketId)
                ? $query->where('market_id', (int) $selectedMarketId)
                : $query;
        }

        if ($user->market_id) {
            return $query->where('market_id', $user->market_id);
        }

        return $query->whereRaw('1 = 0');
    }

    public static function canViewAny(): bool
    {
        $user = Filament::auth()->user();

        return (bool) $user && ($user->isSuperAdmin() || (bool) $user->market_id);
    }

    public static function canCreate(): bool
    {
        // Начисления создаются только импортом.
        return false;
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

        return (bool) $user->market_id && (int) $record->market_id === (int) $user->market_id;
    }

    public static function canDelete($record): bool
    {
        // Удаление начислений в прототипе запрещаем (чтобы не потерять историю импорта).
        return false;
    }
}
