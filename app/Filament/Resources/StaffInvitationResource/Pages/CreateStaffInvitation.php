<?php

namespace App\Filament\Resources\StaffInvitationResource\Pages;

use App\Filament\Resources\StaffInvitationResource;
use App\Filament\Resources\Pages\BaseCreateRecord;
use App\Support\StaffInvitationSender;
use Filament\Notifications\Notification;
use Illuminate\Support\Str;

class CreateStaffInvitation extends BaseCreateRecord
{
    protected static string $resource = StaffInvitationResource::class;

    private ?string $plainInvitationToken = null;

    protected static ?string $title = 'Создать приглашение';

    public function getPageClasses(): array
    {
        return [
            ...parent::getPageClasses(),
            'fi-resource-invitations-create-page',
        ];
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->plainInvitationToken = Str::random(64);
        $data['token_hash'] = hash('sha256', $this->plainInvitationToken);

        return $data;
    }

    protected function afterCreate(): void
    {
        if ($this->plainInvitationToken === null) {
            return;
        }

        try {
            app(StaffInvitationSender::class)->send($this->record, $this->plainInvitationToken);

            Notification::make()
                ->title('Приглашение отправлено')
                ->success()
                ->send();
        } catch (\Throwable $e) {
            report($e);

            Notification::make()
                ->title('Не удалось отправить приглашение')
                ->body('Проверьте настройки почты и попробуйте повторную отправку.')
                ->danger()
                ->send();
        }
    }
}
