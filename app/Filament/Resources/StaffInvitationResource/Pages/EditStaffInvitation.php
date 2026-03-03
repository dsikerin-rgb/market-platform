<?php

namespace App\Filament\Resources\StaffInvitationResource\Pages;

use App\Filament\Resources\StaffInvitationResource;
use Filament\Actions;
use App\Filament\Resources\Pages\BaseEditRecord;

class EditStaffInvitation extends BaseEditRecord
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
