<?php

namespace App\Filament\Resources\MarketLocationResource\Pages;

use App\Filament\Resources\MarketLocationResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListMarketLocations extends ListRecords
{
    protected static string $resource = MarketLocationResource::class;

    protected static ?string $title = 'Локации рынка';

    public function getBreadcrumb(): ?string
    {
        return 'Локации рынка';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Создать локацию'),
        ];
    }
}
