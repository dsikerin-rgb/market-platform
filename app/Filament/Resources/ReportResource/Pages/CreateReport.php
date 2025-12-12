<?php

namespace App\Filament\Resources\ReportResource\Pages;

use App\Filament\Resources\ReportResource;
use Filament\Resources\Pages\CreateRecord;

class CreateReport extends CreateRecord
{
    protected static string $resource = ReportResource::class;

    protected static ?string $title = 'Создать отчёт';

    public function getBreadcrumb(): string
    {
        return 'Создать отчёт';
    }
}
