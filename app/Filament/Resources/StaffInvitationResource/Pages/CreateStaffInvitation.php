<?php

namespace App\Filament\Resources\StaffInvitationResource\Pages;

use App\Filament\Resources\StaffInvitationResource;
use App\Filament\Resources\Pages\BaseCreateRecord;
use App\Support\StaffInvitationSender;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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
        $data['expires_at'] = $this->normalizeExpiresAt($data['expires_at'] ?? null);

        $this->plainInvitationToken = Str::random(64);
        $data['token_hash'] = hash('sha256', $this->plainInvitationToken);

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
