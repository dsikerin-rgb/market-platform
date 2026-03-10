<?php

declare(strict_types=1);

namespace App\Filament\Resources\TenantContractResource\Pages;

use App\Filament\Resources\TenantContractResource;
use Filament\Resources\Pages\ListRecords;

class ListTenantContracts extends ListRecords
{
    protected static string $resource = TenantContractResource::class;

    protected static ?string $title = 'Договоры';

    public function getBreadcrumb(): string
    {
        return 'Договоры';
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
