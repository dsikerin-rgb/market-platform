<?php

declare(strict_types=1);

namespace App\Filament\Resources\MarketplaceSlideResource\Pages;

use App\Filament\Resources\MarketplaceSlideResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListMarketplaceSlides extends ListRecords
{
    protected static string $resource = MarketplaceSlideResource::class;

    protected static ?string $title = 'Слайды маркетплейса';

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Добавить слайд'),
        ];
    }
}
