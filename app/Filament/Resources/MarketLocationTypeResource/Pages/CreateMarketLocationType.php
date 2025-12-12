<?php

namespace App\Filament\Resources\MarketLocationTypeResource\Pages;

use App\Filament\Resources\MarketLocationTypeResource;
use Filament\Resources\Pages\CreateRecord;

class CreateMarketLocationType extends CreateRecord
{
    protected static string $resource = MarketLocationTypeResource::class;

    protected static ?string $title = 'Создать тип локации';

    public function getBreadcrumb(): string
    {
        return 'Создать тип локации';
    }
}
