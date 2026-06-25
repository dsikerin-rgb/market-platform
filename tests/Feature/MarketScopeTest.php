<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Concerns\BelongsToMarket;
use App\Models\Market;
use App\Support\MarketContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class MarketScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('market_scope_test_records');
        Schema::create('market_scope_test_records', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('market_id')->nullable();
            $table->string('name');
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('market_scope_test_records');

        parent::tearDown();
    }

    public function test_disabled_scope_does_not_filter_queries(): void
    {
        [$firstMarket, $secondMarket] = $this->seedMarketScopeRecords();

        config()->set('market_context.scope_enabled', false);

        $names = app(MarketContext::class)->withMarket(
            $firstMarket,
            fn (): array => MarketScopeTestRecord::query()
                ->orderBy('name')
                ->pluck('name')
                ->all(),
        );

        self::assertSame(['first', 'second'], $names);
        self::assertNotSame((int) $firstMarket->id, (int) $secondMarket->id);
    }

    public function test_enabled_scope_filters_queries_to_current_market(): void
    {
        [$firstMarket] = $this->seedMarketScopeRecords();

        config()->set('market_context.scope_enabled', true);

        $names = app(MarketContext::class)->withMarket(
            $firstMarket,
            fn (): array => MarketScopeTestRecord::query()
                ->orderBy('name')
                ->pluck('name')
                ->all(),
        );

        self::assertSame(['first'], $names);
    }

    public function test_enabled_scope_without_context_is_non_strict_by_default(): void
    {
        $this->seedMarketScopeRecords();

        config()->set('market_context.scope_enabled', true);
        config()->set('market_context.strict_missing_context', false);

        self::assertSame(2, MarketScopeTestRecord::query()->count());
    }

    public function test_enabled_scope_can_fail_when_context_is_missing_in_strict_mode(): void
    {
        $this->seedMarketScopeRecords();

        config()->set('market_context.scope_enabled', true);
        config()->set('market_context.strict_missing_context', true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Market context is not available.');

        MarketScopeTestRecord::query()->count();
    }

    public function test_for_market_scope_is_explicit_and_independent_from_global_flag(): void
    {
        [$firstMarket] = $this->seedMarketScopeRecords();

        config()->set('market_context.scope_enabled', false);

        $names = MarketScopeTestRecord::query()
            ->forMarket($firstMarket)
            ->pluck('name')
            ->all();

        self::assertSame(['first'], $names);
    }

    public function test_market_scope_can_be_bypassed_explicitly_for_system_queries(): void
    {
        [$firstMarket] = $this->seedMarketScopeRecords();

        config()->set('market_context.scope_enabled', true);

        $names = app(MarketContext::class)->withMarket(
            $firstMarket,
            fn (): array => MarketScopeTestRecord::query()
                ->withoutMarketScope()
                ->orderBy('name')
                ->pluck('name')
                ->all(),
        );

        self::assertSame(['first', 'second'], $names);
    }

    /**
     * @return array{0: Market, 1: Market}
     */
    private function seedMarketScopeRecords(): array
    {
        $firstMarket = $this->createMarket('Market A');
        $secondMarket = $this->createMarket('Market B');

        MarketScopeTestRecord::query()->create([
            'market_id' => (int) $firstMarket->id,
            'name' => 'first',
        ]);
        MarketScopeTestRecord::query()->create([
            'market_id' => (int) $secondMarket->id,
            'name' => 'second',
        ]);

        return [$firstMarket, $secondMarket];
    }

    private function createMarket(string $name): Market
    {
        return Market::query()->create([
            'name' => $name,
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);
    }
}

class MarketScopeTestRecord extends Model
{
    use BelongsToMarket;

    protected $table = 'market_scope_test_records';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'market_id',
        'name',
    ];
}
