<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SendMailSmokeTest extends Command
{
    protected $signature = 'mail:smoke-test
        {recipient : Email address that should receive the test message}
        {--subject= : Override the default test subject}';

    protected $description = 'Sends a simple test email through the configured Laravel mailer.';

    public function handle(): int
    {
        $recipient = trim((string) $this->argument('recipient'));

        if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('Recipient must be a valid email address.');

            return Command::INVALID;
        }

        $subject = trim((string) ($this->option('subject') ?? ''));
        if ($subject === '') {
            $subject = 'Market Platform mail smoke test';
        }

        $mailer = (string) config('mail.default');
        $fromAddress = (string) config('mail.from.address');
        $fromName = (string) config('mail.from.name');

        $body = implode(PHP_EOL, [
            'Market Platform mail smoke test.',
            '',
            'Environment: ' . (string) config('app.env'),
            'Mailer: ' . $mailer,
            'From: ' . trim($fromName . ' <' . $fromAddress . '>'),
            'Sent at: ' . now()->toDateTimeString(),
        ]);

        try {
            Mail::raw($body, function ($message) use ($recipient, $subject): void {
                $message->to($recipient)->subject($subject);
            });
        } catch (\Throwable $e) {
            $this->error('Mail smoke test failed: ' . $e->getMessage());

            return Command::FAILURE;
        }

        $this->info("Mail smoke test sent to {$recipient} using mailer [{$mailer}].");

        return Command::SUCCESS;
    }
}
