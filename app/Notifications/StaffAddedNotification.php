<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use App\Support\NotificationChannelDefaults;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class StaffAddedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly User $staff,
        private readonly ?User $actor,
    ) {
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return app(NotificationChannelDefaults::class)->resolveWithMailDefault(
            $notifiable,
            'messages',
            (int) ($this->staff->market_id ?? 0),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title('Вас добавили в Market Platform')
            ->body('Аккаунт сотрудника создан. Вы можете войти в админку.')
            ->actions([
                Action::make('open')
                    ->label('Открыть')
                    ->url($this->adminUrl())
                    ->markAsRead(),
            ])
            ->getDatabaseMessage();
    }

    public function toMail(object $notifiable): MailMessage
    {
        $actorName = trim((string) ($this->actor?->name ?? ''));
        $marketName = trim((string) ($this->staff->market?->name ?? 'Market Platform'));

        $mail = (new MailMessage())
            ->subject('Вас добавили в Market Platform')
            ->greeting('Здравствуйте!')
            ->line('Для вас создан аккаунт сотрудника в Market Platform.')
            ->line('Рынок: ' . $marketName);

        if ($actorName !== '') {
            $mail->line('Добавил: ' . $actorName);
        }

        return $mail
            ->line('Используйте пароль, который вам передал администратор.')
            ->action('Войти в сервис', $this->adminUrl());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'staff_user_id' => (int) $this->staff->id,
            'actor_user_id' => (int) ($this->actor?->id ?? 0),
            'market_id' => (int) ($this->staff->market_id ?? 0),
            'url' => $this->adminUrl(),
        ];
    }

    private function adminUrl(): string
    {
        return url('/admin');
    }
}
