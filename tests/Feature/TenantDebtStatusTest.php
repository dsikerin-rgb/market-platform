<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Market;
use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TenantDebtStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_adds_debt_status_columns(): void
    {
        $this->assertTrue(
            Schema::hasColumns('tenants', ['debt_status', 'debt_status_note', 'debt_status_updated_at'])
        );
    }

    public function test_debt_status_persists_and_updates_timestamp(): void
    {
        Carbon::setTestNow('2025-01-01 10:00:00');

        $market = Market::create([
            'name' => 'Тестовый рынок',
        ]);

        $tenant = Tenant::create([
            'market_id' => $market->id,
            'name' => 'ООО Тест',
            'debt_status' => 'green',
            'debt_status_note' => 'Без просрочек',
        ]);

        $this->assertSame('green', $tenant->debt_status);
        $this->assertSame('Без задолженности', $tenant->debt_status_label);
        $this->assertSame('2025-01-01 10:00:00', $tenant->debt_status_updated_at?->format('Y-m-d H:i:s'));

        Carbon::setTestNow('2025-01-02 12:00:00');

        $tenant->debt_status = 'red';
        $tenant->save();

        $tenant->refresh();

        $this->assertSame('red', $tenant->debt_status);
        $this->assertSame('2025-01-02 12:00:00', $tenant->debt_status_updated_at?->format('Y-m-d H:i:s'));
    }
}
