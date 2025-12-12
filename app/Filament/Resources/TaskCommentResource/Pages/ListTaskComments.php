<?php

namespace App\Filament\Resources\TaskCommentResource\Pages;

use App\Filament\Resources\TaskCommentResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListTaskComments extends ListRecords
{
    protected static string $resource = TaskCommentResource::class;

    protected static ?string $title = 'Комментарии задач';

    public function getBreadcrumb(): string
    {
        return 'Комментарии задач';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Создать комментарий'),
        ];
    }
}
