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

    public function test_staff_presence_avatar_opens_quick_chat_drawer(): void
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
            ->assertSee('Message Sender')
            ->assertSeeHtml("type: 'staff', id: " . (int) $sender->id);
    }

    public function test_staff_presence_rail_has_separate_ai_consultant_launcher(): void
    {
        $market = Market::query()->create([
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $this->actingAsMarketAdmin($market);

        Livewire::test(OnlineStaffRail::class)
            ->assertSeeHtml('staff-presence__stack--ai')
            ->assertSeeHtml("type: 'ai', id: 1")
            ->assertSeeHtml("aiNudgeStorageKeyPrefix: 'market.aiAgentNudge.dismissedAt.'")
            ->assertSeeHtml('aiNudgeStorageKey()')
            ->assertSee('ИИ-консультант');
    }

    public function test_presence_rail_poll_refreshes_current_user_presence(): void
    {
        $market = Market::query()->create([
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $admin = $this->actingAsMarketAdmin($market);
        $admin->forceFill(['last_seen_at' => now()->subMinutes(10)])->save();

        Livewire::test(OnlineStaffRail::class)
            ->assertOk();

        $this->assertTrue($admin->refresh()->last_seen_at->greaterThan(now()->subMinute()));
    }

    public function test_market_staff_can_see_online_super_admin_without_market_id(): void
    {
        $market = Market::query()->create([
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $this->actingAsMarketAdmin($market);

        Role::findOrCreate('super-admin', 'web');

        $superAdmin = User::factory()->create([
            'name' => 'Visible Super Admin',
            'market_id' => null,
            'tenant_id' => null,
            'email' => 'visible-super-admin@example.test',
            'last_seen_at' => now(),
        ]);
        $superAdmin->assignRole('super-admin');

        Livewire::test(OnlineStaffRail::class)
            ->assertSee('Visible Super Admin');
    }

    public function test_super_admin_without_selected_market_does_not_see_staff_from_all_markets(): void
    {
        $marketA = Market::query()->create([
            'name' => 'Market A',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);
        $marketB = Market::query()->create([
            'name' => 'Market B',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $this->actingAsSuperAdmin();

        User::factory()->create([
            'name' => 'Market A Staff',
            'market_id' => (int) $marketA->id,
            'tenant_id' => null,
            'email' => 'market-a-staff@example.test',
            'last_seen_at' => now(),
        ]);

        User::factory()->create([
            'name' => 'Market B Staff',
            'market_id' => (int) $marketB->id,
            'tenant_id' => null,
            'email' => 'market-b-staff@example.test',
            'last_seen_at' => now(),
        ]);

        Livewire::test(OnlineStaffRail::class)
            ->assertDontSee('Market A Staff')
            ->assertDontSee('Market B Staff');
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

    private function actingAsSuperAdmin(): User
    {
        Role::findOrCreate('super-admin', 'web');

        $user = User::factory()->create([
            'name' => 'Scoped Super Admin',
            'market_id' => null,
            'tenant_id' => null,
            'email' => 'presence-super-admin-' . uniqid('', true) . '@example.test',
        ]);
        $user->assignRole('super-admin');

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($user, Filament::getAuthGuard());

        return $user;
    }
}
