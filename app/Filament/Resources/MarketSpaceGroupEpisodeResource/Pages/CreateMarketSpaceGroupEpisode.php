<?php

declare(strict_types=1);

namespace App\Filament\Resources\MarketSpaceGroupEpisodeResource\Pages;

use App\Filament\Resources\MarketSpaceGroupEpisodeResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateMarketSpaceGroupEpisode extends CreateRecord
{
    protected static string $resource = MarketSpaceGroupEpisodeResource::class;

    protected static ?string $title = 'Новый эпизод группы мест';

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $user = Filament::auth()->user();

        if ($user && ! $user->isSuperAdmin()) {
            $data['market_id'] = (int) $user->market_id;
        }

        $data['created_by_user_id'] = $user?->id;

        return $data;
    }
}
