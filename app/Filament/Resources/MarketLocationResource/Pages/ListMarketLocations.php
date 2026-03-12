<?php

namespace App\Filament\Resources\MarketLocationResource\Pages;

use App\Filament\Resources\MarketLocationResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListMarketLocations extends ListRecords
{
    protected static string $resource = MarketLocationResource::class;

    protected static ?string $title = 'Локации рынка';

    public function getBreadcrumb(): string
    {
        return 'Локации рынка';
    }

    protected function getHeaderActions(): array
    {
        $createAction = Actions\CreateAction::make()
            ->label('Создать локацию');

        if (method_exists($createAction, 'slideOver')) {
            $createAction->slideOver();
        }

        if (method_exists($createAction, 'modalWidth')) {
            $createAction->modalWidth('4xl');
        }

        return [$createAction];
    }
}
