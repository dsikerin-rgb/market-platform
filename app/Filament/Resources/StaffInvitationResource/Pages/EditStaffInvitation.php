<?php

namespace App\Filament\Resources\StaffInvitationResource\Pages;

use App\Filament\Resources\StaffInvitationResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditStaffInvitation extends EditRecord
{
    protected static string $resource = StaffInvitationResource::class;

    protected static ?string $title = 'Редактировать приглашение';

    public function getBreadcrumb(): string
    {
        return 'Редактировать приглашение';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->label('Удалить'),
        ];
    }
}
