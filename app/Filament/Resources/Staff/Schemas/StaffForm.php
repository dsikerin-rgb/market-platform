<?php

namespace App\Filament\Resources\Staff\Schemas;

use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Hash;

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

        $nameEmail = [];
        if (class_exists(\Filament\Forms\Components\Grid::class)) {
            $nameEmail[] = \Filament\Forms\Components\Grid::make(2)->schema([
                Forms\Components\TextInput::make('name')
                    ->label('Имя / ФИО')
                    ->required()
                    ->maxLength(255),

                Forms\Components\TextInput::make('email')
                    ->label('Email')
                    ->email()
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
            ]);
        } else {
            $nameEmail[] = Forms\Components\TextInput::make('name')
                ->label('Имя / ФИО')
                ->required()
                ->maxLength(255);

            $nameEmail[] = Forms\Components\TextInput::make('email')
                ->label('Email')
                ->email()
                ->required()
                ->maxLength(255)
                ->unique(ignoreRecord: true);
        }

        $passwordPair = [];
        if (class_exists(\Filament\Forms\Components\Grid::class)) {
            $passwordPair[] = \Filament\Forms\Components\Grid::make(2)->schema([
                Forms\Components\TextInput::make('password')
                    ->label('Пароль')
                    ->password()
                    ->revealable()
                    ->minLength(8)
                    ->required(fn (string $operation) => $operation === 'create')
                    ->dehydrated(fn ($state) => filled($state))
                    ->dehydrateStateUsing(fn ($state) => filled($state) ? Hash::make($state) : null),

                Forms\Components\TextInput::make('password_confirmation')
                    ->label('Подтверждение пароля')
                    ->password()
                    ->revealable()
                    ->required(fn (string $operation, $get) => $operation === 'create' || filled($get('password')))
                    ->same('password')
                    ->dehydrated(false),
            ]);
        } else {
            $passwordPair[] = Forms\Components\TextInput::make('password')
                ->label('Пароль')
                ->password()
                ->revealable()
                ->minLength(8)
                ->required(fn (string $operation) => $operation === 'create')
                ->dehydrated(fn ($state) => filled($state))
                ->dehydrateStateUsing(fn ($state) => filled($state) ? Hash::make($state) : null);

            $passwordPair[] = Forms\Components\TextInput::make('password_confirmation')
                ->label('Подтверждение пароля')
                ->password()
                ->revealable()
                ->required(fn (string $operation, $get) => $operation === 'create' || filled($get('password')))
                ->same('password')
                ->dehydrated(false);
        }

        $roleLabels = [
            'super-admin' => 'Супер-администратор',
            'market-admin' => 'Администратор рынка',
            'market-manager' => 'Менеджер рынка',
            'market-operator' => 'Оператор рынка',
            'merchant' => 'Арендатор',
        ];

        return $schema->components([
            $marketSelect,
            $marketHidden,

            ...$nameEmail,
            ...$passwordPair,

            Forms\Components\Select::make('roles')
                ->label('Роли')
                ->multiple()
                ->preload()
                ->searchable()
                ->relationship(
                    name: 'roles',
                    titleAttribute: 'name',
                    modifyQueryUsing: function ($query) use ($user) {
                        // сотрудникам не показываем "арендатор" (это другой тип пользователя)
                        $query->where('name', '!=', 'merchant');

                        // market-admin не должен видеть/назначать super-admin
                        if (! $user || ! $user->isSuperAdmin()) {
                            $query->where('name', '!=', 'super-admin');
                        }

                        return $query;
                    },
                )
                ->getOptionLabelFromRecordUsing(fn ($record) => $roleLabels[$record->name] ?? $record->name)
                ->helperText('Роли определяют доступ сотрудника в системе.'),
        ]);
    }
}
