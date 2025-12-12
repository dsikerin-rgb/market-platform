<?php

namespace App\Filament\Resources\Roles\Schemas;

use Filament\Forms;
use Filament\Schemas\Schema;

class RoleForm
{
    public static function configure(Schema $schema): Schema
    {
        // Системные коды ролей (в БД) → человеко-читаемые названия (в UI)
        $roleOptions = [
            'super-admin' => 'Супер-администратор',
            'market-admin' => 'Администратор рынка',
            'market-manager' => 'Менеджер рынка',
            'market-operator' => 'Оператор рынка',
            'merchant' => 'Арендатор',
            '__custom' => 'Другая (ввести вручную)',
        ];

        $nameField = Forms\Components\Select::make('name')
            ->label('Роль')
            ->options($roleOptions)
            ->searchable()
            ->preload()
            ->required()
            ->reactive()
            // При открытии существующей роли: если она не из списка — показываем "Другая"
            ->afterStateHydrated(function ($state, callable $set) use ($roleOptions) {
                if (is_string($state) && $state !== '' && ! array_key_exists($state, $roleOptions)) {
                    $set('name_custom', $state);
                    $set('name', '__custom');
                }
            })
            // Если выбрали "Другая" — сохраняем то, что ввели в name_custom
            ->dehydrateStateUsing(fn ($state, $get) => $state === '__custom'
                ? trim((string) $get('name_custom'))
                : (string) $state)
            ->helperText('Для надёжной работы прав доступа системные коды ролей лучше не менять.');

        $customNameField = Forms\Components\TextInput::make('name_custom')
            ->label('Код роли (вручную)')
            ->placeholder('например: accountant, security, hr-manager')
            ->maxLength(255)
            ->visible(fn ($get) => $get('name') === '__custom')
            ->required(fn ($get) => $get('name') === '__custom')
            ->regex('/^[a-zA-Z0-9\-_]+$/')
            ->helperText('Только латиница, цифры, дефис и подчёркивание.')
            ->dehydrated(false);

        $guardField = Forms\Components\Hidden::make('guard_name')
            ->default('web')
            ->dehydrated(true);

        // Если есть Grid — делаем аккуратную раскладку как в UI (в одну строку/блок)
        if (class_exists(\Filament\Forms\Components\Grid::class)) {
            return $schema->components([
                \Filament\Forms\Components\Grid::make(2)->schema([
                    $nameField,
                    $customNameField,
                ]),
                $guardField,
            ]);
        }

        return $schema->components([
            $nameField,
            $customNameField,
            $guardField,
        ]);
    }
}
