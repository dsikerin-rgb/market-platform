<?php

namespace App\Filament\Resources\TenantResource\Pages;

use App\Filament\Resources\TenantResource;
use App\Filament\Resources\Pages\BaseCreateRecord;

class CreateTenant extends BaseCreateRecord
{
    protected static string $resource = TenantResource::class;

    protected static ?string $title = 'Создать арендатора';

    public function getBreadcrumb(): string
    {
        return 'Создать арендатора';
    }
}
