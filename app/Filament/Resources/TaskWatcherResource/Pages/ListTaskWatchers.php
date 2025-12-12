<?php

namespace App\Filament\Resources\TaskWatcherResource\Pages;

use App\Filament\Resources\TaskWatcherResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListTaskWatchers extends ListRecords
{
    protected static string $resource = TaskWatcherResource::class;

    protected static ?string $title = 'Наблюдатели задач';

    public function getBreadcrumb(): string
    {
        return 'Наблюдатели задач';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Добавить наблюдателя'),
        ];
    }
}
