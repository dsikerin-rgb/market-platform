<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\IntegrationExchange;
use App\Support\NotificationChannelResolver;
use App\Support\UserNotificationPreferences;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OneCIntegrationExchangeNotification extends Notification
{
    private readonly string $finishedAt;

    public function __construct(
        private readonly IntegrationExchange $exchange,
    ) {
        $this->exchange->loadMissing('market');
        $this->finishedAt = ($this->exchange->finished_at ?? now())->format('Y-m-d H:i:s');
    }

    public function via(object $notifiable): array
    {
        return app(NotificationChannelResolver::class)->resolve(
            $notifiable,
            UserNotificationPreferences::TOPIC_ONE_C_INTEGRATIONS,
            (int) $this->exchange->market_id,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $notification = FilamentNotification::make()
            ->title('Новый обмен 1С')
            ->body($this->buildBody());

        if ($this->isError()) {
            $notification->danger();
        } else {
            $notification->success();
        }

        return $notification->getDatabaseMessage();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'integration_exchange_id' => (int) $this->exchange->getKey(),
            'market_id' => (int) $this->exchange->market_id,
            'market_name' => $this->marketName(),
            'entity_type' => (string) $this->exchange->entity_type,
            'entity_label' => $this->entityLabel(),
            'direction' => (string) $this->exchange->direction,
            'status' => (string) $this->exchange->status,
            'status_label' => $this->statusLabel(),
            'counters' => $this->counters(),
            'error' => $this->errorText(),
            'finished_at' => $this->finishedAt,
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
        $message = (new MailMessage())
            ->subject('Новый обмен 1С: ' . $this->statusLabel())
            ->line('Сущность: ' . $this->entityLabel())
            ->line('Направление: ' . $this->directionLabel())
            ->line('Статус: ' . $this->statusLabel())
            ->line('Счётчики: ' . $this->countersText())
            ->line('Время: ' . $this->finishedAt);

        $marketName = $this->marketName();
        if ($marketName !== '') {
            $message->line('Рынок: ' . $marketName);
        }

        $error = $this->errorText();
        if ($error !== '') {
            $message->line('Ошибка: ' . $this->truncate($error, 500));
        }

        return $message;
    }

    private function buildBody(): string
    {
        $parts = [
            'Сущность: ' . $this->entityLabel(),
            'Напр.: ' . $this->directionLabel(),
            'Статус: ' . $this->statusLabel(),
            'Счётчики: ' . $this->countersText(),
            'Время: ' . $this->finishedAt,
        ];

        $marketName = $this->marketName();
        if ($marketName !== '') {
            $parts[] = 'Рынок: ' . $marketName;
        }

        $error = $this->errorText();
        if ($error !== '') {
            $parts[] = 'Ошибка: ' . $this->truncate($error, 180);
        }

        return implode(' | ', $parts);
    }

    private function buildTelegramText(): string
    {
        $lines = [
            'Новый обмен 1С',
            'Сущность: ' . $this->entityLabel(),
            'Напр.: ' . $this->directionLabel(),
            'Статус: ' . $this->statusLabel(),
            'Счётчики: ' . $this->countersText(),
            'Время: ' . $this->finishedAt,
        ];

        $marketName = $this->marketName();
        if ($marketName !== '') {
            $lines[] = 'Рынок: ' . $marketName;
        }

        $error = $this->errorText();
        if ($error !== '') {
            $lines[] = 'Ошибка: ' . $this->truncate($error, 300);
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * @return array{
     *   received:int|null,
     *   inserted:int|null,
     *   created:int|null,
     *   updated:int|null,
     *   skipped:int|null
     * }
     */
    private function counters(): array
    {
        $payload = (array) ($this->exchange->payload ?? []);

        return [
            'received' => isset($payload['received']) ? (int) $payload['received'] : null,
            'inserted' => isset($payload['inserted']) ? (int) $payload['inserted'] : null,
            'created' => isset($payload['created']) ? (int) $payload['created'] : null,
            'updated' => isset($payload['updated']) ? (int) $payload['updated'] : null,
            'skipped' => isset($payload['skipped']) ? (int) $payload['skipped'] : null,
        ];
    }

    private function countersText(): string
    {
        $counters = $this->counters();
        $parts = [];

        if ($counters['received'] !== null) {
            $parts[] = 'R:' . $counters['received'];
        }

        if ($counters['inserted'] !== null) {
            $parts[] = 'I:' . $counters['inserted'];
        }

        if ($counters['created'] !== null) {
            $parts[] = 'C:' . $counters['created'];
        }

        if ($counters['updated'] !== null) {
            $parts[] = 'U:' . $counters['updated'];
        }

        if ($counters['skipped'] !== null) {
            $parts[] = 'S:' . $counters['skipped'];
        }

        return $parts === [] ? '—' : implode(' · ', $parts);
    }

    private function entityLabel(): string
    {
        return match ((string) $this->exchange->entity_type) {
            'contract_debts' => 'Долги',
            'contracts' => 'Договоры',
            default => (string) $this->exchange->entity_type,
        };
    }

    private function directionLabel(): string
    {
        return mb_strtoupper((string) $this->exchange->direction);
    }

    private function statusLabel(): string
    {
        return match ((string) $this->exchange->status) {
            IntegrationExchange::STATUS_OK => 'OK',
            IntegrationExchange::STATUS_ERROR => 'ERROR',
            IntegrationExchange::STATUS_IN_PROGRESS => 'В работе',
            default => (string) $this->exchange->status,
        };
    }

    private function marketName(): string
    {
        return trim((string) ($this->exchange->market->name ?? ''));
    }

    private function errorText(): string
    {
        $error = trim((string) ($this->exchange->error ?? ''));

        return $error;
    }

    private function isError(): bool
    {
        return $this->exchange->status === IntegrationExchange::STATUS_ERROR;
    }

    private function truncate(string $value, int $limit): string
    {
        if (mb_strlen($value) <= $limit) {
            return $value;
        }

        return mb_substr($value, 0, $limit - 3) . '...';
    }
}
