<?php

namespace App\Filament\Resources\MarketSpaceTypeResource\Pages;

use App\Filament\Resources\MarketSpaceTypeResource;
use Filament\Resources\Pages\CreateRecord;

class CreateMarketSpaceType extends CreateRecord
{
    protected static string $resource = MarketSpaceTypeResource::class;

    protected static ?string $title = 'Создать тип торгового места';

    public function getBreadcrumb(): string
    {
        return 'Создать тип торгового места';
    }
}
