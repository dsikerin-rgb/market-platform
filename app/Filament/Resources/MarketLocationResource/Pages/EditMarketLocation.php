<?php

namespace App\Filament\Resources\MarketLocationResource\Pages;

use App\Filament\Resources\MarketLocationResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;

class EditMarketLocation extends EditRecord
{
    protected static string $resource = MarketLocationResource::class;

    protected static ?string $title = 'Редактирование локации';

    public function getBreadcrumb(): string
    {
        return 'Редактирование локации';
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return $data;
        }

        // Рыночные роли никогда не меняют рынок локации
        if (! $user->isSuperAdmin()) {
            $data['market_id'] = $this->record->market_id;

            return $data;
        }

        // Super-admin: если выбран рынок через переключатель — фиксируем market_id
        $selectedMarketId = session('filament.admin.selected_market_id');
        if (filled($selectedMarketId)) {
            $data['market_id'] = (int) $selectedMarketId;
        } else {
            // иначе не даём случайно "обнулить" рынок
            if (empty($data['market_id'])) {
                $data['market_id'] = $this->record->market_id;
            }
        }

        return $data;
    }

    protected function getHeaderActions(): array
    {
        $actions = [];

        $canDelete = MarketLocationResource::canDelete($this->record);

        if ($canDelete) {
            if (class_exists(\Filament\Actions\DeleteAction::class)) {
                $actions[] = \Filament\Actions\DeleteAction::make()->label('Удалить локацию');
            } elseif (class_exists(\Filament\Pages\Actions\DeleteAction::class)) {
                $actions[] = \Filament\Pages\Actions\DeleteAction::make()->label('Удалить локацию');
            }
        }

        return $actions;
    }
}
