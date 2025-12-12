<?php

namespace App\Filament\Resources\StaffInvitationResource\Pages;

use App\Filament\Resources\StaffInvitationResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListStaffInvitations extends ListRecords
{
    protected static string $resource = StaffInvitationResource::class;

    protected static ?string $title = 'Приглашения';

    public function getBreadcrumb(): string
    {
        return 'Приглашения';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Создать приглашение'),
        ];
    }
}
