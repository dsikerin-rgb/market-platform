<?php

namespace App\Filament\Resources\OperationResource\Pages;

use App\Filament\Resources\OperationResource;
use Filament\Resources\Pages\EditRecord;

class EditOperation extends EditRecord
{
    protected static string $resource = OperationResource::class;

    protected static ?string $title = 'Редактировать операцию';

    public function getBreadcrumb(): string
    {
        return 'Редактировать';
    }
}
