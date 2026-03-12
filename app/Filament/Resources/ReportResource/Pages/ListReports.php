<?php

namespace App\Filament\Resources\ReportResource\Pages;

use App\Filament\Resources\ReportResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListReports extends ListRecords
{
    protected static string $resource = ReportResource::class;

    protected static ?string $title = 'Отчёты';

    public function getBreadcrumb(): string
    {
        return 'Отчёты';
    }

    protected function getHeaderActions(): array
    {
        $createAction = Actions\CreateAction::make()
            ->label('Создать отчёт');

        if (method_exists($createAction, 'slideOver')) {
            $createAction->slideOver();
        }

        if (method_exists($createAction, 'modalWidth')) {
            $createAction->modalWidth('5xl');
        }

        return [$createAction];
    }
}
