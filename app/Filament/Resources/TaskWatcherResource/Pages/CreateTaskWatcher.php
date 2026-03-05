<?php

namespace App\Filament\Resources\TaskWatcherResource\Pages;

use App\Filament\Resources\TaskWatcherResource;
use App\Filament\Resources\Pages\BaseCreateRecord;

class CreateTaskWatcher extends BaseCreateRecord
{
    protected static string $resource = TaskWatcherResource::class;

    protected static ?string $title = 'Добавить наблюдателя';
}
