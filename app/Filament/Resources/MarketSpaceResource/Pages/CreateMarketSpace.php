<?php

namespace App\Filament\Resources\MarketSpaceResource\Pages;

use App\Filament\Resources\MarketSpaceResource;
use Filament\Resources\Pages\CreateRecord;

class CreateMarketSpace extends CreateRecord
{
    protected static string $resource = MarketSpaceResource::class;

    protected static ?string $title = 'Создать торговое место';

    public function getBreadcrumb(): ?string
    {
        return 'Создать торговое место';
    }
}
