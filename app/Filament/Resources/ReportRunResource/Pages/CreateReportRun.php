<?php

namespace App\Filament\Resources\ReportRunResource\Pages;

use App\Filament\Resources\ReportRunResource;
use Filament\Resources\Pages\CreateRecord;

class CreateReportRun extends CreateRecord
{
    protected static string $resource = ReportRunResource::class;

    protected static ?string $title = 'Создать запуск отчёта';

    public function getBreadcrumb(): string
    {
        return 'Создать запуск отчёта';
    }
}
