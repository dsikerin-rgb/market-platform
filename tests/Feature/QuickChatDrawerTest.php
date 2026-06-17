<?php
# tests/Feature/QuickChatDrawerTest.php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Admin\QuickChatDrawer;
use App\Models\Market;
use App\Models\StaffConversation;
use App\Models\StaffConversationMessage;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TicketChatNotification;
use App\Support\StaffConversationService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class QuickChatDrawerTest extends TestCase
{
    use RefreshDatabase;

    public function test_regular_requests_page_ticket_query_does_not_auto_open_drawer(): void
    {
        $market = Market::query()->create([
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $this->actingAsMarketAdmin($market);

        $ticket = Ticket::query()->create([
            'market_id' => (int) $market->id,
            'subject' => 'Tenant request',
            'description' => 'Tenant request body',
            'category' => 'other',
            'priority' => 'normal',
            'status' => 'new',
        ]);

        Livewire::withQueryParams([
            'channel' => 'tenants',
            'ticket_id' => (int) $ticket->id,
        ])
            ->test(QuickChatDrawer::class)
            ->assertSet('isOpen', false)
            ->assertSet('selectedType', null)
            ->assertSet('selectedId', null);
    }

    public function test_explicit_quick_chat_ticket_query_auto_opens_drawer(): void
    {
        $market = Market::query()->create([
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $this->actingAsMarketAdmin($market);

        $ticket = Ticket::query()->create([
            'market_id' => (int) $market->id,
            'subject' => 'Tenant request',
            'description' => 'Tenant request body',
            'category' => 'other',
            'priority' => 'normal',
            'status' => 'new',
        ]);

        Livewire::withQueryParams([
            'quick_chat' => 'ticket',
            'channel' => 'tenants',
            'ticket_id' => (int) $ticket->id,
        ])
            ->test(QuickChatDrawer::class)
            ->assertSet('isOpen', true)
            ->assertSet('selectedType', 'ticket')
            ->assertSet('selectedId', (int) $ticket->id);
    }

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

    public function test_staff_message_can_be_sent_with_attachment(): void
    {
        Storage::fake('public');

        $market = Market::query()->create([
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $admin = $this->actingAsMarketAdmin($market);
        $staff = User::factory()->create([
            'name' => 'Attachment Receiver',
            'market_id' => (int) $market->id,
            'tenant_id' => null,
            'email' => 'attachment-receiver-quick-chat@example.test',
        ]);

        $this->createConversation($market, $staff, $admin, 'Files', now());

        Livewire::test(QuickChatDrawer::class)
            ->call('openDrawer')
            ->call('selectChat', 'staff', (int) $staff->id)
            ->set('messageBody', '')
            ->set('messageAttachments', [
                UploadedFile::fake()->create('invoice.pdf', 12, 'application/pdf'),
            ])
            ->call('sendMessage')
            ->assertHasNoErrors()
            ->assertSee('invoice.pdf');

        $message = StaffConversationMessage::query()
            ->where('user_id', (int) $admin->id)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('', (string) $message->body);
        $this->assertIsArray($message->attachments);
        $this->assertSame('invoice.pdf', $message->attachments[0]['name'] ?? null);
    }

    public function test_staff_dialog_can_be_started_from_search(): void
    {
        $market = Market::query()->create([
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $admin = $this->actingAsMarketAdmin($market);
        $staff = User::factory()->create([
            'name' => 'Fresh Receiver',
            'market_id' => (int) $market->id,
            'tenant_id' => null,
            'email' => 'fresh-receiver-quick-chat@example.test',
        ]);

        Livewire::test(QuickChatDrawer::class)
            ->call('openDrawer')
            ->set('search', 'Fresh')
            ->assertSee('Fresh Receiver')
            ->assertSee('Новый диалог')
            ->call('selectChat', 'staff', (int) $staff->id)
            ->assertSee('Напишите первое сообщение, чтобы начать переписку.')
            ->set('messageBody', 'Первое сообщение')
            ->call('sendMessage')
            ->assertHasNoErrors()
            ->assertSee('Первое сообщение');

        $this->assertDatabaseHas('staff_conversations', [
            'market_id' => (int) $market->id,
            'created_by_user_id' => (int) $admin->id,
            'recipient_user_id' => (int) $staff->id,
        ]);

        $this->assertDatabaseHas('staff_conversation_messages', [
            'user_id' => (int) $admin->id,
            'body' => 'Первое сообщение',
        ]);
    }

    public function test_tenant_message_notifications_are_counted_and_marked_read(): void
    {
        $market = Market::query()->create([
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $admin = $this->actingAsMarketAdmin($market);
        $market->forceFill([
            'settings' => [
                'request_notification_recipient_user_ids' => [(int) $admin->id],
            ],
        ])->save();

        $ticket = Ticket::query()->create([
            'market_id' => (int) $market->id,
            'subject' => 'Tenant unread request',
            'description' => 'Tenant request body',
            'category' => 'other',
            'priority' => 'normal',
            'status' => 'new',
        ]);

        $notificationId = (string) Str::uuid();

        DB::table('notifications')->insert([
            'id' => $notificationId,
            'type' => TicketChatNotification::class,
            'notifiable_type' => User::class,
            'notifiable_id' => (int) $admin->id,
            'data' => json_encode([
                'ticket_id' => (int) $ticket->id,
                'market_id' => (int) $market->id,
                'event_type' => TicketChatNotification::EVENT_MESSAGE_CREATED,
                'title' => 'New chat message',
                'message' => 'Tenant wrote a message',
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'read_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Livewire::test(QuickChatDrawer::class)
            ->assertSeeHtml('<span class="quick-chat__badge">1</span>')
            ->call('openDrawer')
            ->assertSee('Tenant unread request')
            ->assertSeeHtml('<span class="quick-chat__count">1</span>')
            ->call('selectChat', 'ticket', (int) $ticket->id)
            ->assertHasNoErrors();

        $this->assertNotNull(DB::table('notifications')->where('id', $notificationId)->value('read_at'));
    }

    public function test_staff_conversation_service_reuses_existing_thread(): void
    {
        $market = Market::query()->create([
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $admin = $this->actingAsMarketAdmin($market);
        $staff = User::factory()->create([
            'name' => 'Existing Thread Peer',
            'market_id' => (int) $market->id,
            'tenant_id' => null,
            'email' => 'existing-thread-peer@example.test',
        ]);

        $conversation = $this->createConversation($market, $staff, $admin, 'Existing', now()->subHour());
        StaffConversationMessage::query()->create([
            'staff_conversation_id' => (int) $conversation->id,
            'user_id' => (int) $staff->id,
            'body' => 'Old message',
            'read_at' => null,
        ]);

        $reused = app(StaffConversationService::class)->startConversation(
            $admin,
            $staff,
            'New subject should not split',
            'New message in same thread',
        );

        $this->assertSame((int) $conversation->id, (int) $reused->id);
        $this->assertSame(1, StaffConversation::query()->count());
        $this->assertDatabaseHas('staff_conversation_messages', [
            'staff_conversation_id' => (int) $conversation->id,
            'user_id' => (int) $admin->id,
            'body' => 'New message in same thread',
        ]);
    }

    public function test_staff_search_can_mix_existing_chats_and_new_candidates(): void
    {
        $market = Market::query()->create([
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $admin = $this->actingAsMarketAdmin($market);
        $existingStaff = User::factory()->create([
            'name' => 'Existing Search Peer',
            'market_id' => (int) $market->id,
            'tenant_id' => null,
            'email' => 'existing-search-peer@example.test',
        ]);
        $newStaff = User::factory()->create([
            'name' => 'Fresh Search Peer',
            'market_id' => (int) $market->id,
            'tenant_id' => null,
            'email' => 'fresh-search-peer@example.test',
        ]);

        $this->createConversation($market, $admin, $existingStaff, 'Search topic', now());

        Livewire::test(QuickChatDrawer::class)
            ->call('openDrawer')
            ->set('search', 'Search Peer')
            ->assertSee('Existing Search Peer')
            ->assertSee('Fresh Search Peer')
            ->assertSee('Новый диалог')
            ->call('selectChat', 'staff', (int) $newStaff->id)
            ->assertSee('Напишите первое сообщение, чтобы начать переписку.');
    }

    public function test_super_admin_can_find_staff_dialog_candidate_without_selected_market(): void
    {
        $market = Market::query()->create([
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $this->actingAsSuperAdmin();
        Role::findOrCreate('market-admin', 'web');

        $staffCandidate = User::factory()->create([
            'name' => 'Searchable Staff Candidate',
            'market_id' => (int) $market->id,
            'tenant_id' => null,
            'email' => 'searchable-staff-candidate@example.test',
        ]);
        $staffCandidate->assignRole('market-admin');

        Livewire::test(QuickChatDrawer::class)
            ->call('openDrawer')
            ->set('search', 'Searchable')
            ->assertSee('Searchable Staff Candidate');
    }

    public function test_staff_can_start_staff_dialog_with_super_admin_without_market_id(): void
    {
        $market = Market::query()->create([
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $admin = $this->actingAsMarketAdmin($market);

        Role::findOrCreate('super-admin', 'web');

        $superAdmin = User::factory()->create([
            'name' => 'Internal Super Admin',
            'market_id' => null,
            'tenant_id' => null,
            'email' => 'internal-super-admin-qa@example.test',
        ]);
        $superAdmin->assignRole('super-admin');

        Livewire::test(QuickChatDrawer::class)
            ->call('openDrawer', 'staff', (int) $superAdmin->id)
            ->assertSet('isOpen', true)
            ->assertSet('selectedType', 'staff')
            ->assertSet('selectedId', (int) $superAdmin->id)
            ->assertSeeHtml('class="quick-chat__composer"')
            ->set('messageBody', 'Hello from staff')
            ->call('sendMessage')
            ->assertHasNoErrors()
            ->assertSee('Hello from staff');

        $this->assertDatabaseHas('staff_conversations', [
            'market_id' => (int) $market->id,
            'created_by_user_id' => (int) $admin->id,
            'recipient_user_id' => (int) $superAdmin->id,
        ]);

        $this->assertDatabaseHas('staff_conversation_messages', [
            'user_id' => (int) $admin->id,
            'body' => 'Hello from staff',
        ]);
    }

    public function test_staff_cannot_open_dialog_with_merchant_role(): void
    {
        $market = Market::query()->create([
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $this->actingAsMarketAdmin($market);

        Role::findOrCreate('merchant', 'web');

        $merchant = User::factory()->create([
            'name' => 'Blocked Merchant',
            'market_id' => (int) $market->id,
            'tenant_id' => null,
            'email' => 'blocked-merchant-qa@example.test',
        ]);
        $merchant->assignRole('merchant');

        Livewire::test(QuickChatDrawer::class)
            ->call('openDrawer', 'staff', (int) $merchant->id)
            ->assertSet('selectedType', null)
            ->assertSet('selectedId', null);
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

    private function actingAsSuperAdmin(): User
    {
        Role::findOrCreate('super-admin', 'web');

        $user = User::factory()->create([
            'market_id' => null,
            'tenant_id' => null,
            'email' => 'quick-chat-super-admin-' . uniqid('', true) . '@example.test',
        ]);
        $user->assignRole('super-admin');

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
