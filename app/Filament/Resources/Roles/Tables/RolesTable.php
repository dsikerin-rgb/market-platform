<?php

namespace App\Filament\Resources\Roles\Tables;

use Filament\Tables;
use Filament\Tables\Table;

class RolesTable
{
    public static function configure(Table $table): Table
    {
        $systemRoles = ['super-admin', 'market-admin', 'merchant'];

        $labels = [
            'super-admin' => 'Супер-администратор',
            'market-admin' => 'Администратор рынка',
            'market-manager' => 'Менеджер рынка',
            'market-operator' => 'Оператор рынка',
            'merchant' => 'Арендатор',
        ];

        $recordActions = [];

        if (class_exists(\Filament\Actions\EditAction::class)) {
            $recordActions[] = \Filament\Actions\EditAction::make()->label('Редактировать');
        } elseif (class_exists(\Filament\Tables\Actions\EditAction::class)) {
            $recordActions[] = \Filament\Tables\Actions\EditAction::make()->label('Редактировать');
        }

        // Delete action — только если роль НЕ системная
        if (class_exists(\Filament\Actions\DeleteAction::class)) {
            $recordActions[] = \Filament\Actions\DeleteAction::make()
                ->label('Удалить')
                ->visible(fn ($record) => ! in_array($record->name, $systemRoles, true));
        } elseif (class_exists(\Filament\Tables\Actions\DeleteAction::class)) {
            $recordActions[] = \Filament\Tables\Actions\DeleteAction::make()
                ->label('Удалить')
                ->visible(fn ($record) => ! in_array($record->name, $systemRoles, true));
        }

        return $table
            ->columns([
                Tables\Columns\TextColumn::make('label_ru')
                    ->label('Название')
                    ->formatStateUsing(fn ($state, $record) => $state ?: ($labels[$record->name] ?? $record->name))
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('name')
                    ->label('Код роли')
                    ->formatStateUsing(fn (?string $state) => $labels[$state] ?? $state)
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('guard_name')
                    ->label('Guard')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('permissions.name')
                    ->label('Права')
                    ->badge()
                    ->separator(', '),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Создана')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions($recordActions)
            // массовое удаление отключаем (слишком рискованно для ролей)
            ->toolbarActions([]);
    }
}
