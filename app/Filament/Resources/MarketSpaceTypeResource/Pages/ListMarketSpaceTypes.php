<?php

namespace App\Filament\Resources\MarketSpaceTypeResource\Pages;

use App\Filament\Resources\MarketSpaceTypeResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListMarketSpaceTypes extends ListRecords
{
    protected static string $resource = MarketSpaceTypeResource::class;

    protected static ?string $title = 'Типы торговых мест';

    public function getBreadcrumb(): string
    {
        return 'Типы торговых мест';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Создать тип'),
        ];
    }
}
