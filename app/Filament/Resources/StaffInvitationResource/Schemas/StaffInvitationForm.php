<?php
# app/Filament/Resources/StaffInvitationResource/Schemas/StaffInvitationForm.php

namespace App\Filament\Resources\StaffInvitationResource\Schemas;

use App\Support\RoleScenarioCatalog;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Grid;
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

        // --- Roles multi-select ---
        $rolesSelect = Forms\Components\Select::make('roles')
            ->label('Роли')
            ->multiple()
            ->preload()
            ->searchable()
            ->placeholder('Выберите роли для приглашения')
            ->relationship(
                name: 'roles',
                titleAttribute: 'name',
                modifyQueryUsing: function ($query) use ($user) {
                    $query->where('name', '!=', 'merchant');

                    if (! $user || ! $user->isSuperAdmin()) {
                        $query->where('name', '!=', 'super-admin');
                    }

                    return $query;
                },
            )
            ->getOptionLabelFromRecordUsing(function ($record) {
                $name = (string) ($record->name ?? '');

                if ($name === '') {
                    return '—';
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
                    return $translated;
                }

                return RoleScenarioCatalog::labelForSlug($slug, $name);
            });

        // --- Invited by (auto) ---
        $invitedBy = Forms\Components\Hidden::make('invited_by')
            ->default(fn () => $user?->id)
            ->dehydrated(true);

        return $schema->components([
            Section::make('Приглашение')
                ->description('Отправьте приглашение по email для нового сотрудника')
                ->schema([
                    $marketField,

                    Forms\Components\TextInput::make('email')
                        ->label('Email')
                        ->email()
                        ->required()
                        ->maxLength(255)
                        ->unique(ignoreRecord: true)
                        ->placeholder('new-user@example.com'),

                    Grid::make(2)->schema([
                        $rolesSelect,
                    ]),

                    $invitedBy,
                ])
                ->columnSpan(['default' => 12, 'xl' => 8]),

            Section::make('Срок действия')
                ->description('Приглашение истечёт после указанной даты')
                ->schema([
                    Forms\Components\DateTimePicker::make('expires_at')
                        ->label('Действует до')
                        ->default(fn () => now()->addDays(7))
                        ->helperText('По умолчанию — 7 дней')
                        ->seconds(false),
                ])
                ->columnSpan(['default' => 12, 'xl' => 4]),
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

        $rolesSelect = Forms\Components\Select::make('roles')
            ->label('Роли')
            ->multiple()
            ->preload()
            ->searchable()
            ->relationship(
                name: 'roles',
                titleAttribute: 'name',
                modifyQueryUsing: function ($query) use ($user) {
                    $query->where('name', '!=', 'merchant');

                    if (! $user || ! $user->isSuperAdmin()) {
                        $query->where('name', '!=', 'super-admin');
                    }

                    return $query;
                },
            )
            ->getOptionLabelFromRecordUsing(function ($record) {
                $name = (string) ($record->name ?? '');

                if ($name === '') {
                    return '—';
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
                    return $translated;
                }

                return RoleScenarioCatalog::labelForSlug($slug, $name);
            });

        return $schema->components([
            Section::make('Приглашение')
                ->description('Информация о приглашении')
                ->schema([
                    $marketField,

                    Forms\Components\TextInput::make('email')
                        ->label('Email')
                        ->email()
                        ->required()
                        ->maxLength(255),

                    $rolesSelect,

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
                        ->preload(),
                ])
                ->columnSpan(['default' => 12, 'xl' => 7]),

            Section::make('Статус')
                ->description('Токен и сроки приглашения')
                ->schema([
                    Forms\Components\TextInput::make('token_hash')
                        ->label('Хэш токена')
                        ->disabled()
                        ->dehydrated(false),

                    Forms\Components\DateTimePicker::make('expires_at')
                        ->label('Действует до')
                        ->seconds(false),

                    Forms\Components\DateTimePicker::make('accepted_at')
                        ->label('Принят')
                        ->disabled()
                        ->dehydrated(false),
                ])
                ->columnSpan(['default' => 12, 'xl' => 5]),
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
