<?php

namespace App\Filament\Resources\MarketLocationResource\Pages;

use App\Filament\Resources\MarketLocationResource;
use Filament\Resources\Pages\CreateRecord;

class CreateMarketLocation extends CreateRecord
{
    protected static string $resource = MarketLocationResource::class;

    protected static ?string $title = 'Создать локацию';

    public function getBreadcrumb(): ?string
    {
        return 'Создать локацию';
    }
}
