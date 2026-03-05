<?php

namespace App\Filament\Resources\MarketLocationTypeResource\Pages;

use App\Filament\Resources\MarketLocationTypeResource;
use App\Filament\Resources\Pages\BaseCreateRecord;

class CreateMarketLocationType extends BaseCreateRecord
{
    protected static string $resource = MarketLocationTypeResource::class;

    protected static ?string $title = 'Создать тип локации';
}
