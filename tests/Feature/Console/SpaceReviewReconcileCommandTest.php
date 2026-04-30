<?php

namespace Tests\Feature\Console;

use App\Domain\Operations\SpaceReviewDecision;
use App\Models\Market;
use App\Models\MarketSpace;
use App\Models\MarketSpaceTenantBinding;
use App\Models\Operation;
use App\Models\Tenant;
use App\Models\TenantContract;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SpaceReviewReconcileCommandTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => Hash::make('password'),
        ]);
    }

    private function createMarket(): Market
    {
        return Market::create([
            'name' => 'Test Market',
            'slug' => 'test-market-reconcile',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);
    }

    private function createSpace(Market $market, array $overrides = []): MarketSpace
    {
        return MarketSpace::create(array_merge([
            'market_id' => $market->id,
            'number' => 'A-101',
            'display_name' => 'Space A-101',
            'code' => 'a-101',
            'status' => 'occupied',
            'is_active' => true,
        ], $overrides));
    }

    private function createTenant(Market $market, string $name): Tenant
    {
        return Tenant::create([
            'market_id' => $market->id,
            'name' => $name,
            'inn' => '1234567890',
            'is_active' => true,
        ]);
    }

    private function createBinding(MarketSpace $space, Tenant $tenant, array $overrides = []): MarketSpaceTenantBinding
    {
        return MarketSpaceTenantBinding::create(array_merge([
            'market_id' => $space->market_id,
            'market_space_id' => $space->id,
            'tenant_id' => $tenant->id,
            'tenant_contract_id' => null,
            'started_at' => now(),
            'ended_at' => null,
            'binding_type' => 'exact',
            'confidence' => 'high',
            'source' => 'tenant_contract_auto',
            'created_by_user_id' => $this->user->id,
            'resolution_reason' => 'contract_space_link',
        ], $overrides));
    }

    public function test_dry_run_does_not_modify_observed_operations(): void
    {
        $market = $this->createMarket();
        $space = $this->createSpace($market, [
            'number' => 'DRY-101',
            'display_name' => 'Dry run test',
        ]);
        $tenant = $this->createTenant($market, 'Скицко Виталий Александрович');

        // Создаем observed операцию
        $operation = Operation::create([
            'market_id' => $market->id,
            'entity_type' => 'market_space',
            'entity_id' => $space->id,
            'type' => 'space_review',
            'status' => 'observed',
            'payload' => [
                'market_space_id' => $space->id,
                'decision' => SpaceReviewDecision::TENANT_CHANGED_ON_SITE,
                'observed_tenant_name' => 'Скицко В.А.',
                'reason' => 'Сменился арендатор',
            ],
            'created_by' => $this->user->id,
        ]);

        // Создаем exact binding с совпадающим tenant
        $this->createBinding($space, $tenant);

        // Запускаем в dry-run режиме (по умолчанию без --apply)
        $this->artisan('space-review:reconcile --market=' . $market->id)
            ->assertExitCode(0);

        // Проверяем, что операция НЕ была изменена
        $operation->refresh();
        $this->assertSame('observed', $operation->status);
        $this->assertFalse($operation->payload['auto_closed_by_reconciliation'] ?? false);
        $this->assertArrayNotHasKey('auto_close_at', $operation->payload);
        $this->assertArrayNotHasKey('auto_close_binding_id', $operation->payload);
    }

    public function test_apply_closes_matching_observed_operation_and_sets_payload_fields(): void
    {
        $market = $this->createMarket();
        $space = $this->createSpace($market, [
            'number' => 'APPLY-101',
            'display_name' => 'Apply test',
            'map_review_status' => 'changed_tenant',
        ]);
        $tenant = $this->createTenant($market, 'Скицко Виталий Александрович');

        // Создаем observed операцию
        $operation = Operation::create([
            'market_id' => $market->id,
            'entity_type' => 'market_space',
            'entity_id' => $space->id,
            'type' => 'space_review',
            'status' => 'observed',
            'payload' => [
                'market_space_id' => $space->id,
                'decision' => SpaceReviewDecision::TENANT_CHANGED_ON_SITE,
                'observed_tenant_name' => 'Скицко В.А.',
                'reason' => 'Сменился арендатор',
            ],
            'created_by' => $this->user->id,
        ]);

        // Создаем exact binding с совпадающим tenant
        $binding = $this->createBinding($space, $tenant);

        // Запускаем с --apply
        $this->artisan('space-review:reconcile --market=' . $market->id . ' --apply --max-auto-closes=10')
            ->assertExitCode(0);

        // Проверяем, что операция была закрыта
        $operation->refresh();
        $this->assertSame('applied', $operation->status);
        $this->assertTrue($operation->payload['auto_closed_by_reconciliation']);
        $this->assertArrayHasKey('auto_close_at', $operation->payload);
        $this->assertSame($binding->id, $operation->payload['auto_close_binding_id']);

        // Проверяем, что map_review_status стал changed_tenant (как для applied tenant_changed_on_site)
        $space->refresh();
        $this->assertSame('changed_tenant', $space->map_review_status);
    }

    public function test_operation_without_exact_binding_is_not_changed(): void
    {
        $market = $this->createMarket();
        $space = $this->createSpace($market, [
            'number' => 'NOBIND-101',
            'display_name' => 'No binding test',
        ]);

        // Создаем observed операцию БЕЗ exact binding
        $operation = Operation::create([
            'market_id' => $market->id,
            'entity_type' => 'market_space',
            'entity_id' => $space->id,
            'type' => 'space_review',
            'status' => 'observed',
            'payload' => [
                'market_space_id' => $space->id,
                'decision' => SpaceReviewDecision::TENANT_CHANGED_ON_SITE,
                'observed_tenant_name' => 'Какой-то Арендатор',
                'reason' => 'Сменился арендатор',
            ],
            'created_by' => $this->user->id,
        ]);

        // Запускаем с --apply
        $this->artisan('space-review:reconcile --market=' . $market->id . ' --apply --max-auto-closes=10')
            ->assertExitCode(0);

        // Проверяем, что операция НЕ была изменена
        $operation->refresh();
        $this->assertSame('observed', $operation->status);
        $this->assertFalse($operation->payload['auto_closed_by_reconciliation'] ?? false);
    }

    public function test_applied_operation_preserves_original_payload_fields(): void
    {
        $market = $this->createMarket();
        $space = $this->createSpace($market, [
            'number' => 'PRESERVE-101',
            'display_name' => 'Preserve test',
        ]);
        $tenant = $this->createTenant($market, 'Скицко Виталий Александрович');

        // Создаем observed операцию с оригинальными полями
        $operation = Operation::create([
            'market_id' => $market->id,
            'entity_type' => 'market_space',
            'entity_id' => $space->id,
            'type' => 'space_review',
            'status' => 'observed',
            'payload' => [
                'market_space_id' => $space->id,
                'decision' => SpaceReviewDecision::TENANT_CHANGED_ON_SITE,
                'observed_tenant_name' => 'Скицко В.А.',
                'reason' => 'Сменился арендатор',
            ],
            'created_by' => $this->user->id,
        ]);

        // Создаем exact binding
        $this->createBinding($space, $tenant);

        // Запускаем с --apply
        $this->artisan('space-review:reconcile --market=' . $market->id . ' --apply --max-auto-closes=10')
            ->assertExitCode(0);

        // Проверяем, что оригинальные поля сохранены
        $operation->refresh();
        $this->assertSame('applied', $operation->status);
        $this->assertSame(SpaceReviewDecision::TENANT_CHANGED_ON_SITE, $operation->payload['decision']);
        $this->assertSame('Скицко В.А.', $operation->payload['observed_tenant_name']);
        $this->assertSame('Сменился арендатор', $operation->payload['reason']);
        $this->assertSame($space->id, $operation->payload['market_space_id']);
    }

    public function test_max_auto_closes_limits_number_of_applied_operations(): void
    {
        $market = $this->createMarket();

        // Создаем 3 observed операции с matching bindings
        $operations = [];
        for ($i = 0; $i < 3; $i++) {
            $space = $this->createSpace($market, [
                'number' => 'LIMIT-' . ($i + 1),
                'display_name' => 'Limit test ' . $i,
            ]);
            $tenant = $this->createTenant($market, 'Скицко Виталий Александрович');

            // Создаем операцию с разным created_at чтобы обеспечить порядок
            $operation = Operation::create([
                'market_id' => $market->id,
                'entity_type' => 'market_space',
                'entity_id' => $space->id,
                'type' => 'space_review',
                'status' => 'observed',
                'payload' => [
                    'market_space_id' => $space->id,
                    'decision' => SpaceReviewDecision::TENANT_CHANGED_ON_SITE,
                    'observed_tenant_name' => 'Скицко В.А.',
                    'reason' => 'Сменился арендатор',
                ],
                'created_by' => $this->user->id,
                'created_at' => now()->subMinutes(3 - $i), // Первая операция самая старая
            ]);

            $operations[] = $operation;
            $this->createBinding($space, $tenant);
        }

        // Запускаем с max-auto-closes=2
        $this->artisan('space-review:reconcile --market=' . $market->id . ' --apply --max-auto-closes=2')
            ->assertExitCode(0);

        // Проверяем, что только 2 операции были закрыты (самые старые)
        foreach ($operations as $index => $operation) {
            $operation->refresh();
            if ($index < 2) {
                $this->assertSame('applied', $operation->status);
                $this->assertTrue($operation->payload['auto_closed_by_reconciliation']);
            } else {
                $this->assertSame('observed', $operation->status);
                $this->assertFalse($operation->payload['auto_closed_by_reconciliation'] ?? false);
            }
        }
    }
}
