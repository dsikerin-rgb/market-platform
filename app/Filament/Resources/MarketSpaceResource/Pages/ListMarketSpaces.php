<?php

namespace App\Filament\Resources\MarketSpaceResource\Pages;

use App\Filament\Resources\MarketSpaceResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListMarketSpaces extends ListRecords
{
    protected static string $resource = MarketSpaceResource::class;

    protected static ?string $title = 'Торговые места';

    public function getBreadcrumb(): string
    {
        return 'Торговые места';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Создать торговое место'),
        ];
    }
}
