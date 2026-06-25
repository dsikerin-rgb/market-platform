<?php

declare(strict_types=1);

namespace App\Filament\Resources\MarketDocumentActivityEventResource\Pages;

use App\Filament\Resources\MarketDocumentActivityEventResource;
use Filament\Resources\Pages\ListRecords;

class ListMarketDocumentActivityEvents extends ListRecords
{
    protected static string $resource = MarketDocumentActivityEventResource::class;

    protected static ?string $title = 'Журнал диска';

    protected function getHeaderActions(): array
    {
        return [];
    }
}
