<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Market;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class TicketAccessService
{
    public function canView(User $user, Ticket $ticket): bool
    {
        if ($this->isSuperAdmin($user)) {
            return true;
        }

        if ((int) ($user->market_id ?? 0) <= 0 || (int) $user->market_id !== (int) $ticket->market_id) {
            return false;
        }

        if ((int) ($ticket->assigned_to ?? 0) === (int) $user->id) {
            return true;
        }

        if (in_array((int) $user->id, $this->recipientIdsForTicket($ticket), true)) {
            return true;
        }

        return TicketComment::query()
            ->where('ticket_id', (int) $ticket->id)
            ->where('user_id', (int) $user->id)
            ->exists();
    }

    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($this->isSuperAdmin($user)) {
            return;
        }

        $marketId = (int) ($user->market_id ?? 0);
        if ($marketId <= 0) {
            $query->whereRaw('1 = 0');

            return;
        }

        $recipientIds = $this->recipientIdsForMarket($marketId);
        $usesGeneralRequests = in_array((int) $user->id, $recipientIds['general'], true);
        $usesRepairRequests = in_array((int) $user->id, $recipientIds['repair'], true);
        $usesHelpRequests = in_array((int) $user->id, $recipientIds['help'], true);

        $query
            ->where('market_id', $marketId)
            ->where(function (Builder $visibility) use (
                $user,
                $recipientIds,
                $usesGeneralRequests,
                $usesRepairRequests,
                $usesHelpRequests
            ): void {
                $visibility
                    ->where('assigned_to', (int) $user->id)
                    ->orWhereHas('comments', function (Builder $comments) use ($user): void {
                        $comments->where('user_id', (int) $user->id);
                    });

                if ($usesGeneralRequests) {
                    $visibility->orWhere(function (Builder $tickets) use ($recipientIds): void {
                        $tickets->where(function (Builder $general): void {
                            $general
                                ->whereNotIn('category', ['repair', 'help'])
                                ->orWhereNull('category');
                        });

                        if ($recipientIds['repair'] === []) {
                            $tickets->orWhere('category', 'repair');
                        }

                        if ($recipientIds['help'] === []) {
                            $tickets->orWhere('category', 'help');
                        }
                    });
                }

                if ($usesRepairRequests) {
                    $visibility->orWhere('category', 'repair');
                }

                if ($usesHelpRequests) {
                    $visibility->orWhere('category', 'help');
                }
            });
    }

    /**
     * @return list<int>
     */
    public function recipientIdsForTicket(Ticket $ticket): array
    {
        $recipientIds = $this->recipientIdsForMarket((int) $ticket->market_id);
        $category = (string) ($ticket->category ?? '');

        if ($category === 'repair' && $recipientIds['repair'] !== []) {
            return $recipientIds['repair'];
        }

        if ($category === 'help' && $recipientIds['help'] !== []) {
            return $recipientIds['help'];
        }

        return $recipientIds['general'];
    }

    /**
     * @return array{general:list<int>,repair:list<int>,help:list<int>}
     */
    private function recipientIdsForMarket(int $marketId): array
    {
        $market = Market::query()
            ->select(['id', 'settings'])
            ->find($marketId);

        $settings = (array) ($market?->settings ?? []);

        return [
            'general' => $this->normalizeIds($settings['request_notification_recipient_user_ids'] ?? []),
            'repair' => $this->normalizeIds($settings['request_repair_notification_recipient_user_ids'] ?? []),
            'help' => $this->normalizeIds($settings['request_help_notification_recipient_user_ids'] ?? []),
        ];
    }

    /**
     * @return list<int>
     */
    private function normalizeIds(mixed $ids): array
    {
        return array_values(array_unique(array_map(
            static fn (mixed $id): int => (int) $id,
            array_filter((array) $ids, static fn (mixed $id): bool => is_numeric($id) && (int) $id > 0),
        )));
    }

    private function isSuperAdmin(User $user): bool
    {
        return method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin();
    }
}
