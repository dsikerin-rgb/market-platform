<?php

namespace App\Filament\Resources\MarketSpaceTypeResource\Pages;

use App\Filament\Resources\MarketSpaceTypeResource;
use App\Filament\Resources\Pages\BaseCreateRecord;

class CreateMarketSpaceType extends BaseCreateRecord
{
    protected static string $resource = MarketSpaceTypeResource::class;

    protected static ?string $title = 'Создать тип торгового места';
}
