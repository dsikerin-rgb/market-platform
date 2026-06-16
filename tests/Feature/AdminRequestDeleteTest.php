<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Market;
use App\Models\Tenant;
use App\Models\TenantRequest;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketComment;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminRequestDeleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_market_admin_can_delete_own_market_tenant_request_ticket(): void
    {
        Storage::fake('public');
        config(['filament.default_filesystem_disk' => 'public']);

        $market = Market::create([
            'name' => 'Delete Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $tenant = Tenant::create([
            'market_id' => (int) $market->id,
            'name' => 'Delete Test Tenant',
            'is_active' => true,
        ]);

        $admin = $this->actingAsMarketAdmin($market);

        $ticket = Ticket::create([
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $tenant->id,
            'subject' => 'Test request',
            'description' => 'Temporary test request',
            'category' => 'other',
            'priority' => 'normal',
            'status' => 'new',
        ]);

        TicketComment::create([
            'ticket_id' => (int) $ticket->id,
            'user_id' => (int) $admin->id,
            'body' => 'Test comment',
        ]);

        Storage::disk('public')->put('ticket-attachments/test-request.txt', 'test');

        TicketAttachment::create([
            'ticket_id' => (int) $ticket->id,
            'file_path' => 'ticket-attachments/test-request.txt',
            'original_name' => 'test-request.txt',
        ]);

        TenantRequest::create([
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $tenant->id,
            'ticket_id' => (int) $ticket->id,
            'subject' => 'Test request',
            'description' => 'Temporary test request',
            'category' => 'other',
            'priority' => 'normal',
            'status' => 'new',
            'created_by_user_id' => (int) $admin->id,
            'is_active' => true,
        ]);

        $response = $this
            ->actingAs($admin, Filament::getAuthGuard())
            ->post(route('filament.admin.requests.delete', ['ticket' => (int) $ticket->id]), [
                'tenant_id' => (int) $tenant->id,
                'status_redirect' => 'all',
            ]);

        $response->assertRedirect('/admin/requests?tenant_id=' . (int) $tenant->id);

        $this->assertDatabaseMissing('tickets', ['id' => (int) $ticket->id]);
        $this->assertDatabaseMissing('ticket_comments', ['ticket_id' => (int) $ticket->id]);
        $this->assertDatabaseMissing('ticket_attachments', ['ticket_id' => (int) $ticket->id]);
        $this->assertDatabaseMissing('tenant_requests', ['ticket_id' => (int) $ticket->id]);
        Storage::disk('public')->assertMissing('ticket-attachments/test-request.txt');
    }

    public function test_market_admin_cannot_delete_other_market_tenant_request_ticket(): void
    {
        $market = Market::create([
            'name' => 'Primary Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $otherMarket = Market::create([
            'name' => 'Other Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $tenant = Tenant::create([
            'market_id' => (int) $otherMarket->id,
            'name' => 'Other Tenant',
            'is_active' => true,
        ]);

        $admin = $this->actingAsMarketAdmin($market);

        $ticket = Ticket::create([
            'market_id' => (int) $otherMarket->id,
            'tenant_id' => (int) $tenant->id,
            'subject' => 'Other request',
            'description' => 'Request from another market',
            'category' => 'other',
            'priority' => 'normal',
            'status' => 'new',
        ]);

        $response = $this
            ->actingAs($admin, Filament::getAuthGuard())
            ->post(route('filament.admin.requests.delete', ['ticket' => (int) $ticket->id]));

        $response->assertForbidden();

        $this->assertDatabaseHas('tickets', ['id' => (int) $ticket->id]);
    }

    private function actingAsMarketAdmin(Market $market): User
    {
        Role::findOrCreate('market-admin', 'web');

        $user = User::factory()->create([
            'market_id' => (int) $market->id,
            'tenant_id' => null,
            'email' => 'request-delete-admin-' . (int) $market->id . '@example.test',
        ]);

        $user->assignRole('market-admin');

        $this->actingAs($user, Filament::getAuthGuard());

        return $user;
    }
}
