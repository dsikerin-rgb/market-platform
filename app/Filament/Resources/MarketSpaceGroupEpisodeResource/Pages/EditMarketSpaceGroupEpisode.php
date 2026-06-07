<?php

declare(strict_types=1);

namespace App\Filament\Resources\MarketSpaceGroupEpisodeResource\Pages;

use App\Filament\Resources\MarketSpaceGroupEpisodeResource;
use App\Filament\Resources\Pages\BaseEditRecord;
use Filament\Actions;
use Filament\Facades\Filament;

class EditMarketSpaceGroupEpisode extends BaseEditRecord
{
    protected static string $resource = MarketSpaceGroupEpisodeResource::class;

    protected static ?string $title = 'Эпизод группы мест';

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $user = Filament::auth()->user();

        if ($user && ! $user->isSuperAdmin()) {
            $data['market_id'] = (int) $this->record->market_id;
        }

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->label('Удалить эпизод'),
        ];
    }
}
