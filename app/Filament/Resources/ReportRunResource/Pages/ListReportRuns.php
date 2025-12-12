<?php

namespace App\Filament\Resources\ReportRunResource\Pages;

use App\Filament\Resources\ReportRunResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListReportRuns extends ListRecords
{
    protected static string $resource = ReportRunResource::class;

    protected static ?string $title = 'Запуски отчётов';

    public function getBreadcrumb(): string
    {
        return 'Запуски отчётов';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Создать запуск'),
        ];
    }
}
