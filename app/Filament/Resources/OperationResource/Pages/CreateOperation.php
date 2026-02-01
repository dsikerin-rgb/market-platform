<?php

namespace App\Filament\Resources\OperationResource\Pages;

use App\Filament\Resources\OperationResource;
use Filament\Resources\Pages\CreateRecord;

class CreateOperation extends CreateRecord
{
    protected static string $resource = OperationResource::class;

    protected static ?string $title = 'Создать операцию';

    public function getBreadcrumb(): string
    {
        return 'Создать';
    }
}
