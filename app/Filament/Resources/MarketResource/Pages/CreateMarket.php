<?php

namespace App\Filament\Resources\MarketResource\Pages;

use App\Filament\Resources\MarketResource;
use Filament\Resources\Pages\CreateRecord;

class CreateMarket extends CreateRecord
{
    protected static string $resource = MarketResource::class;

    protected static ?string $title = 'Создать рынок';

    public function getBreadcrumb(): ?string
    {
        return 'Создать рынок';
    }
}
