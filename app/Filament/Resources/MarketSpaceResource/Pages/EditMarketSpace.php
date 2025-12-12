<?php

namespace App\Filament\Resources\MarketSpaceResource\Pages;

use App\Filament\Resources\MarketSpaceResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;

class EditMarketSpace extends EditRecord
{
    protected static string $resource = MarketSpaceResource::class;

    protected static ?string $title = 'Редактирование торгового места';

    public function getBreadcrumb(): string
    {
        return 'Редактирование торгового места';
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return $data;
        }

        // Рыночные роли никогда не меняют market_id
        if (! $user->isSuperAdmin()) {
            $data['market_id'] = $this->record->market_id;
            return $data;
        }

        // Super-admin: если выбран рынок через переключатель — фиксируем market_id
        $selectedMarketId = session('filament.admin.selected_market_id');
        if (filled($selectedMarketId)) {
            $data['market_id'] = (int) $selectedMarketId;
        } else {
            // иначе не даём случайно "обнулить"
            if (empty($data['market_id'])) {
                $data['market_id'] = $this->record->market_id;
            }
        }

        return $data;
    }

    protected function getHeaderActions(): array
    {
        $actions = [];

        $canDelete = MarketSpaceResource::canDelete($this->record);

        if ($canDelete) {
            if (class_exists(\Filament\Actions\DeleteAction::class)) {
                $actions[] = \Filament\Actions\DeleteAction::make()->label('Удалить торговое место');
            } elseif (class_exists(\Filament\Pages\Actions\DeleteAction::class)) {
                $actions[] = \Filament\Pages\Actions\DeleteAction::make()->label('Удалить торговое место');
            }
        }

        return $actions;
    }
}
