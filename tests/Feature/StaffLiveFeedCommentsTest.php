<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Admin\StaffLiveFeed;
use App\Models\Market;
use App\Models\StaffConversation;
use App\Models\StaffConversationMessage;
use App\Models\StaffFeedComment;
use App\Models\StaffFeedPost;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StaffLiveFeedCommentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_market_user_can_comment_visible_staff_feed_post(): void
    {
        $market = $this->createMarket('Test Market');
        $user = $this->actingAsMarketAdmin($market);
        $post = $this->createPost($market, $user, 'Рабочее сообщение');

        Livewire::test(StaffLiveFeed::class)
            ->set('commentBodies.' . $post->id, 'Принято в работу')
            ->call('createComment', $post->id)
            ->assertHasNoErrors();

        $this->assertDatabaseHas('staff_feed_comments', [
            'staff_feed_post_id' => (int) $post->id,
            'author_user_id' => (int) $user->id,
            'body' => 'Принято в работу',
        ]);
    }

    public function test_market_user_cannot_comment_other_market_post(): void
    {
        $market = $this->createMarket('First Market');
        $otherMarket = $this->createMarket('Other Market');
        $user = $this->actingAsMarketAdmin($market);
        $otherUser = User::factory()->create([
            'market_id' => (int) $otherMarket->id,
            'email' => 'other-staff-feed@example.test',
        ]);
        $post = $this->createPost($otherMarket, $otherUser, 'Чужое сообщение');

        Livewire::test(StaffLiveFeed::class)
            ->set('commentBodies.' . $post->id, 'Не должно сохраниться')
            ->call('createComment', $post->id)
            ->assertHasNoErrors();

        $this->assertSame(0, StaffFeedComment::query()->count());
    }

    public function test_staff_user_without_market_cannot_comment_market_post(): void
    {
        $market = $this->createMarket('Market');
        $author = User::factory()->create([
            'market_id' => (int) $market->id,
            'email' => 'market-author@example.test',
        ]);
        $post = $this->createPost($market, $author, 'Сообщение рынка');

        $user = $this->actingAsStaffWithoutMarket();

        Livewire::test(StaffLiveFeed::class)
            ->set('commentBodies.' . $post->id, 'Не должно сохраниться')
            ->call('createComment', $post->id)
            ->assertHasNoErrors();

        $this->assertSame(0, StaffFeedComment::query()->count());
        $this->assertSame(0, (int) ($user->market_id ?: 0));
    }

    public function test_super_admin_can_comment_any_market_post(): void
    {
        $market = $this->createMarket('Market');
        $author = User::factory()->create([
            'market_id' => (int) $market->id,
            'email' => 'market-author-super-visible@example.test',
        ]);
        $post = $this->createPost($market, $author, 'Сообщение рынка');
        $superAdmin = $this->actingAsSuperAdmin();

        Livewire::test(StaffLiveFeed::class)
            ->set('commentBodies.' . $post->id, 'Комментарий супер-админа')
            ->call('createComment', $post->id)
            ->assertHasNoErrors();

        $this->assertDatabaseHas('staff_feed_comments', [
            'staff_feed_post_id' => (int) $post->id,
            'author_user_id' => (int) $superAdmin->id,
            'body' => 'Комментарий супер-админа',
        ]);
    }

    public function test_live_feed_shows_only_current_users_unread_staff_message_summary(): void
    {
        $market = $this->createMarket('Test Market');
        $otherMarket = $this->createMarket('Other Market');
        $admin = $this->actingAsMarketAdmin($market);

        $sender = User::factory()->create([
            'name' => 'Message Sender',
            'market_id' => (int) $market->id,
            'tenant_id' => null,
            'email' => 'message-sender-feed@example.test',
        ]);
        $hiddenSender = User::factory()->create([
            'name' => 'Hidden Sender',
            'market_id' => (int) $otherMarket->id,
            'tenant_id' => null,
            'email' => 'hidden-sender-feed@example.test',
        ]);
        $hiddenRecipient = User::factory()->create([
            'name' => 'Hidden Recipient',
            'market_id' => (int) $otherMarket->id,
            'tenant_id' => null,
            'email' => 'hidden-recipient-feed@example.test',
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

        $hiddenConversation = StaffConversation::query()->create([
            'market_id' => (int) $otherMarket->id,
            'created_by_user_id' => (int) $hiddenSender->id,
            'recipient_user_id' => (int) $hiddenRecipient->id,
            'subject' => 'Hidden topic',
            'last_message_at' => now(),
        ]);
        StaffConversationMessage::query()->create([
            'staff_conversation_id' => (int) $hiddenConversation->id,
            'user_id' => (int) $hiddenSender->id,
            'body' => 'Hidden internal message',
            'read_at' => null,
        ]);

        Livewire::test(StaffLiveFeed::class)
            ->assertSeeHtml('staff-live-feed__unread-alert')
            ->assertSee('Message Sender')
            ->assertDontSee('Hidden Sender');
    }

    public function test_super_admin_unread_staff_message_summary_uses_selected_market(): void
    {
        $marketA = $this->createMarket('Selected Market');
        $marketB = $this->createMarket('Other Market');
        $superAdmin = $this->actingAsSuperAdmin();
        session(['filament.admin.selected_market_id' => (int) $marketA->id]);

        $selectedSender = User::factory()->create([
            'name' => 'Selected Market Sender',
            'market_id' => (int) $marketA->id,
            'tenant_id' => null,
            'email' => 'selected-market-feed-sender@example.test',
        ]);
        $otherSender = User::factory()->create([
            'name' => 'Other Market Sender',
            'market_id' => (int) $marketB->id,
            'tenant_id' => null,
            'email' => 'other-market-feed-sender@example.test',
        ]);

        $selectedConversation = StaffConversation::query()->create([
            'market_id' => (int) $marketA->id,
            'created_by_user_id' => (int) $selectedSender->id,
            'recipient_user_id' => (int) $superAdmin->id,
            'subject' => 'Selected market topic',
            'last_message_at' => now(),
        ]);
        StaffConversationMessage::query()->create([
            'staff_conversation_id' => (int) $selectedConversation->id,
            'user_id' => (int) $selectedSender->id,
            'body' => 'Selected market unread message',
            'read_at' => null,
        ]);

        $otherConversation = StaffConversation::query()->create([
            'market_id' => (int) $marketB->id,
            'created_by_user_id' => (int) $otherSender->id,
            'recipient_user_id' => (int) $superAdmin->id,
            'subject' => 'Other market topic',
            'last_message_at' => now(),
        ]);
        StaffConversationMessage::query()->create([
            'staff_conversation_id' => (int) $otherConversation->id,
            'user_id' => (int) $otherSender->id,
            'body' => 'Other market unread message',
            'read_at' => null,
        ]);

        Livewire::test(StaffLiveFeed::class)
            ->assertSeeHtml('staff-live-feed__unread-alert')
            ->assertSee('Selected Market Sender')
            ->assertDontSee('Other Market Sender');
    }

    private function createMarket(string $name): Market
    {
        return Market::query()->create([
            'name' => $name,
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);
    }

    private function actingAsMarketAdmin(Market $market): User
    {
        Role::findOrCreate('market-admin', 'web');

        $user = User::factory()->create([
            'market_id' => (int) $market->id,
            'tenant_id' => null,
            'email' => 'staff-feed-admin-' . uniqid('', true) . '@example.test',
        ]);
        $user->assignRole('market-admin');

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($user, Filament::getAuthGuard());

        return $user;
    }

    private function actingAsStaffWithoutMarket(): User
    {
        Role::findOrCreate('staff', 'web');

        $user = User::factory()->create([
            'market_id' => null,
            'tenant_id' => null,
            'email' => 'staff-without-market-' . uniqid('', true) . '@example.test',
        ]);
        $user->assignRole('staff');

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
            'email' => 'staff-feed-super-admin-' . uniqid('', true) . '@example.test',
        ]);
        $user->assignRole('super-admin');

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($user, Filament::getAuthGuard());

        return $user;
    }

    private function createPost(Market $market, User $author, string $body): StaffFeedPost
    {
        return StaffFeedPost::query()->create([
            'market_id' => (int) $market->id,
            'author_user_id' => (int) $author->id,
            'type' => 'message',
            'body' => $body,
            'meta' => [],
        ]);
    }
}
