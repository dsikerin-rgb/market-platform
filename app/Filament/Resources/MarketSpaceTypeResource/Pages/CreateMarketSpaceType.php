<?php

namespace App\Filament\Resources\MarketSpaceTypeResource\Pages;

use App\Filament\Resources\MarketSpaceTypeResource;
use App\Filament\Resources\Pages\BaseCreateRecord;

class CreateMarketSpaceType extends BaseCreateRecord
{
    protected static string $resource = MarketSpaceTypeResource::class;

    protected static ?string $title = 'Создать тип торгового места';

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $marketId = (int) ($data['market_id'] ?? 0);
        $name = trim((string) ($data['name_ru'] ?? ''));

        if ($marketId > 0 && $name !== '' && blank($data['code'] ?? null)) {
            $data['code'] = MarketSpaceTypeResource::makeUniqueCode($marketId, $name);
        }

        return $data;
    }
}
