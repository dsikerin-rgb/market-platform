<?php

namespace App\Filament\Resources\TaskWatcherResource\Pages;

use App\Filament\Resources\TaskWatcherResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditTaskWatcher extends EditRecord
{
    protected static string $resource = TaskWatcherResource::class;

    protected static ?string $title = 'Редактировать наблюдателя';

    public function getBreadcrumb(): string
    {
        return 'Редактировать наблюдателя';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->label('Удалить'),
        ];
    }
}
