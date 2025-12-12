<?php

namespace App\Filament\Resources\IntegrationExchangeResource\Pages;

use App\Filament\Resources\IntegrationExchangeResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditIntegrationExchange extends EditRecord
{
    protected static string $resource = IntegrationExchangeResource::class;

    protected static ?string $title = 'Редактировать обмен интеграции';

    public function getBreadcrumb(): string
    {
        return 'Редактировать обмен интеграции';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->label('Удалить'),
        ];
    }
}
