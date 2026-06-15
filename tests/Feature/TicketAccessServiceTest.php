<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Market;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\User;
use App\Support\TicketAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TicketAccessServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_repair_ticket_visibility_uses_category_recipients_before_general_recipients(): void
    {
        $market = Market::query()->create([
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $generalRecipient = $this->createMarketUser($market, 'general-recipient@example.test');
        $repairRecipient = $this->createMarketUser($market, 'repair-recipient@example.test');
        $otherUser = $this->createMarketUser($market, 'other-user@example.test');
        $participant = $this->createMarketUser($market, 'participant@example.test');

        $market->update([
            'settings' => [
                'request_notification_recipient_user_ids' => [(string) $generalRecipient->id],
                'request_repair_notification_recipient_user_ids' => [(string) $repairRecipient->id],
                'request_help_notification_recipient_user_ids' => [],
            ],
        ]);

        $ticket = Ticket::query()->create([
            'market_id' => (int) $market->id,
            'tenant_id' => null,
            'subject' => 'Repair request',
            'description' => 'Repair request body',
            'category' => 'repair',
            'priority' => 'normal',
            'status' => 'new',
            'assigned_to' => null,
        ]);

        TicketComment::query()->create([
            'ticket_id' => (int) $ticket->id,
            'user_id' => (int) $participant->id,
            'body' => 'I am already involved',
        ]);

        $access = app(TicketAccessService::class);

        $this->assertFalse($access->canView($generalRecipient, $ticket));
        $this->assertTrue($access->canView($repairRecipient, $ticket));
        $this->assertFalse($access->canView($otherUser, $ticket));
        $this->assertTrue($access->canView($participant, $ticket));

        $generalRecipientQuery = Ticket::query();
        $access->scopeVisibleTo($generalRecipientQuery, $generalRecipient);
        $visibleIdsForGeneralRecipient = $generalRecipientQuery->pluck('id')->all();

        $repairRecipientQuery = Ticket::query();
        $access->scopeVisibleTo($repairRecipientQuery, $repairRecipient);
        $visibleIdsForRepairRecipient = $repairRecipientQuery->pluck('id')->all();

        $this->assertNotContains((int) $ticket->id, $visibleIdsForGeneralRecipient);
        $this->assertContains((int) $ticket->id, $visibleIdsForRepairRecipient);
    }

    private function createMarketUser(Market $market, string $email): User
    {
        return User::factory()->create([
            'market_id' => (int) $market->id,
            'tenant_id' => null,
            'email' => $email,
        ]);
    }
}
