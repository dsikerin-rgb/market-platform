<?php

namespace App\Filament\Resources\MarketResource\Pages;

use App\Filament\Resources\MarketResource;
use App\Filament\Resources\Pages\BaseCreateRecord;

class CreateMarket extends BaseCreateRecord
{
    protected static string $resource = MarketResource::class;

    protected static ?string $title = 'Создать рынок';
}
