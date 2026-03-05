<?php

namespace App\Filament\Resources\MarketResource\Pages;

use App\Filament\Resources\MarketResource;
use Filament\Actions;
use App\Filament\Resources\Pages\BaseEditRecord;

class EditMarket extends BaseEditRecord
{
    protected static string $resource = MarketResource::class;

    protected static ?string $title = 'Рынок';

    public function getBreadcrumb(): string
    {
        return 'Рынок';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->label('Удалить рынок'),
        ];
    }
}
