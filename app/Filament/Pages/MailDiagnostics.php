<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Mail;

class MailDiagnostics extends Page
{
    protected static ?string $title = 'Диагностика почты';

    protected static ?string $navigationLabel = 'Почта';

    protected static \UnitEnum|string|null $navigationGroup = 'Настройки';

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-envelope';

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'market-settings/mail';

    protected string $view = 'filament.pages.mail-diagnostics';

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function canAccess(): bool
    {
        return (bool) (Filament::auth()->user()?->isSuperAdmin() ?? false);
    }

    public function getHeading(): string|Htmlable|null
    {
        return null;
    }

    /**
     * @return array<string, string>
     */
    public function mailStatus(): array
    {
        return [
            'mailer' => (string) config('mail.default'),
            'host' => (string) config('mail.mailers.smtp.host'),
            'port' => (string) config('mail.mailers.smtp.port'),
            'scheme' => (string) (config('mail.mailers.smtp.scheme') ?? 'not set'),
            'username' => filled(config('mail.mailers.smtp.username')) ? 'set' : 'not set',
            'from' => trim((string) config('mail.from.name') . ' <' . (string) config('mail.from.address') . '>'),
            'queue' => (string) config('queue.default'),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('sendTestMail')
                ->label('Отправить тест')
                ->icon('heroicon-o-paper-airplane')
                ->color('primary')
                ->modalHeading('Тестовая отправка email')
                ->modalDescription('Письмо будет отправлено через текущий mailer из конфигурации Laravel.')
                ->form([
                    TextInput::make('recipient')
                        ->label('Email получателя')
                        ->email()
                        ->required()
                        ->default(fn (): ?string => Filament::auth()->user()?->email),
                    TextInput::make('subject')
                        ->label('Тема')
                        ->default('Market Platform mail smoke test')
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $recipient = trim((string) ($data['recipient'] ?? ''));
                    $subject = trim((string) ($data['subject'] ?? ''));

                    if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
                        Notification::make()
                            ->title('Некорректный email')
                            ->warning()
                            ->send();

                        return;
                    }

                    try {
                        Mail::raw($this->testMessageBody(), function ($message) use ($recipient, $subject): void {
                            $message->to($recipient)->subject($subject);
                        });
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('Письмо не отправлено')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Тестовое письмо отправлено')
                        ->body('Получатель: ' . $recipient)
                        ->success()
                        ->send();
                }),
        ];
    }

    private function testMessageBody(): string
    {
        return implode(PHP_EOL, [
            'Market Platform mail smoke test.',
            '',
            'Environment: ' . (string) config('app.env'),
            'Mailer: ' . (string) config('mail.default'),
            'Sent at: ' . now()->toDateTimeString(),
        ]);
    }
}
