<?php
/**
 * Path: tests/Feature/MarketMap/OperationEffectiveDateFixTest.php
 * Description: Feature tests для OperationEffectiveDateFixer.
 */

declare(strict_types=1);

namespace Tests\Feature\MarketMap;

use App\Domain\Operations\OperationType;
use App\Models\Market;
use App\Models\MarketSpace;
use App\Models\Operation;
use App\Models\Tenant;
use App\Services\MarketMap\OperationEffectiveDateFixer;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests для OperationEffectiveDateFixer.
 *
 * Тестирует:
 * - Успешное исправление effective_date для linked операций
 * - Сохранение времени при изменении даты
 * - Блокировку не-linked операций
 * - Блокировку non-applied операций
 * - Блокировку будущих дат
 * - Требование причины исправления
 * - Создание audit operation с правильным payload
 */

class OperationEffectiveDateFixTest extends TestCase
{
    use RefreshDatabase;

    private function createMarket(): Market
    {
        return Market::create([
            'name' => 'Test Market',
            'timezone' => 'Asia/Barnaul',
            'is_active' => true,
        ]);
    }

    private function createSpace(Market $market, array $attributes = []): MarketSpace
    {
        return MarketSpace::create([
            'market_id' => (int) $market->id,
            'number' => $attributes['number'] ?? 'TEST-1',
            'display_name' => $attributes['display_name'] ?? 'Test Space',
            'status' => $attributes['status'] ?? 'occupied',
            'is_active' => true,
            'tenant_id' => $attributes['tenant_id'] ?? null,
            'map_review_status' => $attributes['map_review_status'] ?? 'matched',
        ]);
    }

    private function actingAsSuperAdmin(int $marketId = 0): \App\Models\User
    {
        $user = \App\Models\User::create([
            'email' => 'admin@test.local',
            'name' => 'Test Admin',
            'password' => bcrypt('password'),
            'is_super_admin' => true,
        ]);

        $this->actingAs($user);

        return $user;
    }

    public function test_successfully_fixes_effective_date_for_linked_operations(): void
    {
        $market = $this->createMarket();
        $user = $this->actingAsSuperAdmin((int) $market->id);
        $tenant = Tenant::create([
            'market_id' => (int) $market->id,
            'name' => 'Test Tenant',
            'is_active' => true,
        ]);
        $space = $this->createSpace($market, [
            'tenant_id' => (int) $tenant->id,
            'map_review_status' => 'matched',
        ]);

        // Создаём tenant_switch (id будет меньше) - используем дату в прошлом
        $tenantSwitch = Operation::create([
            'market_id' => (int) $market->id,
            'entity_type' => 'market_space',
            'entity_id' => (int) $space->id,
            'type' => OperationType::TENANT_SWITCH,
            'effective_at' => CarbonImmutable::parse('2025-01-01 10:30:00', 'Asia/Barnaul')->utc(),
            'effective_month' => CarbonImmutable::parse('2025-01-01', 'Asia/Barnaul')->startOfMonth(),
            'status' => 'applied',
            'payload' => [
                'market_space_id' => (int) $space->id,
                'to_tenant_id' => (int) $tenant->id,
            ],
            'created_by' => (int) $user->id,
        ]);

        // Создаём space_review (id будет больше)
        $spaceReview = Operation::create([
            'market_id' => (int) $market->id,
            'entity_type' => 'market_space',
            'entity_id' => (int) $space->id,
            'type' => OperationType::SPACE_REVIEW,
            'effective_at' => CarbonImmutable::parse('2025-01-01 15:45:00', 'Asia/Barnaul')->utc(),
            'effective_month' => CarbonImmutable::parse('2025-01-01', 'Asia/Barnaul')->startOfMonth(),
            'status' => 'applied',
            'payload' => [
                'market_space_id' => (int) $space->id,
                'decision' => 'matched',
            ],
            'created_by' => (int) $user->id,
        ]);

        // Старые значения для проверки
        $oldTenantEffectiveAt = $tenantSwitch->effective_at;
        $oldSpaceEffectiveAt = $spaceReview->effective_at;

        // Debug: проверить, что в БД
        $tsDb = \DB::table('operations')->where('id', $tenantSwitch->id)->first();
        $srDb = \DB::table('operations')->where('id', $spaceReview->id)->first();

        if (! $tsDb || ! $srDb) {
            $this->fail('Операции не найдены в БД после создания');
        }

        // Используем дату, которая точно будет в прошлом относительно любого реального времени
        $newEffectiveDate = '2025-06-15';

        $fixer = app(OperationEffectiveDateFixer::class);
        $result = $fixer->fixEffectiveDate(
            (int) $spaceReview->id,
            (int) $tenantSwitch->id,
            $newEffectiveDate,
            'Исправление ошибки в дате',
            (int) $user->id
        );

        $this->assertTrue($result['ok']);
        $this->assertArrayHasKey('fixed_operation_ids', $result);
        $this->assertSame((int) $tenantSwitch->id, $result['fixed_operation_ids']['tenant_switch']);
        $this->assertSame((int) $spaceReview->id, $result['fixed_operation_ids']['space_review']);

        // Проверяем обновлённые значения через DB::table (чтобы обойти кэширование Eloquent)
        $tenantSwitchFresh = \DB::table('operations')->where('id', $tenantSwitch->id)->first();
        $spaceReviewFresh = \DB::table('operations')->where('id', $spaceReview->id)->first();
        $spaceFresh = \DB::table('market_spaces')->where('id', $space->id)->first();

        // Проверяем, что дата изменилась на новую
        $this->assertStringStartsWith('2025-06-15', $tenantSwitchFresh->effective_at);
        $this->assertStringStartsWith('2025-06-15', $spaceReviewFresh->effective_at);

        // Проверяем, что effective_month корректный (первое число месяца)
        $this->assertSame('2025-06-01', $tenantSwitchFresh->effective_month);
        $this->assertSame('2025-06-01', $spaceReviewFresh->effective_month);

        // Проверяем, что map_reviewed_at обновлён
        $this->assertNotNull($spaceFresh->map_reviewed_at);
        $this->assertStringStartsWith('2025-06-15', $spaceFresh->map_reviewed_at);

        // Проверяем audit operation
        $auditOperation = \DB::table('operations')
            ->where('type', OperationType::SPACE_REVIEW)
            ->where('status', 'applied')
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($auditOperation, 'Audit operation не найдена');

        $auditPayload = json_decode($auditOperation->payload, true);
        $this->assertSame('effective_date_corrected', $auditPayload['audit_type']);
        $this->assertContains($tenantSwitch->id, $auditPayload['corrected_operation_ids']);
        $this->assertContains($spaceReview->id, $auditPayload['corrected_operation_ids']);
        $this->assertNotNull($auditPayload['old_effective_at']);
        $this->assertNotNull($auditPayload['new_effective_at']);
        $this->assertSame('Исправление ошибки в дате', $auditPayload['reason']);
    }

    public function test_preserves_time_when_changing_date_in_asia_barnaul_timezone(): void
    {
        $market = $this->createMarket(); // Asia/Barnaul
        $user = $this->actingAsSuperAdmin((int) $market->id);
        $tenant = Tenant::create([
            'market_id' => (int) $market->id,
            'name' => 'Test Tenant',
            'is_active' => true,
        ]);
        $space = $this->createSpace($market);

        // Создаём операции с конкретным временем - используем дату в прошлом
        $tenantSwitch = Operation::create([
            'market_id' => (int) $market->id,
            'entity_type' => 'market_space',
            'entity_id' => (int) $space->id,
            'type' => OperationType::TENANT_SWITCH,
            'effective_at' => CarbonImmutable::parse('2025-01-01 14:25:30', 'Asia/Barnaul')->utc(),
            'status' => 'applied',
            'payload' => ['market_space_id' => (int) $space->id, 'to_tenant_id' => (int) $tenant->id],
            'created_by' => (int) $user->id,
        ]);

        $spaceReview = Operation::create([
            'market_id' => (int) $market->id,
            'entity_type' => 'market_space',
            'entity_id' => (int) $space->id,
            'type' => OperationType::SPACE_REVIEW,
            'effective_at' => CarbonImmutable::parse('2025-01-01 18:10:45', 'Asia/Barnaul')->utc(),
            'status' => 'applied',
            'payload' => ['market_space_id' => (int) $space->id, 'decision' => 'matched'],
            'created_by' => (int) $user->id,
        ]);

        $fixer = app(OperationEffectiveDateFixer::class);
        $result = $fixer->fixEffectiveDate(
            (int) $spaceReview->id,
            (int) $tenantSwitch->id,
            '2025-03-15',
            'Тест сохранения времени',
            (int) $user->id
        );

        $this->assertTrue($result['ok']);

        $tenantSwitchFresh = \DB::table('operations')->where('id', $tenantSwitch->id)->first();
        $spaceReviewFresh = \DB::table('operations')->where('id', $spaceReview->id)->first();

        // Проверяем, что дата изменилась на новую
        $this->assertStringStartsWith('2025-03-15', $tenantSwitchFresh->effective_at);
        $this->assertStringStartsWith('2025-03-15', $spaceReviewFresh->effective_at);

        // Проверяем, что время сохранилось точно (HH:MM:SS)
        // 14:25:30 Barnaul (март UTC+10) = 04:25:30 UTC
        // 18:10:45 Barnaul (март UTC+10) = 08:10:45 UTC
        $this->assertStringContainsString('04:25:30', $tenantSwitchFresh->effective_at);
        $this->assertStringContainsString('08:10:45', $spaceReviewFresh->effective_at);

        // Проверяем, что effective_month корректный (первое число месяца)
        $this->assertSame('2025-03-01', $tenantSwitchFresh->effective_month);
        $this->assertSame('2025-03-01', $spaceReviewFresh->effective_month);

        // Проверяем audit operation с расширенным payload
        $auditOperation = \DB::table('operations')
            ->where('type', OperationType::SPACE_REVIEW)
            ->where('status', 'applied')
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($auditOperation, 'Audit operation не найдена');

        $auditPayload = json_decode($auditOperation->payload, true);
        $this->assertSame('effective_date_corrected', $auditPayload['audit_type']);
        $this->assertContains($tenantSwitch->id, $auditPayload['corrected_operation_ids']);
        $this->assertContains($spaceReview->id, $auditPayload['corrected_operation_ids']);
        $this->assertNotNull($auditPayload['old_effective_at']);
        $this->assertNotNull($auditPayload['new_effective_at']);
        $this->assertSame('Тест сохранения времени', $auditPayload['reason']);

        // Проверяем структуру old_effective_at и new_effective_at
        $this->assertArrayHasKey('tenant_switch', $auditPayload['old_effective_at']);
        $this->assertArrayHasKey('space_review', $auditPayload['old_effective_at']);
        $this->assertArrayHasKey('tenant_switch', $auditPayload['new_effective_at']);
        $this->assertArrayHasKey('space_review', $auditPayload['new_effective_at']);

        // Проверяем, что old значения соответствуют исходным операциям
        $this->assertStringContainsString('04:25:30', $auditPayload['old_effective_at']['tenant_switch']);
        $this->assertStringContainsString('08:10:45', $auditPayload['old_effective_at']['space_review']);

        // Проверяем, что new значения имеют новую дату и то же время
        $this->assertStringStartsWith('2025-03-15', $auditPayload['new_effective_at']['tenant_switch']);
        $this->assertStringContainsString('04:25:30', $auditPayload['new_effective_at']['tenant_switch']);
        $this->assertStringStartsWith('2025-03-15', $auditPayload['new_effective_at']['space_review']);
        $this->assertStringContainsString('08:10:45', $auditPayload['new_effective_at']['space_review']);
    }

    public function test_blocks_if_tenant_switch_not_linked_to_space_review(): void
    {
        $market = $this->createMarket();
        $user = $this->actingAsSuperAdmin((int) $market->id);
        $tenant = Tenant::create([
            'market_id' => (int) $market->id,
            'name' => 'Test Tenant',
            'is_active' => true,
        ]);
        $space1 = $this->createSpace($market, ['number' => 'SPACE-1']);
        $space2 = $this->createSpace($market, ['number' => 'SPACE-2']);

        $tenantSwitch = Operation::create([
            'market_id' => (int) $market->id,
            'entity_type' => 'market_space',
            'entity_id' => (int) $space1->id,
            'type' => OperationType::TENANT_SWITCH,
            'effective_at' => now('UTC'),
            'status' => 'applied',
            'payload' => ['market_space_id' => (int) $space1->id, 'to_tenant_id' => (int) $tenant->id],
            'created_by' => (int) $user->id,
        ]);

        $spaceReview = Operation::create([
            'market_id' => (int) $market->id,
            'entity_type' => 'market_space',
            'entity_id' => (int) $space2->id,
            'type' => OperationType::SPACE_REVIEW,
            'effective_at' => now('UTC'),
            'status' => 'applied',
            'payload' => ['market_space_id' => (int) $space2->id, 'decision' => 'matched'],
            'created_by' => (int) $user->id,
        ]);

        $fixer = app(OperationEffectiveDateFixer::class);
        $result = $fixer->fixEffectiveDate(
            (int) $spaceReview->id,
            (int) $tenantSwitch->id,
            '2026-02-01',
            'Тест',
            (int) $user->id
        );

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('одному торговому месту', $result['message']);
    }

    public function test_blocks_if_operations_not_applied(): void
    {
        $market = $this->createMarket();
        $user = $this->actingAsSuperAdmin((int) $market->id);
        $tenant = Tenant::create([
            'market_id' => (int) $market->id,
            'name' => 'Test Tenant',
            'is_active' => true,
        ]);
        $space = $this->createSpace($market);

        // tenant_switch в статусе draft
        $tenantSwitch = Operation::create([
            'market_id' => (int) $market->id,
            'entity_type' => 'market_space',
            'entity_id' => (int) $space->id,
            'type' => OperationType::TENANT_SWITCH,
            'effective_at' => now('UTC'),
            'status' => 'draft',
            'payload' => ['market_space_id' => (int) $space->id, 'to_tenant_id' => (int) $tenant->id],
            'created_by' => (int) $user->id,
        ]);

        $spaceReview = Operation::create([
            'market_id' => (int) $market->id,
            'entity_type' => 'market_space',
            'entity_id' => (int) $space->id,
            'type' => OperationType::SPACE_REVIEW,
            'effective_at' => now('UTC'),
            'status' => 'applied',
            'payload' => ['market_space_id' => (int) $space->id, 'decision' => 'matched'],
            'created_by' => (int) $user->id,
        ]);

        $fixer = app(OperationEffectiveDateFixer::class);
        $result = $fixer->fixEffectiveDate(
            (int) $spaceReview->id,
            (int) $tenantSwitch->id,
            '2026-02-01',
            'Тест',
            (int) $user->id
        );

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('applied', $result['message']);
    }

    public function test_blocks_if_date_is_in_future(): void
    {
        $market = $this->createMarket();
        $user = $this->actingAsSuperAdmin((int) $market->id);
        $tenant = Tenant::create([
            'market_id' => (int) $market->id,
            'name' => 'Test Tenant',
            'is_active' => true,
        ]);
        $space = $this->createSpace($market);

        $tenantSwitch = Operation::create([
            'market_id' => (int) $market->id,
            'entity_type' => 'market_space',
            'entity_id' => (int) $space->id,
            'type' => OperationType::TENANT_SWITCH,
            'effective_at' => now('UTC'),
            'status' => 'applied',
            'payload' => ['market_space_id' => (int) $space->id, 'to_tenant_id' => (int) $tenant->id],
            'created_by' => (int) $user->id,
        ]);

        $spaceReview = Operation::create([
            'market_id' => (int) $market->id,
            'entity_type' => 'market_space',
            'entity_id' => (int) $space->id,
            'type' => OperationType::SPACE_REVIEW,
            'effective_at' => now('UTC'),
            'status' => 'applied',
            'payload' => ['market_space_id' => (int) $space->id, 'decision' => 'matched'],
            'created_by' => (int) $user->id,
        ]);

        // Используем дату, которая будет в будущем относительно любого разумного текущего времени
        $fixer = app(OperationEffectiveDateFixer::class);
        $result = $fixer->fixEffectiveDate(
            (int) $spaceReview->id,
            (int) $tenantSwitch->id,
            '2099-12-31',
            'Проверка блокировки будущей даты',
            (int) $user->id
        );

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('будущем', $result['message']);
    }

    public function test_requires_reason(): void
    {
        $market = $this->createMarket();
        $user = $this->actingAsSuperAdmin((int) $market->id);
        $tenant = Tenant::create([
            'market_id' => (int) $market->id,
            'name' => 'Test Tenant',
            'is_active' => true,
        ]);
        $space = $this->createSpace($market);

        $tenantSwitch = Operation::create([
            'market_id' => (int) $market->id,
            'entity_type' => 'market_space',
            'entity_id' => (int) $space->id,
            'type' => OperationType::TENANT_SWITCH,
            'effective_at' => now('UTC'),
            'status' => 'applied',
            'payload' => ['market_space_id' => (int) $space->id, 'to_tenant_id' => (int) $tenant->id],
            'created_by' => (int) $user->id,
        ]);

        $spaceReview = Operation::create([
            'market_id' => (int) $market->id,
            'entity_type' => 'market_space',
            'entity_id' => (int) $space->id,
            'type' => OperationType::SPACE_REVIEW,
            'effective_at' => now('UTC'),
            'status' => 'applied',
            'payload' => ['market_space_id' => (int) $space->id, 'decision' => 'matched'],
            'created_by' => (int) $user->id,
        ]);

        $fixer = app(OperationEffectiveDateFixer::class);
        $result = $fixer->fixEffectiveDate(
            (int) $spaceReview->id,
            (int) $tenantSwitch->id,
            '2026-02-01',
            '',
            (int) $user->id
        );

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Причина', $result['message']);
    }
}
