<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Admin\OnlineStaffRail;
use App\Models\Market;
use App\Models\StaffConversation;
use App\Models\StaffConversationMessage;
use App\Models\User;
use App\Support\SystemAgentService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StaffPresenceRailTest extends TestCase
{
    use RefreshDatabase;

    public function test_system_agent_is_hidden_from_staff_presence_rail(): void
    {
        $market = Market::query()->create([
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $this->actingAsMarketAdmin($market);

        User::factory()->create([
            'name' => 'System Agent',
            'market_id' => (int) $market->id,
            'tenant_id' => null,
            'email' => 'system+market' . $market->id . '@' . SystemAgentService::EMAIL_DOMAIN,
            'last_seen_at' => now(),
        ]);

        User::factory()->create([
            'name' => 'Visible Staff',
            'market_id' => (int) $market->id,
            'tenant_id' => null,
            'email' => 'visible-staff@example.test',
            'last_seen_at' => now(),
        ]);

        Livewire::test(OnlineStaffRail::class)
            ->assertSee('Visible Staff')
            ->assertDontSee('System Agent');
    }

    public function test_unread_staff_messages_are_visible_on_presence_rail(): void
    {
        $market = Market::query()->create([
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $admin = $this->actingAsMarketAdmin($market);
        $sender = User::factory()->create([
            'name' => 'Message Sender',
            'market_id' => (int) $market->id,
            'tenant_id' => null,
            'email' => 'message-sender@example.test',
            'last_seen_at' => now(),
        ]);

        $conversation = StaffConversation::query()->create([
            'market_id' => (int) $market->id,
            'created_by_user_id' => (int) $sender->id,
            'recipient_user_id' => (int) $admin->id,
            'subject' => 'Unread topic',
            'last_message_at' => now(),
        ]);

        StaffConversationMessage::query()->create([
            'staff_conversation_id' => (int) $conversation->id,
            'user_id' => (int) $sender->id,
            'body' => 'Unread internal message',
            'read_at' => null,
        ]);

        Livewire::test(OnlineStaffRail::class)
            ->assertSeeHtml('staff-presence__unread-badge')
            ->call('openStaffModal', (int) $sender->id)
            ->assertSee('Unread internal message')
            ->call('markSelectedStaffMessagesRead')
            ->assertDontSee('Unread internal message');
    }

    private function actingAsMarketAdmin(Market $market): User
    {
        Role::findOrCreate('market-admin', 'web');

        $user = User::factory()->create([
            'market_id' => (int) $market->id,
            'tenant_id' => null,
            'email' => 'presence-admin-' . uniqid('', true) . '@example.test',
        ]);
        $user->assignRole('market-admin');

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($user, Filament::getAuthGuard());

        return $user;
    }
}
