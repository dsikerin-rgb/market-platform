<?php

namespace App\Filament\Resources\TaskCommentResource\Pages;

use App\Filament\Resources\TaskCommentResource;
use App\Filament\Resources\Pages\BaseCreateRecord;

class CreateTaskComment extends BaseCreateRecord
{
    protected static string $resource = TaskCommentResource::class;

    protected static ?string $title = 'Создать комментарий';
}
