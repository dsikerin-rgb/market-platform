<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\StaffInvitation;
use App\Support\MarketplaceMediaStorage;
use App\Support\MarketplaceSettingsValue;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class StaffInvitationNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly StaffInvitation $invitation,
        private readonly string $acceptUrl,
    ) {
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $market = $this->invitation->market;
        $marketName = trim((string) ($market?->name ?? 'Market Platform'));
        $marketSettings = is_array($market?->settings) ? $market->settings : [];
        $marketplaceSettings = is_array($marketSettings['marketplace'] ?? null)
            ? $marketSettings['marketplace']
            : [];
        $brandName = MarketplaceSettingsValue::string($marketplaceSettings['brand_name'] ?? null, $marketName);
        $expiresAt = $this->invitation->expires_at?->timezone(config('app.timezone'))->format('d.m.Y H:i');

        return (new MailMessage())
            ->subject('Приглашение в команду рынка «' . $marketName . '»')
            ->view([
                'mail.staff-invitation',
                'mail.staff-invitation-text',
            ], [
                'acceptUrl' => $this->acceptUrl,
                'brandName' => $brandName !== '' ? $brandName : $marketName,
                'expiresAt' => $expiresAt,
                'logoUrl' => $this->logoUrl($marketplaceSettings),
                'marketName' => $marketName,
            ]);
    }

    /**
     * @param  array<string, mixed>  $marketplaceSettings
     */
    private function logoUrl(array $marketplaceSettings): ?string
    {
        $logoPath = MarketplaceSettingsValue::nullablePath($marketplaceSettings['logo_path'] ?? null);

        return $logoPath !== null ? MarketplaceMediaStorage::url($logoPath) : null;
    }
}
