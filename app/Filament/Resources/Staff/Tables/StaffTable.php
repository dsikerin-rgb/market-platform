<?php

namespace App\Filament\Resources\Staff\Tables;

use App\Filament\Resources\Staff\StaffResource;
use App\Support\RoleScenarioCatalog;
use Filament\Facades\Filament;
use Filament\Tables;
use Filament\Tables\Table;

class StaffTable
{
    public static function configure(Table $table): Table
    {
        $user = Filament::auth()->user();

        $recordActions = [];

        if (class_exists(\Filament\Actions\EditAction::class)) {
            $recordActions[] = \Filament\Actions\EditAction::make()->label('Редактировать');
        } elseif (class_exists(\Filament\Tables\Actions\EditAction::class)) {
            $recordActions[] = \Filament\Tables\Actions\EditAction::make()->label('Редактировать');
        }

        if (class_exists(\Filament\Actions\DeleteAction::class)) {
            $recordActions[] = \Filament\Actions\DeleteAction::make()
                ->label('Удалить')
                ->visible(fn ($record): bool => StaffResource::canDelete($record));
        } elseif (class_exists(\Filament\Tables\Actions\DeleteAction::class)) {
            $recordActions[] = \Filament\Tables\Actions\DeleteAction::make()
                ->label('Удалить')
                ->visible(fn ($record): bool => StaffResource::canDelete($record));
        }

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
                    ->formatStateUsing(fn (?string $state): string => RoleScenarioCatalog::labelForSlug((string) $state, (string) $state))
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
