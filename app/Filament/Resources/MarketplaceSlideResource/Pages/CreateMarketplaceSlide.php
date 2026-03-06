<?php

declare(strict_types=1);

namespace App\Filament\Resources\MarketplaceSlideResource\Pages;

use App\Filament\Resources\MarketplaceSlideResource;
use Filament\Resources\Pages\CreateRecord;

class CreateMarketplaceSlide extends CreateRecord
{
    protected static string $resource = MarketplaceSlideResource::class;

    protected static ?string $title = 'Новый слайд';

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
