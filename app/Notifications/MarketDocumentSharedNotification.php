<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\MarketDocument;
use App\Models\User;
use App\Notifications\Channels\TelegramChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MarketDocumentSharedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param list<string> $channels
     */
    public function __construct(
        private readonly MarketDocument $document,
        private readonly User $author,
        private readonly string $message,
        private readonly array $channels,
    ) {
    }

    /**
     * @return list<string|class-string>
     */
    public function via(object $notifiable): array
    {
        $channels = [];

        if (in_array('mail', $this->channels, true) && filled($notifiable->email ?? null)) {
            $channels[] = 'mail';
        }

        if (in_array('telegram', $this->channels, true) && filled($notifiable->telegram_chat_id ?? null)) {
            $channels[] = TelegramChannel::class;
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $fileName = $this->document->resolvedFileName();
        $authorName = trim((string) ($this->author->name ?: $this->author->email));
        $message = trim($this->message);
        $url = $this->documentUrl();

        $mail = (new MailMessage())
            ->subject('Вам открыт доступ к файлу')
            ->greeting('Здравствуйте!')
            ->line(($authorName !== '' ? $authorName : 'Сотрудник') . ' поделился с вами файлом: ' . $fileName);

        if ($message !== '') {
            $mail->line($message);
        }

        if ($url !== '') {
            $mail->action('Открыть файл', $url);
        }

        return $mail;
    }

    /**
     * @return array{text:string, url:string}
     */
    public function toTelegram(object $notifiable): array
    {
        $authorName = trim((string) ($this->author->name ?: $this->author->email));
        $message = trim($this->message);
        $text = ($authorName !== '' ? $authorName : 'Сотрудник') . ' поделился с вами файлом: ' . $this->document->resolvedFileName();

        if ($message !== '') {
            $text .= PHP_EOL . $message;
        }

        return [
            'text' => $text,
            'url' => $this->documentUrl(),
        ];
    }

    private function documentUrl(): string
    {
        $url = $this->document->temporaryDownloadUrl();

        return is_string($url) ? $url : url('/admin/market-documents?tab=shared-with-me');
    }
}
