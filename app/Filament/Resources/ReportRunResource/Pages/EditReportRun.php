<?php

namespace App\Filament\Resources\ReportRunResource\Pages;

use App\Filament\Resources\ReportRunResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditReportRun extends EditRecord
{
    protected static string $resource = ReportRunResource::class;

    protected static ?string $title = 'Редактировать запуск отчёта';

    public function getBreadcrumb(): string
    {
        return 'Редактировать запуск отчёта';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->label('Удалить'),
        ];
    }
}
