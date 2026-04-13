<?php
# app/Filament/Resources/StaffInvitationResource/Schemas/StaffInvitationForm.php

namespace App\Filament\Resources\StaffInvitationResource\Schemas;

use App\Support\RoleScenarioCatalog;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class StaffInvitationForm
{
    public static function configure(Schema $schema): Schema
    {
        $user = Filament::auth()->user();
        $selectedMarketId = static::selectedMarketIdFromSession();

        // --- Market field ---
        if ((bool) $user && method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            if (filled($selectedMarketId)) {
                $marketField = Forms\Components\Hidden::make('market_id')
                    ->default(fn () => (int) $selectedMarketId)
                    ->dehydrated(true);
            } else {
                $marketField = Forms\Components\Select::make('market_id')
                    ->label('Рынок')
                    ->relationship('market', 'name')
                    ->required()
                    ->searchable()
                    ->preload()
                    ->dehydrated(true);
            }
        } else {
            $marketField = Forms\Components\Hidden::make('market_id')
                ->default(fn () => $user?->market_id)
                ->dehydrated(true);
        }

        // --- Roles options (JSON array, not relationship) ---
        $roleOptions = Role::query()
            ->where('name', '!=', 'merchant')
            ->when(
                ! ((bool) $user && method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()),
                fn ($q) => $q->where('name', '!=', 'super-admin')
            )
            ->get()
            ->mapWithKeys(function ($record) {
                $name = (string) ($record->name ?? '');

                if ($name === '') {
                    return ['' => '—'];
                }

                $slug = Str::of($name)
                    ->trim()
                    ->lower()
                    ->replace('_', '-')
                    ->replace(' ', '-')
                    ->replace('--', '-')
                    ->toString();

                $key = "roles.{$slug}";
                $translated = __($key);

                if ($translated !== $key) {
                    return [$name => $translated];
                }

                return [$name => RoleScenarioCatalog::labelForSlug($slug, $name)];
            })
            ->all();

        // --- Invited by (auto) ---
        $invitedBy = Forms\Components\Hidden::make('invited_by')
            ->default(fn () => $user?->id)
            ->dehydrated(true);

        return $schema->components([
            Section::make('Приглашение')
                ->description('Отправьте приглашение по email для нового сотрудника')
                ->columns(2)
                ->schema([
                    $marketField,

                    Forms\Components\TextInput::make('email')
                        ->label('Email')
                        ->email()
                        ->required()
                        ->maxLength(255)
                        ->unique(ignoreRecord: true)
                        ->placeholder('new-user@example.com')
                        ->autocomplete('new-email')
                        ->columnSpan(1),

                    Forms\Components\Select::make('roles')
                        ->label('Роли')
                        ->multiple()
                        ->options($roleOptions)
                        ->searchable()
                        ->placeholder('Выберите роли')
                        ->dehydrated(true)
                        ->columnSpan(1),

                    $invitedBy,

                    Forms\Components\DateTimePicker::make('expires_at')
                        ->label('Действует до')
                        ->default(fn () => now()->addDays(7))
                        ->helperText('По умолчанию — 7 дней')
                        ->seconds(false)
                        ->columnSpan(1),
                ])
                ->columnSpan(2),
        ]);
    }

    public static function editForm(Schema $schema): Schema
    {
        $user = Filament::auth()->user();
        $selectedMarketId = static::selectedMarketIdFromSession();

        if ((bool) $user && method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            if (filled($selectedMarketId)) {
                $marketField = Forms\Components\Hidden::make('market_id')
                    ->default(fn () => (int) $selectedMarketId)
                    ->dehydrated(true);
            } else {
                $marketField = Forms\Components\Select::make('market_id')
                    ->label('Рынок')
                    ->relationship('market', 'name')
                    ->required()
                    ->searchable()
                    ->preload()
                    ->dehydrated(true);
            }
        } else {
            $marketField = Forms\Components\Hidden::make('market_id')
                ->default(fn () => $user?->market_id)
                ->dehydrated(true);
        }

        $roleOptions = Role::query()
            ->where('name', '!=', 'merchant')
            ->when(
                ! ((bool) $user && method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()),
                fn ($q) => $q->where('name', '!=', 'super-admin')
            )
            ->get()
            ->mapWithKeys(function ($record) {
                $name = (string) ($record->name ?? '');

                if ($name === '') {
                    return ['' => '—'];
                }

                $slug = Str::of($name)
                    ->trim()
                    ->lower()
                    ->replace('_', '-')
                    ->replace(' ', '-')
                    ->replace('--', '-')
                    ->toString();

                $key = "roles.{$slug}";
                $translated = __($key);

                if ($translated !== $key) {
                    return [$name => $translated];
                }

                return [$name => RoleScenarioCatalog::labelForSlug($slug, $name)];
            })
            ->all();

        return $schema->components([
            Section::make('Приглашение')
                ->description('Информация о приглашении')
                ->columns(2)
                ->schema([
                    $marketField,

                    Forms\Components\TextInput::make('email')
                        ->label('Email')
                        ->email()
                        ->required()
                        ->maxLength(255)
                        ->autocomplete('new-email')
                        ->columnSpan(1),

                    Forms\Components\Select::make('roles')
                        ->label('Роли')
                        ->multiple()
                        ->options($roleOptions)
                        ->searchable()
                        ->dehydrated(true)
                        ->columnSpan(1),

                    Forms\Components\Select::make('invited_by')
                        ->label('Кем приглашён')
                        ->relationship('inviter', 'name', function ($query) use ($user) {
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
                        })
                        ->searchable()
                        ->preload()
                        ->columnSpan(1),

                    Forms\Components\TextInput::make('token_hash')
                        ->label('Хэш токена')
                        ->disabled()
                        ->dehydrated(false)
                        ->columnSpan(1),

                    Forms\Components\DateTimePicker::make('expires_at')
                        ->label('Действует до')
                        ->seconds(false)
                        ->columnSpan(1),

                    Forms\Components\DateTimePicker::make('accepted_at')
                        ->label('Принят')
                        ->disabled()
                        ->dehydrated(false)
                        ->columnSpan(1),
                ])
                ->columnSpan(2),
        ]);
    }

    protected static function selectedMarketIdFromSession(): ?int
    {
        $panelId = Filament::getCurrentPanel()?->getId() ?? 'admin';
        $key = "filament_{$panelId}_market_id";

        $value = session($key);

        return filled($value) ? (int) $value : null;
    }
}
