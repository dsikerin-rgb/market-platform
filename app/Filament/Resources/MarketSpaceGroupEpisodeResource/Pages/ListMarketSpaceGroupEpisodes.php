<?php

declare(strict_types=1);

namespace App\Filament\Resources\MarketSpaceGroupEpisodeResource\Pages;

use App\Filament\Resources\MarketSpaceGroupEpisodeResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListMarketSpaceGroupEpisodes extends ListRecords
{
    protected static string $resource = MarketSpaceGroupEpisodeResource::class;

    protected static ?string $title = 'Эпизоды групп мест';

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Создать эпизод'),
        ];
    }
}
