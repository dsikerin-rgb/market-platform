<?php
# app/Filament/Resources/Staff/Schemas/StaffForm.php

namespace App\Filament\Resources\Staff\Schemas;

use App\Support\RoleScenarioCatalog;
use App\Support\UserNotificationPreferences;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class StaffForm
{
    public static function configure(Schema $schema): Schema
    {
        $user = Filament::auth()->user();

        $marketSelect = Forms\Components\Select::make('market_id')
            ->label('Рынок')
            ->relationship('market', 'name')
            ->searchable()
            ->preload()
            ->required()
            ->default(fn () => $user?->isSuperAdmin()
                ? session('filament.admin.selected_market_id')
                : $user?->market_id)
            ->visible(fn () => (bool) $user && $user->isSuperAdmin())
            ->dehydrated(true);

        $marketHidden = Forms\Components\Hidden::make('market_id')
            ->default(fn () => $user?->market_id)
            ->visible(fn () => ! ((bool) $user && $user->isSuperAdmin()))
            ->dehydrated(true);

        $passwordPair = [
            Forms\Components\TextInput::make('password')
                ->label('Пароль')
                ->password()
                ->revealable()
                ->minLength(8)
                ->required(fn (string $operation) => $operation === 'create')
                ->dehydrated(fn ($state) => filled($state))
                ->dehydrateStateUsing(fn ($state) => filled($state) ? Hash::make($state) : null)
                ->visible(fn (string $operation): bool => $operation === 'create'),

            Forms\Components\TextInput::make('password_confirmation')
                ->label('Подтверждение пароля')
                ->password()
                ->revealable()
                ->required(fn (string $operation, $get) => $operation === 'create' || filled($get('password')))
                ->same('password')
                ->dehydrated(false)
                ->visible(fn (string $operation): bool => $operation === 'create'),
        ];

        return $schema->components([
            Section::make('Основные данные')
                ->schema([
                    Grid::make(2)->schema([
                        $marketSelect,
                        $marketHidden,
                    ]),

                    Grid::make(12)->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Имя / ФИО')
                            ->required()
                            ->maxLength(255)
                            ->columnSpan(9),

                        Forms\Components\TextInput::make('email')
                            ->label('Email')
                            ->email()
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true)
                            ->columnSpan(3),
                    ]),
                ])
                ->columnSpan(['default' => 6, 'xl' => 4]),

            Section::make('Роли и доступ')
                ->schema([
                    Grid::make(2)->schema([
                        Forms\Components\Select::make('roles')
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
                            }),
                    ]),
                ])
                ->columnSpan(['default' => 6, 'xl' => 4]),

            Section::make('Telegram')
                ->description('Одноразовый chat_id заполняется только при создании сотрудника.')
                ->schema([
                    Forms\Components\TextInput::make('telegram_chat_id')
                        ->label('Telegram (chat_id)')
                        ->placeholder('например: 123456789')
                        ->helperText('Нужен для доставки уведомлений в Telegram.')
                        ->maxLength(32)
                        ->regex('/^-?\\d+$/')
                        ->validationMessages([
                            'regex' => 'Используйте только цифры и, при необходимости, знак "-" в начале.',
                        ])
                        ->dehydrateStateUsing(fn ($state) => filled($state) ? trim((string) $state) : null)
                        ->visible(fn (string $operation): bool => $operation === 'create'),
                ])
                ->columnSpan(['default' => 12, 'xl' => 6])
                ->visible(fn (string $operation): bool => $operation === 'create'),

            Section::make('Безопасность')
                ->description('Пароль задается только при создании сотрудника.')
                ->schema($passwordPair)
                ->columnSpan(['default' => 12, 'xl' => 6])
                ->visible(fn (string $operation): bool => $operation === 'create'),

            Section::make('Уведомления')
                ->description('Для super-admin и market-admin личные настройки доступны всегда.')
                ->schema([
                    Forms\Components\Toggle::make('notification_preferences.self_manage')
                        ->label('Разрешить личные настройки')
                        ->helperText('Пользователь сможет сам менять свои каналы и события в кабинете.')
                        ->default(false),

                    Forms\Components\CheckboxList::make('notification_preferences.channels')
                        ->label('Каналы доставки')
                        ->options(UserNotificationPreferences::channelLabels())
                        ->columns(3)
                        ->helperText('Если пусто, применяются стандартные каналы.'),

                    Forms\Components\CheckboxList::make('notification_preferences.topics')
                        ->label('События')
                        ->options(UserNotificationPreferences::topicLabels())
                        ->default(UserNotificationPreferences::defaultTopicsForRoleNames([]))
                        ->columns(2)
                        ->helperText('Если пусто, пользователь не получает уведомления.'),
                ])
                ->collapsible()
                ->visible(fn (string $operation): bool => $operation === 'create'
                    && (bool) $user
                    && ($user->isSuperAdmin() || $user->isMarketAdmin())),
        ]);
    }
}
