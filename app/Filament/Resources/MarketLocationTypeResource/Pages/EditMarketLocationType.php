<?php

namespace App\Filament\Resources\MarketLocationTypeResource\Pages;

use App\Filament\Resources\MarketLocationTypeResource;
use Filament\Actions;
use App\Filament\Resources\Pages\BaseEditRecord;

class EditMarketLocationType extends BaseEditRecord
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
