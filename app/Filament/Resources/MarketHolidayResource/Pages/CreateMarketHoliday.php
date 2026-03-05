<?php

declare(strict_types=1);

namespace App\Filament\Resources\MarketHolidayResource\Pages;

use App\Filament\Resources\MarketHolidayResource;
use App\Filament\Resources\Pages\BaseCreateRecord;
use Filament\Support\Enums\Width;

class CreateMarketHoliday extends BaseCreateRecord
{
    protected static string $resource = MarketHolidayResource::class;

    public function getMaxContentWidth(): Width|string|null
    {
        return Width::Full;
    }
}
