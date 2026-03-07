<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\IntegrationExchange;
use App\Models\User;
use App\Notifications\OneCIntegrationExchangeNotification;
use App\Support\UserNotificationPreferences;

class IntegrationExchangeObserver
{
    public function updated(IntegrationExchange $exchange): void
    {
        if (! $this->shouldNotify($exchange)) {
            return;
        }

        $notification = new OneCIntegrationExchangeNotification($exchange);

        $superAdmins = User::query()
            ->whereHas('roles', fn ($query) => $query->where('name', 'super-admin'))
            ->get();

        if ($superAdmins->isEmpty()) {
            return;
        }

        foreach ($superAdmins as $superAdmin) {
            if (! $this->canReceiveOneCNotifications($superAdmin)) {
                continue;
            }

            $superAdmin->notify($notification);
        }
    }

    private function shouldNotify(IntegrationExchange $exchange): bool
    {
        if (! $exchange->wasChanged('status')) {
            return false;
        }

        if (! $exchange->wasChanged('finished_at')) {
            return false;
        }

        if ($exchange->getOriginal('status') !== IntegrationExchange::STATUS_IN_PROGRESS) {
            return false;
        }

        if (! in_array($exchange->status, [
            IntegrationExchange::STATUS_OK,
            IntegrationExchange::STATUS_ERROR,
        ], true)) {
            return false;
        }

        return $this->isOneCExchange($exchange);
    }

    private function isOneCExchange(IntegrationExchange $exchange): bool
    {
        $payload = (array) ($exchange->payload ?? []);
        $endpoint = trim((string) ($payload['endpoint'] ?? ''));

        if ($endpoint !== '' && str_starts_with($endpoint, '/api/1c/')) {
            return true;
        }

        return in_array($exchange->entity_type, ['contracts', 'contract_debts'], true);
    }

    private function canReceiveOneCNotifications(User $user): bool
    {
        return app(UserNotificationPreferences::class)->isTopicEnabled(
            $user,
            UserNotificationPreferences::TOPIC_ONE_C_INTEGRATIONS,
        );
    }
}
