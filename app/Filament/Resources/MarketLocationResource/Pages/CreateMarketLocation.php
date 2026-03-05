<?php

namespace App\Filament\Resources\MarketLocationResource\Pages;

use App\Filament\Resources\MarketLocationResource;
use Filament\Facades\Filament;
use App\Filament\Resources\Pages\BaseCreateRecord;

class CreateMarketLocation extends BaseCreateRecord
{
    protected static string $resource = MarketLocationResource::class;

    protected static ?string $title = 'Создать локацию';

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return $data;
        }

        // Рыночные роли всегда создают в своём рынке
        if (! $user->isSuperAdmin()) {
            $data['market_id'] = $user->market_id;

            return $data;
        }

        // Super-admin: если выбран рынок через переключатель — принудительно проставим
        if (empty($data['market_id'])) {
            $selectedMarketId = session('filament.admin.selected_market_id');
            if (filled($selectedMarketId)) {
                $data['market_id'] = (int) $selectedMarketId;
            }
        }

        return $data;
    }
}
