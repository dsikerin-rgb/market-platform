<?php

namespace App\Filament\Resources\TaskCommentResource\Pages;

use App\Filament\Resources\TaskCommentResource;
use Filament\Actions;
use App\Filament\Resources\Pages\BaseEditRecord;

class EditTaskComment extends BaseEditRecord
{
    protected static string $resource = TaskCommentResource::class;

    protected static ?string $title = 'Редактировать комментарий';

    public function getBreadcrumb(): string
    {
        return 'Редактировать комментарий';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->label('Удалить'),
        ];
    }
}
