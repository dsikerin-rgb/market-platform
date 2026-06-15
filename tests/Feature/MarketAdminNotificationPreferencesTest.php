<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Widgets\MarketAttentionWidget;
use App\Models\Market;
use App\Models\StaffConversation;
use App\Models\StaffConversationMessage;
use App\Models\User;
use App\Support\UserNotificationPreferences;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MarketAdminNotificationPreferencesTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_limits_market_admin_topics_to_messages_and_resets_unread_counter(): void
    {
        $market = Market::query()->create([
            'name' => 'Test market',
            'timezone' => 'Asia/Novosibirsk',
            'is_active' => true,
        ]);

        Role::findOrCreate('market-admin', 'web');

        $admin = User::factory()->create([
            'market_id' => (int) $market->id,
            'notification_preferences' => [
                'self_manage' => true,
                'channels' => ['database'],
                'topics' => ['requests', 'tasks', 'messages'],
            ],
        ]);
        $admin->assignRole('market-admin');

        DB::table('notifications')->insert([
            'id' => (string) Str::uuid(),
            'type' => 'App\\Notifications\\TaskAssignedNotification',
            'notifiable_type' => User::class,
            'notifiable_id' => (int) $admin->id,
            'data' => json_encode(['title' => 'Task'], JSON_THROW_ON_ERROR),
            'read_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('notifications:limit-market-admins-to-messages')
            ->assertExitCode(0);

        $admin->refresh();

        $this->assertSame([UserNotificationPreferences::TOPIC_MESSAGES], $admin->notification_preferences['topics'] ?? []);
        $this->assertSame(0, $admin->unreadNotifications()->count());
    }

    public function test_attention_widget_shows_blue_toast_for_unread_staff_messages(): void
    {
        $market = Market::query()->create([
            'name' => 'Test market',
            'timezone' => 'Asia/Novosibirsk',
            'is_active' => true,
        ]);

        Role::findOrCreate('market-admin', 'web');

        $admin = User::factory()->create([
            'market_id' => (int) $market->id,
        ]);
        $admin->assignRole('market-admin');

        $sender = User::factory()->create([
            'market_id' => (int) $market->id,
        ]);

        $conversation = StaffConversation::query()->create([
            'market_id' => (int) $market->id,
            'created_by_user_id' => (int) $sender->id,
            'recipient_user_id' => (int) $admin->id,
            'subject' => 'Question',
            'last_message_at' => now(),
        ]);

        StaffConversationMessage::query()->create([
            'staff_conversation_id' => (int) $conversation->id,
            'user_id' => (int) $sender->id,
            'body' => 'Please check',
            'read_at' => null,
        ]);

        $this->actingAs($admin);

        Livewire::test(MarketAttentionWidget::class)
            ->assertSee('data-tone="info"', false)
            ->assertSee('channel=staff', false);
    }
}
