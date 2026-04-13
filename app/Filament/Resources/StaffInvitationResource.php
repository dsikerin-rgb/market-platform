<?php

namespace App\Filament\Resources;

use App\Filament\Resources\StaffInvitationResource\Pages;
use App\Filament\Resources\StaffInvitationResource\Schemas\StaffInvitationForm;
use App\Models\StaffInvitation;
use Filament\Facades\Filament;
use App\Filament\Resources\BaseResource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class StaffInvitationResource extends BaseResource
{


    protected static ?string $model = StaffInvitation::class;

    protected static ?string $recordTitleAttribute = 'email';

    protected static ?string $modelLabel = 'Приглашение';
    protected static ?string $pluralModelLabel = 'Приглашения';

    /**
     * ВАЖНО: убираем из левого меню.
     * Открываем со страницы "Сотрудники" (кнопка), ресурс доступен по URL.
     */
    protected static bool $shouldRegisterNavigation = false;

    // Метаданные оставляем (на меню не влияют при shouldRegisterNavigation=false)
    protected static ?string $navigationLabel = 'Приглашения';
    protected static \UnitEnum|string|null $navigationGroup = null;
    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-envelope-open';

    protected static function selectedMarketIdFromSession(): ?int
    {
        $panelId = Filament::getCurrentPanel()?->getId() ?? 'admin';
        $key = "filament_{$panelId}_market_id";

        $value = session($key);

        return filled($value) ? (int) $value : null;
    }

    protected static function canManage(): bool
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return false;
        }

        $isSuperAdmin = method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin();
        $isMarketAdmin = method_exists($user, 'hasRole') && $user->hasRole('market-admin');

        return $isSuperAdmin || $isMarketAdmin;
    }

    
    public static function getGloballySearchableAttributes(): array
    {
        return [
            'email',
            'token_hash',
            'market.name',
        ];
    }
    public static function form(Schema $schema): Schema
    {
        return StaffInvitationForm::configure($schema);
    }

    public static function editForm(Schema $schema): Schema
    {
        return StaffInvitationForm::editForm($schema);
    }

    public static function table(Table $table): Table
    {
        $user = Filament::auth()->user();

        $table = $table
            ->columns([
                TextColumn::make('market.name')
                    ->label('Рынок')
                    ->sortable()
                    ->searchable()
                    ->visible(fn () => (bool) $user && method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()),

                TextColumn::make('email')
                    ->label('Email')
                    ->searchable(),

                TextColumn::make('expires_at')
                    ->label('Истекает')
                    ->dateTime()
                    ->sortable(),

                TextColumn::make('accepted_at')
                    ->label('Принят')
                    ->dateTime()
                    ->sortable(),

                IconColumn::make('roles')
                    ->label('Роли')
                    ->boolean(fn ($state) => ! empty($state)),
            ])
            ->recordUrl(fn (StaffInvitation $record): ?string => static::canEdit($record)
                ? static::getUrl('edit', ['record' => $record])
                : null);

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

        if (! empty($actions)) {
            $table = $table->actions($actions);
        }

        return $table;
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStaffInvitations::route('/'),
            'create' => Pages\CreateStaffInvitation::route('/create'),
            'edit' => Pages\EditStaffInvitation::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = Filament::auth()->user();

        if (! $user || ! static::canManage()) {
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

    public static function canViewAny(): bool
    {
        return static::canManage();
    }

    public static function canCreate(): bool
    {
        return static::canManage();
    }

    public static function canEdit($record): bool
    {
        $user = Filament::auth()->user();

        if (! $user || ! static::canManage()) {
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

        if (! $user || ! static::canManage()) {
            return false;
        }

        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return true;
        }

        return (bool) $user->market_id && (int) $record->market_id === (int) $user->market_id;
    }
}
