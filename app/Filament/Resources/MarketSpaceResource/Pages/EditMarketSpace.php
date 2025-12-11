<?php

namespace App\Filament\Resources\MarketSpaceResource\Pages;

use App\Filament\Resources\MarketSpaceResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditMarketSpace extends EditRecord
{
    protected static string $resource = MarketSpaceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
