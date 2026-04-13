<?php

namespace App\Filament\Resources\Roles\Tables;

use App\Support\PermissionDisplayCatalog;
use App\Support\RoleScenarioCatalog;
use Filament\Tables;
use Filament\Tables\Table;

class RolesTable
{
    public static function configure(Table $table): Table
    {
        $systemRoles = ['super-admin', 'market-admin', 'merchant'];

        $recordActions = [];

        if (class_exists(\Filament\Actions\EditAction::class)) {
            $recordActions[] = \Filament\Actions\EditAction::make()
                ->label('Редактировать')
                ->slideOver()
                ->modalHeading(fn ($record) => 'Права роли: ' . ($record->label_ru ?: $record->name))
                ->modalWidth('xl');
        } elseif (class_exists(\Filament\Tables\Actions\EditAction::class)) {
            $recordActions[] = \Filament\Tables\Actions\EditAction::make()
                ->label('Редактировать')
                ->slideOver()
                ->modalHeading(fn ($record) => 'Права роли: ' . ($record->label_ru ?: $record->name))
                ->modalWidth('xl');
        }

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
                    ->label('Роль')
                    ->formatStateUsing(fn ($state, $record) => $state ?: RoleScenarioCatalog::labelForSlug((string) $record->name, (string) $record->name))
                    ->searchable()
                    ->sortable()
                    ->weight('600')
                    ->description(fn ($record) => $record->name)
                    ->size('sm'),

                Tables\Columns\TextColumn::make('role_profile')
                    ->label('Профиль')
                    ->getStateUsing(fn ($record) => RoleScenarioCatalog::descriptionForSlug((string) $record->name) ?? 'Кастомный профиль')
                    ->wrap()
                    ->limit(50)
                    ->size('sm')
                    ->color('gray-600'),

                Tables\Columns\TextColumn::make('notification_topics')
                    ->label('Уведомления')
                    ->getStateUsing(fn ($record) => RoleScenarioCatalog::topicSummaryForSlug((string) $record->name))
                    ->wrap()
                    ->size('sm')
                    ->color('gray-500')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('permissions_count')
                    ->label('Права')
                    ->counts('permissions')
                    ->badge()
                    ->color('primary')
                    ->size('sm')
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Создана')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->size('sm'),
            ])
            ->filters([
                //
            ])
            ->recordActions($recordActions)
            ->toolbarActions([]);
    }
}
