<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\MarketContext;
use App\Support\MarketWriteGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Tests\TestCase;

class MarketWriteGuardTest extends TestCase
{
    public function test_enabled_reflects_market_context_flag(): void
    {
        $guard = app(MarketWriteGuard::class);

        config()->set('market_context.write_guards_enabled', false);
        self::assertFalse($guard->enabled());

        config()->set('market_context.write_guards_enabled', true);
        self::assertTrue($guard->enabled());
    }

    public function test_assert_same_market_allows_matching_records(): void
    {
        $guard = app(MarketWriteGuard::class);

        $guard->assertSameMarket($this->record(10), $this->record(10));

        self::assertTrue($guard->isSameMarket($this->record(10), $this->record(10)));
    }

    public function test_assert_same_market_id_allows_matching_scalar_values(): void
    {
        $guard = app(MarketWriteGuard::class);

        $guard->assertSameMarketId('10', 10);

        self::assertTrue($guard->isSameMarketId('10', 10));
    }

    public function test_assert_same_market_rejects_cross_market_records(): void
    {
        $guard = app(MarketWriteGuard::class);

        $this->expectException(ValidationException::class);

        $guard->assertSameMarket($this->record(10), $this->record(20), 'tenant_id');
    }

    public function test_assert_same_market_id_rejects_cross_market_scalar_values(): void
    {
        $guard = app(MarketWriteGuard::class);

        $this->expectException(ValidationException::class);

        $guard->assertSameMarketId(10, 20, 'tenant_id');
    }

    public function test_assert_belongs_to_current_market_uses_override_context(): void
    {
        $guard = app(MarketWriteGuard::class);
        $context = app(MarketContext::class);

        $context->withMarket(10, fn () => $guard->assertBelongsToCurrentMarket($this->record(10)));

        $this->expectException(ValidationException::class);

        $context->withMarket(10, fn () => $guard->assertBelongsToCurrentMarket($this->record(20)));
    }

    public function test_assertions_require_positive_market_id(): void
    {
        $guard = app(MarketWriteGuard::class);

        $this->expectException(InvalidArgumentException::class);

        $guard->assertSameMarket($this->record(null), $this->record(10));
    }

    public function test_assertions_require_positive_scalar_market_id(): void
    {
        $guard = app(MarketWriteGuard::class);

        $this->expectException(InvalidArgumentException::class);

        $guard->assertSameMarketId(null, 10);
    }

    private function record(int|string|null $marketId): MarketWriteGuardTestRecord
    {
        return new MarketWriteGuardTestRecord(['market_id' => $marketId]);
    }
}

class MarketWriteGuardTestRecord extends Model
{
    protected $guarded = [];
}
