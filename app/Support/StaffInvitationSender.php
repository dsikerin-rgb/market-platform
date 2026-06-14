<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\StaffInvitation;
use App\Notifications\StaffInvitationNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class StaffInvitationSender
{
    public function issueToken(StaffInvitation $invitation, bool $extendExpiration = false): string
    {
        $token = Str::random(64);

        $payload = [
            'token_hash' => hash('sha256', $token),
            'accepted_at' => null,
        ];

        if ($extendExpiration || $invitation->expires_at === null || $invitation->expires_at->isPast()) {
            $payload['expires_at'] = now()->addDays(7);
        }

        $invitation->forceFill($payload)->save();

        return $token;
    }

    public function send(StaffInvitation $invitation, string $token): void
    {
        $invitation->loadMissing('market');

        Notification::route('mail', (string) $invitation->email)
            ->notify(new StaffInvitationNotification($invitation, $this->acceptUrl($invitation, $token)));
    }

    public function issueAndSend(StaffInvitation $invitation, bool $extendExpiration = false): string
    {
        $token = $this->issueToken($invitation, $extendExpiration);
        $this->send($invitation, $token);

        return $token;
    }

    public function acceptUrl(StaffInvitation $invitation, string $token): string
    {
        return route('staff-invitations.accept', [
            'invitation' => $invitation,
            'token' => $token,
        ]);
    }
}
