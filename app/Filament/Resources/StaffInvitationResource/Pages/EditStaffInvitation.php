<?php

namespace App\Filament\Resources\StaffInvitationResource\Pages;

use App\Filament\Resources\Pages\BaseEditRecord;
use App\Filament\Resources\StaffInvitationResource;
use App\Support\StaffInvitationSender;
use Filament\Actions;
use Filament\Notifications\Notification;

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
            Actions\Action::make('resend_invitation')
                ->label('Отправить повторно')
                ->icon('heroicon-o-paper-airplane')
                ->requiresConfirmation()
                ->modalHeading('Отправить приглашение повторно?')
                ->modalDescription('Старая ссылка перестанет работать, сотрудник получит новую ссылку на email.')
                ->modalSubmitActionLabel('Отправить')
                ->action(function (): void {
                    try {
                        app(StaffInvitationSender::class)->issueAndSend($this->record, true);

                        Notification::make()
                            ->title('Приглашение отправлено')
                            ->success()
                            ->send();
                    } catch (\Throwable $e) {
                        report($e);

                        Notification::make()
                            ->title('Не удалось отправить приглашение')
                            ->body('Проверьте настройки почты и попробуйте ещё раз.')
                            ->danger()
                            ->send();
                    }
                }),

            Actions\DeleteAction::make()
                ->label('Удалить'),
        ];
    }

    public function getPageClasses(): array
    {
        return [
            ...parent::getPageClasses(),
            'fi-resource-invitations-edit-page',
        ];
    }
}
