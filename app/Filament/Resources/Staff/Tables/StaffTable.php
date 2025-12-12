<?php

namespace App\Filament\Resources\Staff\Tables;

use Filament\Facades\Filament;
use Filament\Tables;
use Filament\Tables\Table;

class StaffTable
{
    public static function configure(Table $table): Table
    {
        $user = Filament::auth()->user();

        $recordActions = [];

        // Edit action (разные версии Filament)
        if (class_exists(\Filament\Actions\EditAction::class)) {
            $recordActions[] = \Filament\Actions\EditAction::make()->label('Редактировать');
        } elseif (class_exists(\Filament\Tables\Actions\EditAction::class)) {
            $recordActions[] = \Filament\Tables\Actions\EditAction::make()->label('Редактировать');
        }

        // Delete action — только super-admin
        if ((bool) $user && $user->isSuperAdmin()) {
            if (class_exists(\Filament\Actions\DeleteAction::class)) {
                $recordActions[] = \Filament\Actions\DeleteAction::make()->label('Удалить');
            } elseif (class_exists(\Filament\Tables\Actions\DeleteAction::class)) {
                $recordActions[] = \Filament\Tables\Actions\DeleteAction::make()->label('Удалить');
            }
        }

        // Toolbar bulk actions — только super-admin (и только если классы существуют)
        $toolbarActions = [];
        $bulkDelete = null;

        if ((bool) $user && $user->isSuperAdmin()) {
            if (class_exists(\Filament\Actions\DeleteBulkAction::class)) {
                $bulkDelete = \Filament\Actions\DeleteBulkAction::make()->label('Удалить выбранные');
            } elseif (class_exists(\Filament\Tables\Actions\DeleteBulkAction::class)) {
                $bulkDelete = \Filament\Tables\Actions\DeleteBulkAction::make()->label('Удалить выбранные');
            }

            if ($bulkDelete) {
                if (class_exists(\Filament\Actions\BulkActionGroup::class)) {
                    $toolbarActions[] = \Filament\Actions\BulkActionGroup::make([$bulkDelete]);
                } elseif (class_exists(\Filament\Tables\Actions\BulkActionGroup::class)) {
                    $toolbarActions[] = \Filament\Tables\Actions\BulkActionGroup::make([$bulkDelete]);
                } else {
                    // если группировки нет — просто не показываем bulk
                }
            }
        }

        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Сотрудник')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('market.name')
                    ->label('Рынок')
                    ->sortable()
                    ->searchable()
                    ->visible(fn () => (bool) $user && $user->isSuperAdmin()),

                Tables\Columns\TextColumn::make('roles.name')
                    ->label('Роли')
                    ->badge()
                    ->separator(', ')
                    ->toggleable(),
            ])
            ->filters([
                //
            ])
            ->recordActions($recordActions)
            ->toolbarActions($toolbarActions);
    }
}
