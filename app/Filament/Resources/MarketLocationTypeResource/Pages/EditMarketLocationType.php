<?php

namespace App\Filament\Resources\MarketLocationTypeResource\Pages;

use App\Filament\Resources\MarketLocationTypeResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditMarketLocationType extends EditRecord
{
    protected static string $resource = MarketLocationTypeResource::class;

    protected static ?string $title = 'Редактировать тип локации';

    public function getBreadcrumb(): string
    {
        return 'Редактировать тип локации';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->label('Удалить'),
        ];
    }
}
