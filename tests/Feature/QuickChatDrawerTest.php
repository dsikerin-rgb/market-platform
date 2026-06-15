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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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

    public function test_super_admin_can_find_staff_dialog_candidate_without_selected_market(): void
    {
        $market = Market::query()->create([
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $this->actingAsSuperAdmin();

        User::factory()->create([
            'name' => 'Фриц Юрий Александрович',
            'market_id' => (int) $market->id,
            'tenant_id' => null,
            'email' => 'friz2009@example.test',
        ]);

        Livewire::test(QuickChatDrawer::class)
            ->call('openDrawer')
            ->set('search', 'Фри')
            ->assertSee('Фриц Юрий Александрович')
            ->assertSee('Новый диалог');
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
