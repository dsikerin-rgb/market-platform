<?php

namespace App\Filament\Resources\MarketLocationResource\Pages;

use App\Filament\Resources\MarketLocationResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditMarketLocation extends EditRecord
{
    protected static string $resource = MarketLocationResource::class;

    protected static ?string $title = 'Редактирование локации';

    public function getBreadcrumb(): ?string
    {
        return 'Редактирование локации';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->label('Удалить локацию'),
        ];
    }
}
