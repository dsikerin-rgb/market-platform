<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Market;
use App\Models\StaffInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StaffInvitationAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_invited_staff_user_can_accept_invitation(): void
    {
        $market = $this->createMarket();
        Role::findOrCreate('staff', 'web');

        $token = 'test-invitation-token';
        $invitation = StaffInvitation::query()->create([
            'market_id' => (int) $market->id,
            'email' => 'new-staff@example.test',
            'roles' => ['staff'],
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDay(),
            'invited_by' => null,
        ]);

        $response = $this->post(route('staff-invitations.accept.submit', [
            'invitation' => $invitation,
            'token' => $token,
        ]), [
            'name' => 'New Staff',
            'password' => 'strong-password',
            'password_confirmation' => 'strong-password',
        ]);

        $response->assertRedirect('/admin');

        $user = User::query()->where('email', 'new-staff@example.test')->firstOrFail();

        $this->assertSame('New Staff', $user->name);
        $this->assertSame((int) $market->id, (int) $user->market_id);
        $this->assertTrue($user->hasRole('staff'));
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($invitation->fresh()?->accepted_at);
    }

    public function test_invitation_rejects_invalid_token(): void
    {
        $market = $this->createMarket();
        Role::findOrCreate('staff', 'web');

        $invitation = StaffInvitation::query()->create([
            'market_id' => (int) $market->id,
            'email' => 'invalid-token@example.test',
            'roles' => ['staff'],
            'token_hash' => hash('sha256', 'real-token'),
            'expires_at' => now()->addDay(),
            'invited_by' => null,
        ]);

        $response = $this->post(route('staff-invitations.accept.submit', [
            'invitation' => $invitation,
            'token' => 'wrong-token',
        ]), [
            'name' => 'Invalid Token',
            'password' => 'strong-password',
            'password_confirmation' => 'strong-password',
        ]);

        $response->assertOk();
        $response->assertSee('Ссылка приглашения недействительна.');

        $this->assertDatabaseMissing('users', [
            'email' => 'invalid-token@example.test',
        ]);
    }

    public function test_existing_user_can_accept_invitation_without_password_reset(): void
    {
        $market = $this->createMarket();
        Role::findOrCreate('staff', 'web');
        Role::findOrCreate('market-operator', 'web');

        $user = User::factory()->create([
            'email' => 'existing-staff@example.test',
            'market_id' => (int) $market->id,
        ]);
        $user->assignRole('staff');

        $token = 'existing-user-token';
        $invitation = StaffInvitation::query()->create([
            'market_id' => (int) $market->id,
            'email' => 'existing-staff@example.test',
            'roles' => ['market-operator'],
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDay(),
            'invited_by' => null,
        ]);

        $response = $this->post(route('staff-invitations.accept.submit', [
            'invitation' => $invitation,
            'token' => $token,
        ]));

        $response->assertRedirect('/admin');

        $user->refresh();
        $this->assertTrue($user->hasRole('market-operator'));
        $this->assertFalse($user->hasRole('staff'));
        $this->assertAuthenticatedAs($user);
    }

    private function createMarket(): Market
    {
        return Market::query()->create([
            'name' => 'Test market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);
    }
}
