<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Admin\QuickChatDrawer;
use App\Models\Market;
use App\Models\StaffConversation;
use App\Models\StaffConversationMessage;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class QuickChatDrawerTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_conversations_are_grouped_by_counterparty(): void
    {
        $market = Market::query()->create([
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $admin = $this->actingAsMarketAdmin($market);
        $staff = User::factory()->create([
            'name' => 'Message Sender',
            'market_id' => (int) $market->id,
            'tenant_id' => null,
            'email' => 'message-sender-quick-chat@example.test',
        ]);

        $firstConversation = $this->createConversation($market, $staff, $admin, 'First topic', now()->subHour());
        StaffConversationMessage::query()->create([
            'staff_conversation_id' => (int) $firstConversation->id,
            'user_id' => (int) $staff->id,
            'body' => 'First body',
            'read_at' => null,
        ]);

        $secondConversation = $this->createConversation($market, $admin, $staff, 'Second topic', now());
        StaffConversationMessage::query()->create([
            'staff_conversation_id' => (int) $secondConversation->id,
            'user_id' => (int) $admin->id,
            'body' => 'Second body',
            'read_at' => now(),
        ]);

        Livewire::test(QuickChatDrawer::class)
            ->call('openDrawer')
            ->assertSee('Message Sender')
            ->assertDontSee('First topic')
            ->assertDontSee('Second topic')
            ->call('selectChat', 'staff', (int) $staff->id)
            ->assertSee('First body')
            ->assertSee('Second body');
    }

    private function actingAsMarketAdmin(Market $market): User
    {
        Role::findOrCreate('market-admin', 'web');

        $user = User::factory()->create([
            'market_id' => (int) $market->id,
            'tenant_id' => null,
            'email' => 'quick-chat-admin-' . uniqid('', true) . '@example.test',
        ]);
        $user->assignRole('market-admin');

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($user, Filament::getAuthGuard());

        return $user;
    }

    private function createConversation(
        Market $market,
        User $starter,
        User $recipient,
        string $subject,
        mixed $lastMessageAt,
    ): StaffConversation {
        return StaffConversation::query()->create([
            'market_id' => (int) $market->id,
            'created_by_user_id' => (int) $starter->id,
            'recipient_user_id' => (int) $recipient->id,
            'subject' => $subject,
            'last_message_at' => $lastMessageAt,
        ]);
    }
}
