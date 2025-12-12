<?php

namespace App\Filament\Resources\IntegrationExchangeResource\Pages;

use App\Filament\Resources\IntegrationExchangeResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListIntegrationExchanges extends ListRecords
{
    protected static string $resource = IntegrationExchangeResource::class;

    protected static ?string $title = 'Обмены интеграций';

    public function getBreadcrumb(): string
    {
        return 'Обмены интеграций';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Создать обмен'),
        ];
    }
}
