<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use App\Support\NotificationChannelResolver;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class UserLoggedInNotification extends Notification
{
    private readonly string $loggedInAt;

    public function __construct(
        private readonly User $actor,
        private readonly string $ip = '',
        private readonly string $userAgent = '',
    ) {
        $this->loggedInAt = now()->format('Y-m-d H:i:s');
    }

    public function via(object $notifiable): array
    {
        return app(NotificationChannelResolver::class)->resolve($notifiable, 'security');
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title('Вход в систему')
            ->body($this->buildBody())
            ->warning()
            ->getDatabaseMessage();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'actor_user_id' => (int) $this->actor->getKey(),
            'actor_name' => (string) $this->actor->name,
            'actor_email' => (string) $this->actor->email,
            'actor_market_id' => $this->actor->market_id !== null ? (int) $this->actor->market_id : null,
            'actor_roles' => $this->actorRoleNames(),
            'ip' => $this->ip !== '' ? $this->ip : null,
            'user_agent' => $this->userAgent !== '' ? $this->userAgent : null,
            'logged_in_at' => $this->loggedInAt,
        ];
    }

    /**
     * @return array{text:string}
     */
    public function toTelegram(object $notifiable): array
    {
        return [
            'text' => $this->buildTelegramText(),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject('Вход пользователя в систему')
            ->line('Имя: ' . $this->displayName())
            ->line('Email: ' . $this->displayEmail())
            ->line('Роли: ' . $this->formatRoles())
            ->line('Время: ' . $this->loggedInAt)
            ->line('IP: ' . ($this->ip !== '' ? $this->ip : 'n/a'))
            ->line('User-Agent: ' . ($this->userAgent !== '' ? $this->truncate($this->userAgent, 180) : 'n/a'));
    }

    private function buildBody(): string
    {
        $roles = $this->formatRoles();

        $parts = [];
        $parts[] = 'Имя: ' . $this->displayName();
        $parts[] = 'Email: ' . $this->displayEmail();
        $parts[] = 'Роли: ' . $roles;
        $parts[] = 'Время: ' . $this->loggedInAt;

        if ($this->ip !== '') {
            $parts[] = 'IP: ' . $this->ip;
        }

        if ($this->userAgent !== '') {
            $parts[] = 'UA: ' . $this->truncate($this->userAgent, 180);
        }

        return implode(' | ', $parts);
    }

    private function buildTelegramText(): string
    {
        $lines = [
            'Вход в систему',
            'Имя: ' . $this->displayName(),
            'Email: ' . $this->displayEmail(),
            'Роли: ' . $this->formatRoles(),
            'Время: ' . $this->loggedInAt,
        ];

        if ($this->ip !== '') {
            $lines[] = 'IP: ' . $this->ip;
        }

        if ($this->userAgent !== '') {
            $lines[] = 'UA: ' . $this->truncate($this->userAgent, 180);
        }

        return implode(PHP_EOL, $lines);
    }

    private function truncate(string $value, int $limit): string
    {
        if (mb_strlen($value) <= $limit) {
            return $value;
        }

        return mb_substr($value, 0, $limit - 3) . '...';
    }

    /**
     * @return list<string>
     */
    private function actorRoleNames(): array
    {
        return $this->actor->roles()->pluck('name')->values()->all();
    }

    private function formatRoles(): string
    {
        $roles = $this->actorRoleNames();

        return $roles === [] ? '—' : implode(', ', $roles);
    }

    private function displayName(): string
    {
        $name = trim((string) $this->actor->name);

        return $name !== '' ? $name : 'Без имени';
    }

    private function displayEmail(): string
    {
        $email = trim((string) $this->actor->email);

        return $email !== '' ? $email : 'n/a';
    }
}
