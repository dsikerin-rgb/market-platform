<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Widgets\AccrualCompositionWidget;
use App\Models\Market;
use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class AccrualCompositionWidgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_widget_is_hidden_when_only_rent_component_is_present(): void
    {
        Carbon::setTestNow('2026-03-20 12:00:00');

        $market = Market::query()->create([
            'name' => 'Эко Ярмарка',
            'timezone' => 'Asia/Novosibirsk',
            'is_active' => true,
        ]);

        $tenant = Tenant::query()->create([
            'market_id' => (int) $market->id,
            'name' => 'Тестовый арендатор',
            'is_active' => true,
            'external_id' => 'tenant-widget-only-rent',
        ]);

        $user = User::factory()->create([
            'market_id' => (int) $market->id,
            'email' => 'accrual-composition-only-rent@example.test',
        ]);

        DB::table('tenant_accruals')->insert([
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $tenant->id,
            'period' => '2026-03-01',
            'currency' => 'RUB',
            'rent_amount' => 1000.00,
            'utilities_amount' => 0,
            'electricity_amount' => 0,
            'management_fee' => 0,
            'total_with_vat' => 1000.00,
            'source' => '1c',
            'source_row_hash' => hash('sha256', 'widget-only-rent'),
            'imported_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user)->withSession([
            'dashboard_month' => '2026-03',
        ]);

        $this->assertFalse(AccrualCompositionWidget::canView());

        Carbon::setTestNow();
    }

    public function test_widget_is_visible_when_non_rent_component_is_present(): void
    {
        Carbon::setTestNow('2026-03-20 12:00:00');

        $market = Market::query()->create([
            'name' => 'Эко Ярмарка',
            'timezone' => 'Asia/Novosibirsk',
            'is_active' => true,
        ]);

        $tenant = Tenant::query()->create([
            'market_id' => (int) $market->id,
            'name' => 'Тестовый арендатор',
            'is_active' => true,
            'external_id' => 'tenant-widget-with-utilities',
        ]);

        $user = User::factory()->create([
            'market_id' => (int) $market->id,
            'email' => 'accrual-composition-with-utilities@example.test',
        ]);

        DB::table('tenant_accruals')->insert([
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $tenant->id,
            'period' => '2026-03-01',
            'currency' => 'RUB',
            'rent_amount' => 1000.00,
            'utilities_amount' => 50.00,
            'electricity_amount' => 0,
            'management_fee' => 0,
            'total_with_vat' => 1050.00,
            'source' => '1c',
            'source_row_hash' => hash('sha256', 'widget-with-utilities'),
            'imported_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user)->withSession([
            'dashboard_month' => '2026-03',
        ]);

        $this->assertTrue(AccrualCompositionWidget::canView());

        Carbon::setTestNow();
    }

    public function test_widget_shows_percentage_labels_for_1c_accrual_composition(): void
    {
        Carbon::setTestNow('2026-03-20 12:00:00');

        $market = Market::query()->create([
            'name' => 'Эко Ярмарка',
            'timezone' => 'Asia/Novosibirsk',
            'is_active' => true,
        ]);

        $tenant = Tenant::query()->create([
            'market_id' => (int) $market->id,
            'name' => 'Тестовый арендатор',
            'is_active' => true,
            'external_id' => 'tenant-widget-001',
        ]);

        $user = User::factory()->create([
            'market_id' => (int) $market->id,
            'email' => 'accrual-composition-widget@example.test',
        ]);

        DB::table('tenant_accruals')->insert([
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $tenant->id,
            'period' => '2026-03-01',
            'currency' => 'RUB',
            'rent_amount' => 800.00,
            'utilities_amount' => 200.00,
            'electricity_amount' => 0,
            'management_fee' => 0,
            'total_with_vat' => 1000.00,
            'source' => '1c',
            'source_row_hash' => hash('sha256', 'widget-row-1'),
            'imported_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user)->withSession([
            'dashboard_month' => '2026-03',
        ]);

        $livewire = Livewire::test(AccrualCompositionWidget::class);

        $method = new \ReflectionMethod($livewire->instance(), 'getData');
        $method->setAccessible(true);
        $data = $method->invoke($livewire->instance());

        $labels = $data['labels'] ?? [];

        $this->assertContains('Аренда (80%)', $labels);
        $this->assertContains('Коммунальные (20%)', $labels);

        Carbon::setTestNow();
    }
}
