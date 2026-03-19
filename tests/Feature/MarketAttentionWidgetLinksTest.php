<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Widgets\MarketAttentionWidget;
use App\Models\ContractDebt;
use App\Models\IntegrationExchange;
use App\Models\Market;
use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;
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

    public function test_critical_debt_signal_links_to_red_only_slice(): void
    {
        $market = Market::query()->create([
            'name' => 'Тестовый рынок',
            'timezone' => 'Asia/Novosibirsk',
            'is_active' => true,
            'settings' => [
                'debt_monitoring' => [
                    'grace_days' => 5,
                    'red_after_days' => 30,
                ],
            ],
        ]);

        Role::findOrCreate('super-admin', 'web');

        $user = User::factory()->create([
            'market_id' => (int) $market->id,
            'email' => 'attention-red-debt@example.test',
        ]);
        $user->assignRole('super-admin');

        $tenant = Tenant::query()->create([
            'market_id' => (int) $market->id,
            'name' => 'Critical tenant',
            'is_active' => true,
            'external_id' => 'tenant-critical-001',
        ]);

        ContractDebt::query()->create([
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $tenant->id,
            'tenant_external_id' => 'tenant-critical-001',
            'contract_external_id' => 'contract-critical-001',
            'period' => '2026-01',
            'accrued_amount' => 10000,
            'paid_amount' => 0,
            'debt_amount' => 10000,
            'calculated_at' => Carbon::now()->subDays(40),
            'hash' => sha1('critical-debt-row'),
        ]);

        $this->actingAs($user);

        Livewire::test(MarketAttentionWidget::class)
            ->assertSee('with_red_debt=1', false)
            ->assertSee('tableFilters%5Bhas_critical_debt%5D%5Bvalue%5D=1', false);
    }
}
