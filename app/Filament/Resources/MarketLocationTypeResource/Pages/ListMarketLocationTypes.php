<?php

namespace App\Filament\Resources\MarketLocationTypeResource\Pages;

use App\Filament\Resources\MarketLocationTypeResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListMarketLocationTypes extends ListRecords
{
    protected static string $resource = MarketLocationTypeResource::class;

    protected static ?string $title = 'Типы локаций';

    public function getBreadcrumb(): string
    {
        return 'Типы локаций';
    }

    protected function getHeaderActions(): array
    {
        $createAction = Actions\CreateAction::make()
            ->label('Создать тип');

        if (method_exists($createAction, 'slideOver')) {
            $createAction->slideOver();
        }

        if (method_exists($createAction, 'modalWidth')) {
            $createAction->modalWidth('3xl');
        }

        return [$createAction];
    }
}
