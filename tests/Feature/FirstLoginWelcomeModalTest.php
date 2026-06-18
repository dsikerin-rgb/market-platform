<?php
# tests/Feature/FirstLoginWelcomeModalTest.php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Admin\FirstLoginWelcomeModal;
use App\Models\User;
use App\Support\FirstLoginWelcomeNotice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FirstLoginWelcomeModalTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_without_saved_notice_sees_modal(): void
    {
        $user = User::factory()->create([
            'notification_preferences' => [],
        ]);

        $this->actingAs($user);
        session()->forget(FirstLoginWelcomeNotice::SESSION_KEY);

        Livewire::test(FirstLoginWelcomeModal::class)
            ->assertSet('shouldShow', true);
    }

    public function test_clicking_button_saves_notice_version(): void
    {
        $user = User::factory()->create([
            'notification_preferences' => [
                'self_manage' => true,
            ],
        ]);

        $this->actingAs($user);

        Livewire::test(FirstLoginWelcomeModal::class)
            ->call('acknowledge')
            ->assertSet('shouldShow', false);

        $user->refresh();
        $preferences = (array) ($user->notification_preferences ?? []);
        $notice = (array) ($preferences[FirstLoginWelcomeNotice::PREFERENCE_KEY] ?? []);

        self::assertSame(FirstLoginWelcomeNotice::VERSION, $notice['version'] ?? null);
        self::assertTrue((bool) ($preferences['self_manage'] ?? false));
    }

    public function test_user_with_saved_current_version_does_not_see_modal(): void
    {
        $user = User::factory()->create([
            'notification_preferences' => [
                FirstLoginWelcomeNotice::PREFERENCE_KEY => [
                    'version' => FirstLoginWelcomeNotice::VERSION,
                ],
            ],
        ]);

        $this->actingAs($user);
        session()->forget(FirstLoginWelcomeNotice::SESSION_KEY);

        Livewire::test(FirstLoginWelcomeModal::class)
            ->assertSet('shouldShow', false);
    }

    public function test_user_with_saved_previous_version_sees_modal_again(): void
    {
        $user = User::factory()->create([
            'notification_preferences' => [
                FirstLoginWelcomeNotice::PREFERENCE_KEY => [
                    'version' => 'previous-version',
                ],
            ],
        ]);

        $this->actingAs($user);
        session()->forget(FirstLoginWelcomeNotice::SESSION_KEY);

        Livewire::test(FirstLoginWelcomeModal::class)
            ->assertSet('shouldShow', true);
    }
}
