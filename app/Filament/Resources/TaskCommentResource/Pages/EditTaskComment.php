<?php

namespace App\Filament\Resources\TaskCommentResource\Pages;

use App\Filament\Resources\TaskCommentResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditTaskComment extends EditRecord
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
