<?php

declare(strict_types=1);

namespace App\Filament\Resources\MarketHolidayResource\Pages;

use App\Filament\Resources\MarketHolidayResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListMarketHolidays extends ListRecords
{
    protected static string $resource = MarketHolidayResource::class;

    protected static ?string $title = 'Праздники рынка';

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Добавить')
                ->icon('heroicon-o-plus'),
        ];
    }
}
