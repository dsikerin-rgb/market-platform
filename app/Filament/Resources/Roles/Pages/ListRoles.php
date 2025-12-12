<?php

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Resources\Roles\RoleResource;
use Filament\Resources\Pages\ListRecords;

class ListRoles extends ListRecords
{
    protected static string $resource = RoleResource::class;

    protected function getHeaderActions(): array
    {
        $actions = [];

        if (class_exists(\Filament\Actions\CreateAction::class)) {
            $actions[] = \Filament\Actions\CreateAction::make()
                ->label('Создать роль')
                ->modalHeading('Создать роль')
                ->modalDescription('Системные роли лучше не менять. Для новых сотрудников создавайте отдельные роли.');
        } elseif (class_exists(\Filament\Pages\Actions\CreateAction::class)) {
            $actions[] = \Filament\Pages\Actions\CreateAction::make()
                ->label('Создать роль')
                ->modalHeading('Создать роль')
                ->modalDescription('Системные роли лучше не менять. Для новых сотрудников создавайте отдельные роли.');
        }

        return $actions;
    }
}
