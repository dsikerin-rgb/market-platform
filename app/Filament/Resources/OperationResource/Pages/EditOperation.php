<?php

namespace App\Filament\Resources\OperationResource\Pages;

use App\Filament\Resources\OperationResource;
use App\Filament\Resources\Pages\BaseEditRecord;

class EditOperation extends BaseEditRecord
{
    protected static string $resource = OperationResource::class;

    protected static ?string $title = 'Редактировать операцию';

    public function getBreadcrumb(): string
    {
        return 'Редактировать';
    }
}
