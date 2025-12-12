<?php

namespace App\Filament\Resources\IntegrationExchangeResource\Pages;

use App\Filament\Resources\IntegrationExchangeResource;
use Filament\Resources\Pages\CreateRecord;

class CreateIntegrationExchange extends CreateRecord
{
    protected static string $resource = IntegrationExchangeResource::class;

    protected static ?string $title = 'Создать обмен интеграции';

    public function getBreadcrumb(): string
    {
        return 'Создать обмен интеграции';
    }
}
