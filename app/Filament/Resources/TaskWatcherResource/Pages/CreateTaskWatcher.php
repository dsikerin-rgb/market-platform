<?php

namespace App\Filament\Resources\TaskWatcherResource\Pages;

use App\Filament\Resources\TaskWatcherResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTaskWatcher extends CreateRecord
{
    protected static string $resource = TaskWatcherResource::class;

    protected static ?string $title = 'Добавить наблюдателя';

    public function getBreadcrumb(): string
    {
        return 'Добавить наблюдателя';
    }
}
