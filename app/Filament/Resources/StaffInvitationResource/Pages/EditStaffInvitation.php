<?php

namespace App\Filament\Resources\StaffInvitationResource\Pages;

use App\Filament\Resources\Pages\BaseEditRecord;
use App\Filament\Resources\StaffInvitationResource;
use App\Support\StaffInvitationSender;
use Filament\Actions;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

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

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['expires_at'] = $this->normalizeExpiresAt($data['expires_at'] ?? null);

        return $data;
    }

    private function normalizeExpiresAt(mixed $value): Carbon
    {
        if (blank($value)) {
            return now()->addDays(7);
        }

        try {
            $expiresAt = $value instanceof \DateTimeInterface
                ? Carbon::instance($value)
                : Carbon::parse((string) $value);
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'data.expires_at' => 'Укажите корректную дату окончания приглашения.',
            ]);
        }

        if ($expiresAt->lte(now())) {
            throw ValidationException::withMessages([
                'data.expires_at' => 'Дата окончания приглашения должна быть в будущем.',
            ]);
        }

        return $expiresAt;
    }
}
