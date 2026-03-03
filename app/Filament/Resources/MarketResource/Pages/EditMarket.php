<?php

namespace App\Filament\Resources\MarketResource\Pages;

use App\Filament\Resources\MarketResource;
use Filament\Actions;
use App\Filament\Resources\Pages\BaseEditRecord;

class EditMarket extends BaseEditRecord
{
    protected static string $resource = MarketResource::class;

    protected static ?string $title = 'Редактирование рынка';

    public function getBreadcrumb(): string
    {
        return 'Редактирование рынка';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->label('Удалить рынок'),
        ];
    }
}
