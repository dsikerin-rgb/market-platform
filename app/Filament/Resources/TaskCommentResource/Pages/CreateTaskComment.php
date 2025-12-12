<?php

namespace App\Filament\Resources\TaskCommentResource\Pages;

use App\Filament\Resources\TaskCommentResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTaskComment extends CreateRecord
{
    protected static string $resource = TaskCommentResource::class;

    protected static ?string $title = 'Создать комментарий';

    public function getBreadcrumb(): string
    {
        return 'Создать комментарий';
    }
}
