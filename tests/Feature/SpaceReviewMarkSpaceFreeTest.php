<?php
# tests/Feature/SpaceReviewMarkSpaceFreeTest.php

namespace Tests\Feature;

use App\Domain\Operations\OperationType;
use App\Domain\Operations\SpaceReviewDecision;
use App\Models\Market;
use App\Models\MarketSpace;
use App\Models\Operation;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SpaceReviewMarkSpaceFreeTest extends TestCase
{
    use RefreshDatabase;

    private function createMarket(array $attributes = []): Market
    {
        return Market::create(array_merge([
            'name' => 'Test Market',
            'slug' => 'test-market-' . uniqid(),
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ], $attributes));
    }

    private function createSpace(Market $market, array $attributes = []): MarketSpace
    {
        return MarketSpace::create(array_merge([
            'market_id' => $market->id,
            'number' => 'TEST-' . rand(1000, 9999),
            'status' => 'occupied',
            'is_active' => true,
        ], $attributes));
    }

    private function actingAsSuperAdmin(?int $marketId = null): User
    {
        Role::findOrCreate('super-admin', 'web');

        $user = User::factory()->create([
            'market_id' => $marketId,
        ]);
        $user->assignRole('super-admin');

        $this->actingAs($user, 'web');

        if (! config('auth.guards.filament')) {
            config()->set('auth.guards.filament', [
                'driver' => 'session',
                'provider' => 'users',
            ]);
        }

        $this->actingAs($user, 'filament');

        return $user;
    }

    private function withCsrfToken()
    {
        $token = csrf_token();
        return $this->withHeaders([
            'X-CSRF-TOKEN' => $token,
        ]);
    }

    /**
     * Тест: mark_space_free реально освобождает место (очищает tenant_id)
     * Проверяет баг П75: operation applied, но место всё ещё показывает арендатора
     */
    public function test_mark_space_free_clears_tenant_id_and_status(): void
    {
        $market = $this->createMarket();
        $user = $this->actingAsSuperAdmin((int) $market->id);

        $tenant = Tenant::create([
            'market_id' => $market->id,
            'name' => 'Test Tenant',
            'display_name' => 'Тестовый Арендатор',
            'is_active' => true,
        ]);

        $space = $this->createSpace($market, [
            'tenant_id' => $tenant->id,
            'status' => 'occupied',
        ]);

        // До: место занято
        $this->assertSame($tenant->id, $space->tenant_id);
        $this->assertSame('occupied', $space->status);

        $response = $this->withCsrfToken()->postJson('/admin/market-map/review-decision', [
            'decision' => SpaceReviewDecision::MARK_SPACE_FREE,
            'market_space_id' => $space->id,
        ]);

        $response->assertOk();

        // Проверяем operation
        $operation = Operation::query()
            ->where('market_id', $market->id)
            ->where('entity_type', 'market_space')
            ->where('entity_id', $space->id)
            ->where('type', OperationType::SPACE_REVIEW)
            ->latest('id')
            ->first();

        $this->assertNotNull($operation);
        $this->assertSame('applied', $operation->status);
        $this->assertSame(SpaceReviewDecision::MARK_SPACE_FREE, $operation->payload['decision'] ?? null);
        $this->assertSame($space->id, $operation->payload['market_space_id'] ?? null);

        $space->refresh();

        // После: место реально освобождено
        $this->assertSame('vacant', $space->status, 'status должен стать vacant');
        $this->assertNull($space->tenant_id, 'tenant_id должен быть очищен (баг П75)');
        $this->assertFalse($space->isEffectivelyOccupied(), 'место не должно быть занятым');
        $this->assertSame('none', $space->effectiveOccupancySource());
    }

    /**
     * Тест: после MARK_SPACE_FREE место не считается занятым старым tenant_id
     * Проверяет, что fix не вызывает откат к старому tenant_id
     */
    public function test_space_is_not_seen_as_occupied_after_mark_space_free(): void
    {
        $market = $this->createMarket();
        $user = $this->actingAsSuperAdmin((int) $market->id);

        $tenant = Tenant::create([
            'market_id' => $market->id,
            'name' => 'Old Tenant',
            'is_active' => true,
        ]);

        $space = $this->createSpace($market, [
            'tenant_id' => $tenant->id,
            'status' => 'occupied',
        ]);

        // Применяем MARK_SPACE_FREE
        $response = $this->withCsrfToken()->postJson('/admin/market-map/review-decision', [
            'decision' => SpaceReviewDecision::MARK_SPACE_FREE,
            'market_space_id' => $space->id,
        ]);

        $response->assertOk();

        $space->refresh();

        // Проверяем, что место освобождено и нет отката к старому tenant_id
        $this->assertNull($space->tenant_id, 'tenant_id должен быть null');
        $this->assertSame('vacant', $space->status, 'status должен быть vacant');
        $this->assertFalse($space->isEffectivelyOccupied(), 'isEffectivelyOccupied должен быть false');
        $this->assertSame('none', $space->effectiveOccupancySource(), 'effectiveOccupancySource должен быть none');

        // Проверяем operation
        $operation = Operation::query()
            ->where('market_id', $market->id)
            ->where('entity_type', 'market_space')
            ->where('entity_id', $space->id)
            ->where('type', OperationType::SPACE_REVIEW)
            ->where('status', 'applied')
            ->latest('id')
            ->first();

        $this->assertNotNull($operation);
        $this->assertSame(SpaceReviewDecision::MARK_SPACE_FREE, $operation->payload['decision'] ?? null);
        // В payload не должно быть отката к старому tenant_id
        $this->assertNull($operation->payload['to_tenant_id'] ?? null, 'payload не должен содержать to_tenant_id');
    }
}
