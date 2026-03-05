<?php

namespace App\Filament\Resources\ReportRunResource\Pages;

use App\Filament\Resources\ReportRunResource;
use App\Filament\Resources\Pages\BaseCreateRecord;

class CreateReportRun extends BaseCreateRecord
{
    protected static string $resource = ReportRunResource::class;

    protected static ?string $title = 'Создать запуск отчёта';
}
