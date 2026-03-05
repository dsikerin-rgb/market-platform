<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Market;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TicketChatNotification;
use Illuminate\Support\Collection;

class TicketChatNotificationRouter
{
    public function notifyOnTicketCreated(Ticket $ticket, User $sender): void
    {
        $recipients = $this->resolveRecipients($ticket, $sender);
        if ($recipients->isEmpty()) {
            return;
        }

        $title = 'Новое обращение';
        $body = sprintf(
            '#%d: %s',
            (int) $ticket->id,
            (string) $ticket->subject
        );

        $adminUrl = $this->buildAdminUrl($ticket);
        $tenantUrl = $this->buildTenantUrl($ticket);

        foreach ($recipients as $recipient) {
            $this->send(
                ticket: $ticket,
                eventType: TicketChatNotification::EVENT_REQUEST_CREATED,
                recipient: $recipient,
                title: $title,
                body: $body,
                url: $this->resolveUrlForRecipient($recipient, $sender, $adminUrl, $tenantUrl),
            );
        }
    }

    public function notifyOnCommentCreated(Ticket $ticket, User $sender): void
    {
        $recipients = $this->resolveRecipients($ticket, $sender);
        if ($recipients->isEmpty()) {
            return;
        }

        $title = 'Новое сообщение в чате';
        $body = sprintf(
            'Заявка #%d: %s',
            (int) $ticket->id,
            (string) $ticket->subject
        );

        $adminUrl = $this->buildAdminUrl($ticket);
        $tenantUrl = $this->buildTenantUrl($ticket);

        foreach ($recipients as $recipient) {
            $this->send(
                ticket: $ticket,
                eventType: TicketChatNotification::EVENT_MESSAGE_CREATED,
                recipient: $recipient,
                title: $title,
                body: $body,
                url: $this->resolveUrlForRecipient($recipient, $sender, $adminUrl, $tenantUrl),
            );
        }
    }

    /**
     * @return Collection<int, User>
     */
    private function resolveRecipients(Ticket $ticket, User $sender): Collection
    {
        if ($this->isTenantSender($ticket, $sender)) {
            $assignee = $this->resolveAssigneeRecipient($ticket, $sender);
            if ($assignee instanceof User) {
                return collect([$assignee]);
            }

            return $this->resolveMarketChatRecipients($ticket, $sender);
        }

        $tenantRecipients = User::query()
            ->where('tenant_id', (int) $ticket->tenant_id)
            ->where('id', '!=', (int) $sender->id)
            ->get();

        if ($tenantRecipients->isNotEmpty()) {
            return $tenantRecipients;
        }

        $assignee = $this->resolveAssigneeRecipient($ticket, $sender);
        if ($assignee instanceof User) {
            return collect([$assignee]);
        }

        return collect();
    }

    private function resolveAssigneeRecipient(Ticket $ticket, User $sender): ?User
    {
        $assigneeId = (int) ($ticket->assigned_to ?? 0);
        if ($assigneeId <= 0 || $assigneeId === (int) $sender->id) {
            return null;
        }

        return User::query()
            ->whereKey($assigneeId)
            ->where('market_id', (int) $ticket->market_id)
            ->first();
    }

    /**
     * @return Collection<int, User>
     */
    private function resolveMarketChatRecipients(Ticket $ticket, User $sender): Collection
    {
        $market = Market::query()
            ->select(['id', 'settings'])
            ->find((int) $ticket->market_id);

        if (! $market) {
            return collect();
        }

        $settings = (array) ($market->settings ?? []);
        $categoryRecipientIds = null;
        $ticketCategory = (string) ($ticket->category ?? '');
        if ($ticketCategory === 'repair') {
            $categoryRecipientIds = $settings['request_repair_notification_recipient_user_ids'] ?? null;
        } elseif ($ticketCategory === 'help') {
            $categoryRecipientIds = $settings['request_help_notification_recipient_user_ids'] ?? null;
        }

        $recipientIds = $categoryRecipientIds
            ?? $settings['request_notification_recipient_user_ids']
            ?? $settings['holiday_notification_recipient_user_ids']
            ?? [];

        $recipientIds = array_values(array_filter(
            (array) $recipientIds,
            static fn ($value): bool => is_numeric($value),
        ));

        if ($recipientIds === []) {
            return collect();
        }

        return User::query()
            ->where('market_id', (int) $ticket->market_id)
            ->whereIn('id', $recipientIds)
            ->where('id', '!=', (int) $sender->id)
            ->get();
    }

    private function isTenantSender(Ticket $ticket, User $sender): bool
    {
        $senderTenantId = (int) ($sender->tenant_id ?? 0);
        $ticketTenantId = (int) ($ticket->tenant_id ?? 0);

        return $senderTenantId > 0 && $senderTenantId === $ticketTenantId;
    }

    private function buildAdminUrl(Ticket $ticket): string
    {
        return url('/admin/requests?' . http_build_query([
            'tenant_id' => (int) ($ticket->tenant_id ?? 0),
            'ticket_id' => (int) $ticket->id,
        ]));
    }

    private function buildTenantUrl(Ticket $ticket): string
    {
        try {
            return route('cabinet.requests.show', ['ticketId' => (int) $ticket->id]);
        } catch (\Throwable) {
            return url('/cabinet/requests/' . (int) $ticket->id);
        }
    }

    private function resolveUrlForRecipient(
        User $recipient,
        User $sender,
        string $adminUrl,
        string $tenantUrl
    ): string {
        $senderTenantId = (int) ($sender->tenant_id ?? 0);
        $recipientTenantId = (int) ($recipient->tenant_id ?? 0);

        if ($senderTenantId > 0 && $recipientTenantId === 0) {
            return $adminUrl;
        }

        if ($senderTenantId === 0 && $recipientTenantId > 0) {
            return $tenantUrl;
        }

        return $adminUrl;
    }

    private function send(
        Ticket $ticket,
        string $eventType,
        User $recipient,
        string $title,
        string $body,
        ?string $url = null
    ): void
    {
        $recipient->notify(new TicketChatNotification(
            ticket: $ticket,
            eventType: $eventType,
            title: $title,
            body: $body,
            url: $url,
        ));
    }
}
