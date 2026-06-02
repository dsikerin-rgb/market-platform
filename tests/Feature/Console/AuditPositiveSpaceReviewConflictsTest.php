<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domain\Operations\OperationType;
use App\Domain\Operations\SpaceReviewDecision;
use App\Models\Market;
use App\Models\MarketSpace;
use App\Models\Operation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuditPositiveSpaceReviewConflictsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'name' => 'Test User',
            'email' => 'audit-positive-conflicts@example.com',
            'password' => Hash::make('password'),
        ]);
    }

    public function test_dry_run_finds_positive_conflict_without_modifying_it(): void
    {
        $market = $this->createMarket();
        $space = $this->createSpace($market, 'A-1');
        $operation = $this->createObservedConflict($space, 'Совпало');

        $this->artisan('space-review:audit-positive-conflicts --market=' . $market->id . ' --json')
            ->assertExitCode(0);

        $operation->refresh();
        $space->refresh();

        $this->assertSame('observed', $operation->status);
        $this->assertSame('conflict', $space->map_review_status);
        $this->assertSame(1, Operation::query()
            ->where('type', OperationType::SPACE_REVIEW)
            ->where('entity_id', $space->id)
            ->count());
    }

    public function test_apply_creates_explicit_matched_closure_for_positive_conflict(): void
    {
        $market = $this->createMarket();
        $space = $this->createSpace($market, 'A-2');
        $source = $this->createObservedConflict($space, 'Подтверждено');

        $this->artisan('space-review:audit-positive-conflicts --market=' . $market->id . ' --apply --max-auto-closes=10 --json')
            ->assertExitCode(0);

        $space->refresh();

        $closure = Operation::query()
            ->where('type', OperationType::SPACE_REVIEW)
            ->where('entity_id', $space->id)
            ->where('status', 'applied')
            ->latest('id')
            ->first();

        $this->assertNotNull($closure);
        $this->assertSame('matched', $closure->payload['decision'] ?? null);
        $this->assertSame($source->id, $closure->payload['source_review_operation_id'] ?? null);
        $this->assertTrue($closure->payload['auto_closed_by_positive_conflict_audit'] ?? false);
        $this->assertSame('matched', $space->map_review_status);
    }

    public function test_negative_reason_is_not_a_candidate(): void
    {
        $market = $this->createMarket();
        $space = $this->createSpace($market, 'A-3');
        $operation = $this->createObservedConflict($space, 'Не совпало');

        $this->artisan('space-review:audit-positive-conflicts --market=' . $market->id . ' --apply --max-auto-closes=10 --json')
            ->assertExitCode(0);

        $operation->refresh();
        $space->refresh();

        $this->assertSame('observed', $operation->status);
        $this->assertSame('conflict', $space->map_review_status);
        $this->assertSame(1, Operation::query()
            ->where('type', OperationType::SPACE_REVIEW)
            ->where('entity_id', $space->id)
            ->count());
    }

    private function createMarket(): Market
    {
        return Market::create([
            'name' => 'Test market',
            'slug' => 'positive-conflict-market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);
    }

    private function createSpace(Market $market, string $number): MarketSpace
    {
        return MarketSpace::create([
            'market_id' => $market->id,
            'number' => $number,
            'display_name' => $number,
            'status' => 'occupied',
            'is_active' => true,
            'map_review_status' => 'matched',
        ]);
    }

    private function createObservedConflict(MarketSpace $space, string $reason): Operation
    {
        return Operation::create([
            'market_id' => $space->market_id,
            'entity_type' => 'market_space',
            'entity_id' => $space->id,
            'type' => OperationType::SPACE_REVIEW,
            'status' => 'observed',
            'payload' => [
                'market_space_id' => $space->id,
                'decision' => SpaceReviewDecision::OCCUPANCY_CONFLICT,
                'reason' => $reason,
            ],
            'created_by' => $this->user->id,
        ]);
    }
}
