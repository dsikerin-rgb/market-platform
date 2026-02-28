<?php

declare(strict_types=1);

# app/Filament/Resources/IntegrationExchangeResource/Pages/CreateIntegrationExchange.php

namespace App\Filament\Resources\IntegrationExchangeResource\Pages;

use App\Filament\Resources\IntegrationExchangeResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateIntegrationExchange extends CreateRecord
{
    protected static string $resource = IntegrationExchangeResource::class;

    protected static ?string $title = 'Создать обмен интеграции';

    public function getBreadcrumb(): string
    {
        return 'Создать обмен';
    }

    /**
     * Ручное создание обменов оставляем только super-admin.
     * Для остальных журнал должен быть “только чтение”.
     */
    protected function authorizeAccess(): void
    {
        parent::authorizeAccess();

        $user = Filament::auth()->user();

        abort_unless((bool) $user && $user->isSuperAdmin(), 403);
    }
}