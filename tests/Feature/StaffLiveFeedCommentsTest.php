<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Admin\StaffLiveFeed;
use App\Models\Market;
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
