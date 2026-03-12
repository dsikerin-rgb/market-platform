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
        $createAction = Actions\CreateAction::make()
            ->label('Создать запуск');

        if (method_exists($createAction, 'slideOver')) {
            $createAction->slideOver();
        }

        if (method_exists($createAction, 'modalWidth')) {
            $createAction->modalWidth('5xl');
        }

        return [$createAction];
    }
}
