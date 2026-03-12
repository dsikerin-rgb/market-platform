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
        $createAction = Actions\CreateAction::make()->label('Добавить слайд');

        if (method_exists($createAction, 'slideOver')) {
            $createAction->slideOver();
        }

        if (method_exists($createAction, 'modalWidth')) {
            $createAction->modalWidth('5xl');
        }

        return [$createAction];
    }
}
