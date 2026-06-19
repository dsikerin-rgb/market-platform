<?php

declare(strict_types=1);

namespace Tests\Unit\Filament\Widgets;

use App\Filament\Widgets\AccrualCompositionWidget;
use App\Models\Market;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AccrualCompositionWidgetTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_widget_groups_one_c_accrual_packages_without_splitting_composite_services(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 6, 15, 12, 0, 0, 'UTC'));

        $market = Market::query()->create([
            'name' => 'Package Widget Market',
            'timezone' => 'UTC',
            'is_active' => true,
        ]);

        $tenant = Tenant::query()->create([
            'market_id' => (int) $market->id,
            'name' => 'Tenant',
            'is_active' => true,
        ]);

        $user = User::factory()->create(['market_id' => (int) $market->id]);
        auth()->login($user);

        $this->insertAccrual((int) $market->id, (int) $tenant->id, 'Арендная плата', 1000, 'rent-1');
        $this->insertAccrual((int) $market->id, (int) $tenant->id, 'Арендная плата', 500, 'rent-2');
        $this->insertAccrual(
            (int) $market->id,
            (int) $tenant->id,
            'Арендная плата; Компенсация потребленной эл/энергии',
            1000,
            'rent-electricity',
        );
        $this->insertAccrual(
            (int) $market->id,
            (int) $tenant->id,
            'Компенсация потребленной эл/энергии',
            500,
            'electricity',
        );

        $widget = (new class extends AccrualCompositionWidget
        {
            public ?array $pageFilters = ['dashboard_month' => '2026-06'];

            public ?array $filters = null;

            public function exposedGetViewData(): array
            {
                return $this->getViewData();
            }
        });

        $data = $widget->exposedGetViewData();

        self::assertSame('Состав начислений 1С', $data['heading']);
        self::assertSame('06.2026 • группировка по составу услуг', $data['description']);
        self::assertSame(3000.0, $data['totalAmount']);
        self::assertSame(4, $data['rowsCount']);
        self::assertSame(3, $data['packagesCount']);
        self::assertSame([
            'Аренда',
            'Аренда + Эл/энергия',
            'Эл/энергия',
        ], array_column($data['packages'], 'label'));
        self::assertSame([1500.0, 1000.0, 500.0], array_column($data['packages'], 'amount'));
        self::assertSame(['50', '33.3', '16.7'], array_column($data['packages'], 'percent_label'));
    }

    private function insertAccrual(
        int $marketId,
        int $tenantId,
        string $serviceName,
        float $amount,
        string $hashSeed,
    ): void {
        DB::table('tenant_accruals')->insert([
            'market_id' => $marketId,
            'tenant_id' => $tenantId,
            'period' => '2026-06-01',
            'service_name' => $serviceName,
            'currency' => 'RUB',
            'rent_amount' => $amount,
            'total_with_vat' => $amount,
            'status' => 'imported',
            'source' => '1c',
            'source_file' => '1c:accruals',
            'source_row_hash' => hash('sha256', $hashSeed),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
