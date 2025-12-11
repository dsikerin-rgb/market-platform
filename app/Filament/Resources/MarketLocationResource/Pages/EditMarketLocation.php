<?php

namespace App\Filament\Resources\MarketLocationResource\Pages;

use App\Filament\Resources\MarketLocationResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditMarketLocation extends EditRecord
{
    protected static string $resource = MarketLocationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
