<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Market;
use App\Models\User;
use App\Support\DemoPilotDataBuilder;
use App\Support\DemoPilotResetter;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DemoPilotResetterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('demo_pilot.enabled', true);
        config()->set('demo_pilot.reset_enabled', true);
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');

        DB::purge('sqlite');
        DB::reconnect('sqlite');

        Schema::create('markets', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->nullable()->unique();
            $table->string('code')->nullable()->unique();
            $table->string('address')->nullable();
            $table->string('timezone')->default('Europe/Moscow');
            $table->boolean('is_active')->default(true);
            $table->json('settings')->nullable();
            $table->json('features')->nullable();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->unsignedBigInteger('market_id')->nullable();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->timestamps();
        });

        Schema::create('model_has_roles', function (Blueprint $table): void {
            $table->unsignedBigInteger('role_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
        });

        Schema::create('model_has_permissions', function (Blueprint $table): void {
            $table->unsignedBigInteger('permission_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
        });

        Schema::create('market_locations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('market_id');
            $table->string('name');
            $table->string('code')->nullable();
            $table->string('type')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('tenants', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('market_id');
            $table->string('name');
            $table->string('external_id')->nullable();
            $table->timestamps();
        });

        Schema::create('market_spaces', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('market_id');
            $table->unsignedBigInteger('location_id')->nullable();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->string('number')->nullable();
            $table->string('code')->nullable();
            $table->timestamps();
        });

        Schema::create('tenant_contracts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('market_id');
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('market_space_id')->nullable();
            $table->string('external_id')->nullable();
            $table->timestamps();
        });

        Schema::create('tenant_accruals', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('market_id');
            $table->unsignedBigInteger('tenant_id');
            $table->string('source')->default('excel');
            $table->char('source_row_hash', 64)->nullable();
            $table->timestamps();
        });

        Schema::create('tenant_payments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('market_id');
            $table->unsignedBigInteger('tenant_id');
            $table->string('source')->default('1c');
            $table->char('source_row_hash', 64);
            $table->timestamps();
        });

        Schema::create('marketplace_categories', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('market_id')->nullable();
            $table->string('slug');
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('marketplace_products', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('market_id');
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('market_space_id')->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->string('slug');
            $table->string('title');
            $table->boolean('is_demo')->default(false);
            $table->timestamps();
        });

        Schema::create('marketplace_announcements', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('market_id');
            $table->unsignedBigInteger('author_user_id')->nullable();
            $table->string('slug');
            $table->string('title');
            $table->timestamps();
        });
    }

    public function test_plan_blocks_real_market_with_demo_slug(): void
    {
        Market::query()->create([
            'name' => 'Real Market',
            'slug' => 'demo-market',
            'code' => 'DEMO_MARKET',
            'settings' => [],
            'features' => [],
        ]);

        $report = app(DemoPilotResetter::class)->plan(
            app(DemoPilotDataBuilder::class)->build(),
        );

        self::assertSame('blocked', $report['status']);
        self::assertContains(
            'existing market [demo-market] is not marked as demo/pilot synthetic data',
            $report['issues'],
        );
    }

    public function test_execute_deletes_only_known_demo_records_and_keeps_leftovers(): void
    {
        $dataSet = app(DemoPilotDataBuilder::class)->build();
        $market = $this->createDemoMarket();
        $tenantId = $this->insertDemoTenant($market->getKey(), 'demo-tenant-grocery');
        $spaceId = $this->insertDemoSpace($market->getKey(), 'a-02', $tenantId);
        $userId = $this->insertDemoUser($market->getKey(), $tenantId, 'admin@demo.marketuchet.local');

        $this->insertDemoRows($market->getKey(), $tenantId, $spaceId, $userId);
        $this->insertLeftoverRows($market->getKey());

        $report = app(DemoPilotResetter::class)->execute($dataSet);

        self::assertSame('reset', $report['status']);
        self::assertSame('retained', $this->sectionStatus($report, 'market'));
        self::assertSame(1, DB::table('users')->where('email', 'real@example.test')->count());
        self::assertSame(0, DB::table('users')->where('email', 'admin@demo.marketuchet.local')->count());
        self::assertSame(0, DB::table('model_has_roles')->where('model_type', User::class)->where('model_id', $userId)->count());
        self::assertSame(0, DB::table('tenant_payments')->where('source', 'demo_pilot')->count());
        self::assertSame(1, DB::table('tenant_payments')->where('source', '1c')->count());
        self::assertSame(0, DB::table('marketplace_products')->where('slug', 'demo-honey-jar')->count());
        self::assertSame(1, DB::table('marketplace_products')->where('slug', 'real-product')->count());
        self::assertSame(1, Market::query()->whereKey($market->getKey())->count());
    }

    public function test_execute_retains_market_shell_when_no_known_rows_remain(): void
    {
        $dataSet = app(DemoPilotDataBuilder::class)->build();
        $market = $this->createDemoMarket();
        $tenantId = $this->insertDemoTenant($market->getKey(), 'demo-tenant-grocery');
        $spaceId = $this->insertDemoSpace($market->getKey(), 'a-02', $tenantId);
        $userId = $this->insertDemoUser($market->getKey(), $tenantId, 'admin@demo.marketuchet.local');

        $this->insertDemoRows($market->getKey(), $tenantId, $spaceId, $userId);

        $report = app(DemoPilotResetter::class)->execute($dataSet);

        self::assertSame('reset', $report['status']);
        self::assertSame('retained', $this->sectionStatus($report, 'market'));
        self::assertSame(1, Market::query()->whereKey($market->getKey())->count());
        self::assertSame(0, DB::table('tenant_payments')->where('market_id', $market->getKey())->count());
    }

    /**
     * @param array{sections:list<array{section:string, status:string}>} $report
     */
    private function sectionStatus(array $report, string $section): string
    {
        foreach ($report['sections'] as $sectionReport) {
            if ($sectionReport['section'] === $section) {
                return $sectionReport['status'];
            }
        }

        self::fail('Missing section [' . $section . '] in resetter report.');
    }

    private function createDemoMarket(): Market
    {
        return Market::query()->create([
            'name' => 'Demo Market',
            'slug' => 'demo-market',
            'code' => 'DEMO_MARKET',
            'settings' => [
                'demo_pilot' => [
                    'synthetic_source' => 'demo_pilot',
                ],
            ],
            'features' => [],
        ]);
    }

    private function insertDemoTenant(int $marketId, string $externalId): int
    {
        return (int) DB::table('tenants')->insertGetId([
            'market_id' => $marketId,
            'name' => $externalId,
            'external_id' => $externalId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertDemoSpace(int $marketId, string $code, int $tenantId): int
    {
        return (int) DB::table('market_spaces')->insertGetId([
            'market_id' => $marketId,
            'tenant_id' => $tenantId,
            'number' => strtoupper($code),
            'code' => $code,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertDemoUser(int $marketId, int $tenantId, string $email): int
    {
        $userId = (int) DB::table('users')->insertGetId([
            'market_id' => $marketId,
            'tenant_id' => $tenantId,
            'name' => $email,
            'email' => $email,
            'password' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('model_has_roles')->insert([
            'role_id' => 1,
            'model_type' => User::class,
            'model_id' => $userId,
        ]);

        return $userId;
    }

    private function insertDemoRows(int $marketId, int $tenantId, int $spaceId, int $userId): void
    {
        DB::table('market_locations')->insert([
            'market_id' => $marketId,
            'name' => 'Main Hall',
            'code' => 'main-hall',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('tenant_contracts')->insert([
            'market_id' => $marketId,
            'tenant_id' => $tenantId,
            'market_space_id' => $spaceId,
            'external_id' => 'demo-contract-grocery',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('tenant_accruals')->insert([
            'market_id' => $marketId,
            'tenant_id' => $tenantId,
            'source' => 'demo_pilot',
            'source_row_hash' => hash('sha256', 'demo_pilot:accruals:accrual-grocery'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('tenant_payments')->insert([
            'market_id' => $marketId,
            'tenant_id' => $tenantId,
            'source' => 'demo_pilot',
            'source_row_hash' => hash('sha256', 'demo_pilot:payments:payment-grocery'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('marketplace_categories')->insert([
            'market_id' => $marketId,
            'slug' => 'grocery',
            'name' => 'Grocery',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('marketplace_products')->insert([
            'market_id' => $marketId,
            'tenant_id' => $tenantId,
            'market_space_id' => $spaceId,
            'slug' => 'demo-honey-jar',
            'title' => 'Demo honey jar',
            'is_demo' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('marketplace_announcements')->insert([
            'market_id' => $marketId,
            'author_user_id' => $userId,
            'slug' => 'demo-weekend-market',
            'title' => 'Demo weekend market',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertLeftoverRows(int $marketId): void
    {
        DB::table('users')->insert([
            'market_id' => $marketId,
            'name' => 'Real User',
            'email' => 'real@example.test',
            'password' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('tenant_payments')->insert([
            'market_id' => $marketId,
            'tenant_id' => 999,
            'source' => '1c',
            'source_row_hash' => hash('sha256', 'real-payment'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('marketplace_products')->insert([
            'market_id' => $marketId,
            'tenant_id' => 999,
            'slug' => 'real-product',
            'title' => 'Real Product',
            'is_demo' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
