<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Widgets\MarketAttentionWidget;
use App\Models\IntegrationExchange;
use App\Models\Market;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MarketAttentionWidgetLinksTest extends TestCase
{
    use RefreshDatabase;

    public function test_recent_integration_errors_signal_links_to_recent_error_slice(): void
    {
        $market = Market::query()->create([
            'name' => 'Эко Ярмарка',
            'timezone' => 'Asia/Novosibirsk',
            'is_active' => true,
        ]);

        Role::findOrCreate('super-admin', 'web');

        $user = User::factory()->create([
            'market_id' => (int) $market->id,
            'email' => 'attention-super-admin@example.test',
        ]);
        $user->assignRole('super-admin');

        IntegrationExchange::query()->create([
            'market_id' => (int) $market->id,
            'entity_type' => 'contract_debts',
            'direction' => IntegrationExchange::DIRECTION_IN,
            'status' => IntegrationExchange::STATUS_ERROR,
            'payload' => ['received' => 10],
            'started_at' => now()->subHours(2),
            'finished_at' => now()->subHours(1),
        ]);

        $this->actingAs($user);

        Livewire::test(MarketAttentionWidget::class)
            ->assertSee('recent_errors=1', false)
            ->assertSee('tableFilters%5Bstatus%5D%5Bvalue%5D=error', false);
    }
}
