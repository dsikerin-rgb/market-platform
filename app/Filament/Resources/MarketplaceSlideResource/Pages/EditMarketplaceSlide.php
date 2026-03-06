<?php

declare(strict_types=1);

namespace App\Filament\Resources\MarketplaceSlideResource\Pages;

use App\Filament\Resources\MarketplaceSlideResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditMarketplaceSlide extends EditRecord
{
    protected static string $resource = MarketplaceSlideResource::class;

    protected static ?string $title = 'Слайд';

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
