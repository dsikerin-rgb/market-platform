<?php

namespace App\Filament\Resources\MarketSpaceTypeResource\Pages;

use App\Filament\Resources\MarketSpaceTypeResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditMarketSpaceType extends EditRecord
{
    protected static string $resource = MarketSpaceTypeResource::class;

    protected static ?string $title = 'Редактировать тип торгового места';

    public function getBreadcrumb(): string
    {
        return 'Редактировать тип торгового места';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->label('Удалить'),
        ];
    }
}
