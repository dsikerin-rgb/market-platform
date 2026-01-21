<?php

namespace App\Filament\Resources\TenantAccruals\Pages;

use App\Filament\Resources\TenantAccruals\TenantAccrualResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTenantAccrual extends CreateRecord
{
    protected static string $resource = TenantAccrualResource::class;
}
