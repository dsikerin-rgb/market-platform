<?php

namespace App\Filament\Resources\StaffInvitationResource\Pages;

use App\Filament\Resources\StaffInvitationResource;
use Filament\Resources\Pages\CreateRecord;

class CreateStaffInvitation extends CreateRecord
{
    protected static string $resource = StaffInvitationResource::class;

    protected static ?string $title = 'Создать приглашение';

    public function getBreadcrumb(): string
    {
        return 'Создать приглашение';
    }
}
