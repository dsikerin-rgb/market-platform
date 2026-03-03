<?php

namespace App\Filament\Resources\MarketSpaceResource\Pages;

use App\Filament\Resources\MarketSpaceResource;
use App\Filament\Resources\Pages\BaseCreateRecord;

class CreateMarketSpace extends BaseCreateRecord
{
    protected static string $resource = MarketSpaceResource::class;

    protected static ?string $title = 'Создать торговое место';

    public function getBreadcrumb(): string
    {
        return 'Создать торговое место';
    }
}
