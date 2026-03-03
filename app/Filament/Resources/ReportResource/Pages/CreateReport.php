<?php

namespace App\Filament\Resources\ReportResource\Pages;

use App\Filament\Resources\ReportResource;
use App\Filament\Resources\Pages\BaseCreateRecord;

class CreateReport extends BaseCreateRecord
{
    protected static string $resource = ReportResource::class;

    protected static ?string $title = 'Создать отчёт';

    public function getBreadcrumb(): string
    {
        return 'Создать отчёт';
    }
}
