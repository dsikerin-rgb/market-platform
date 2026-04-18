<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\TenantResource;
use App\Models\Market;
use App\Models\Tenant;
use App\Models\TenantContract;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TenantResourceDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_dashboard_shows_payment_discipline_block_with_overdue_days(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-10 12:00:00'));
        try {
            $fixture = $this->createFixture(withDebt: true);

            $this->actingAs($fixture['user']);

            $response = $this->get(TenantResource::getUrl('edit', ['record' => $fixture['tenant']]));

            $response->assertOk();
            $html = $response->getContent();
            $paymentCardText = $this->elementTextByClass($html, 'tenant-payment-discipline__card');

            $this->assertStringContainsString('Платёжная дисциплина', $html);
            $this->assertStringContainsString('tenant-payment-discipline__card--overdue', $html);
            $this->assertStringContainsString('Есть просрочка', $paymentCardText);
            $this->assertStringContainsString('Просрочка: 4 дней', $paymentCardText);
            $this->assertStringContainsString('Сумма просрочки: 1 200,00 ₽', $paymentCardText);
            $this->assertStringNotContainsString('Оплачено', $paymentCardText);
            $this->assertStringNotContainsString('Долг', $paymentCardText);
            $this->assertStringNotContainsString('Снимок', $paymentCardText);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_tenant_dashboard_shows_neutral_payment_discipline_when_no_debt_data(): void
    {
        $fixture = $this->createFixture(withDebt: false);

        $this->actingAs($fixture['user']);

        $response = $this->get(TenantResource::getUrl('edit', ['record' => $fixture['tenant']]));

        $response->assertOk();
        $html = $response->getContent();
        $paymentCardText = $this->elementTextByClass($html, 'tenant-payment-discipline__card');

        $this->assertStringContainsString('Платёжная дисциплина', $html);
        $this->assertStringContainsString('tenant-payment-discipline__state--neutral', $html);
        $this->assertStringContainsString('Нет данных', $paymentCardText);
        $this->assertStringNotContainsString('Есть просрочка', $paymentCardText);
        $this->assertStringNotContainsString('Просрочка:', $paymentCardText);
        $this->assertStringNotContainsString('Оплачено', $paymentCardText);
        $this->assertStringNotContainsString('Долг', $paymentCardText);
        $this->assertStringNotContainsString('Снимок', $paymentCardText);
    }

    /**
     * @return array{market:Market,tenant:Tenant,contract:TenantContract,user:User}
     */
    private function createFixture(bool $withDebt): array
    {
        $market = Market::query()->create([
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
            'settings' => [
                'debt_monitoring' => [
                    'grace_days' => 5,
                    'yellow_after_days' => 1,
                    'red_after_days' => 30,
                ],
            ],
        ]);

        $tenant = Tenant::query()->create([
            'market_id' => (int) $market->id,
            'name' => 'Tenant',
            'external_id' => 'tenant-101',
            'is_active' => true,
        ]);

        $contract = TenantContract::query()->create([
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $tenant->id,
            'number' => 'Contract-101',
            'status' => 'active',
            'starts_at' => '2026-04-01',
            'external_id' => 'contract-101',
            'is_active' => true,
        ]);

        if ($withDebt) {
            DB::table('contract_debts')->insert([
                'market_id' => (int) $market->id,
                'tenant_id' => (int) $tenant->id,
                'tenant_external_id' => 'tenant-101',
                'contract_external_id' => 'contract-101',
                'period' => '2026-04',
                'accrued_amount' => 1500.00,
                'paid_amount' => 300.00,
                'debt_amount' => 1200.00,
                'calculated_at' => '2026-04-01 10:00:00',
                'currency' => 'RUB',
                'source' => '1c',
                'raw_payload' => json_encode([
                    'calculated_at' => '2026-04-01 10:00:00',
                    'debt_amount' => 1200.00,
                ], JSON_UNESCAPED_UNICODE),
                'hash' => hash('sha256', 'tenant-discipline-row'),
                'created_at' => '2026-04-01 10:00:00',
            ]);
        }

        Role::findOrCreate('market-admin', 'web');

        $user = User::factory()->create([
            'market_id' => (int) $market->id,
            'email' => 'market-admin-dashboard@example.test',
        ]);
        $user->assignRole('market-admin');

        return compact('market', 'tenant', 'contract', 'user');
    }

    private function elementTextByClass(string $html, string $className): string
    {
        $node = $this->findElementByClass($html, $className);
        if (! $node) {
            self::fail(sprintf('Element with class "%s" was not found.', $className));
        }

        return trim(preg_replace('/\\s+/u', ' ', $node->textContent) ?? '');
    }

    private function findElementByClass(string $html, string $className): ?\DOMElement
    {
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new \DOMXPath($dom);
        $query = sprintf(
            '//*[contains(concat(" ", normalize-space(@class), " "), " %s ")]',
            $className,
        );

        $node = $xpath->query($query)->item(0);

        return $node instanceof \DOMElement ? $node : null;
    }

}
