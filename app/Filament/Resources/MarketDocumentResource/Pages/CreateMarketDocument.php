<?php

declare(strict_types=1);

namespace App\Filament\Resources\MarketDocumentResource\Pages;

use App\Filament\Resources\MarketDocumentResource;
use App\Filament\Resources\Pages\BaseCreateRecord;

class CreateMarketDocument extends BaseCreateRecord
{
    protected static string $resource = MarketDocumentResource::class;

    protected static ?string $title = 'Документ';
}
