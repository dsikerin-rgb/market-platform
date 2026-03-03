<?php

namespace App\Filament\Resources\StaffInvitationResource\Pages;

use App\Filament\Resources\StaffInvitationResource;
use App\Filament\Resources\Pages\BaseCreateRecord;

class CreateStaffInvitation extends BaseCreateRecord
{
    protected static string $resource = StaffInvitationResource::class;

    protected static ?string $title = 'Создать приглашение';

    public function getBreadcrumb(): string
    {
        return 'Создать приглашение';
    }
}
