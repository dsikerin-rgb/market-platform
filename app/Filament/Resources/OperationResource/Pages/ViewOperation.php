<?php

namespace App\Filament\Resources\OperationResource\Pages;

use App\Filament\Resources\OperationResource;
use Filament\Resources\Pages\ViewRecord;

class ViewOperation extends ViewRecord
{
    protected static string $resource = OperationResource::class;

    protected static ?string $title = 'Операция';

    public function getBreadcrumb(): string
    {
        return 'Просмотр';
    }
}
