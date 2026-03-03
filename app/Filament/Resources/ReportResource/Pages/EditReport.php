<?php

namespace App\Filament\Resources\ReportResource\Pages;

use App\Filament\Resources\ReportResource;
use Filament\Actions;
use App\Filament\Resources\Pages\BaseEditRecord;

class EditReport extends BaseEditRecord
{
    protected static string $resource = ReportResource::class;

    protected static ?string $title = 'Редактировать отчёт';

    public function getBreadcrumb(): string
    {
        return 'Редактировать отчёт';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->label('Удалить'),
        ];
    }
}
