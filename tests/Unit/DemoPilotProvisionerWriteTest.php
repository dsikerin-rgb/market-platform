<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Market;
use App\Models\MarketLocation;
use App\Models\MarketSpace;
use App\Models\Tenant;
use App\Support\DemoPilotDataBuilder;
use App\Support\DemoPilotProvisioner;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DemoPilotProvisionerWriteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('demo_pilot.enabled', true);
        config()->set('demo_pilot.provision_enabled', true);
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

        Schema::create('market_locations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('market_id');
            $table->string('name');
            $table->string('code')->nullable();
            $table->string('type')->nullable();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('tenants', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('market_id');
            $table->string('name');
            $table->string('short_name')->nullable();
            $table->string('slug')->nullable()->unique();
            $table->string('type')->nullable();
            $table->string('external_id')->nullable();
            $table->string('one_c_uid')->nullable();
            $table->string('inn')->nullable();
            $table->string('kpp')->nullable();
            $table->string('ogrn')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('contact_person')->nullable();
            $table->string('status')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->json('one_c_data')->nullable();
            $table->string('debt_status')->nullable();
            $table->timestamp('debt_status_updated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('market_spaces', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('market_id');
            $table->unsignedBigInteger('location_id')->nullable();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->string('number')->nullable();
            $table->string('code')->nullable();
            $table->string('display_name')->nullable();
            $table->string('activity_type')->nullable();
            $table->decimal('area_sqm', 10, 2)->nullable();
            $table->decimal('rent_rate_value', 12, 2)->nullable();
            $table->string('rent_rate_unit')->nullable();
            $table->dateTime('rent_rate_updated_at')->nullable();
            $table->string('type')->nullable();
            $table->string('status')->default('free');
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::shouldReceive('hasTable')->andReturn(true);
        Schema::shouldReceive('hasColumn')->andReturn(true);
    }

    public function test_execute_creates_demo_market_and_is_idempotent(): void
    {
        $dataSet = app(DemoPilotDataBuilder::class)->build();
        $provisioner = app(DemoPilotProvisioner::class);

        $firstReport = $provisioner->execute($dataSet);
        $secondReport = $provisioner->execute($dataSet);

        self::assertSame('partial', $firstReport['status']);
        self::assertTrue($firstReport['writes_enabled']);
        self::assertSame('created', $this->sectionStatus($firstReport, 'market'));
        self::assertSame('created', $this->sectionStatus($firstReport, 'locations'));
        self::assertSame('created', $this->sectionStatus($firstReport, 'tenants'));
        self::assertSame('created', $this->sectionStatus($firstReport, 'spaces'));
        self::assertSame('partial', $secondReport['status']);
        self::assertSame('unchanged', $this->sectionStatus($secondReport, 'market'));
        self::assertSame('unchanged', $this->sectionStatus($secondReport, 'locations'));
        self::assertSame('unchanged', $this->sectionStatus($secondReport, 'tenants'));
        self::assertSame('unchanged', $this->sectionStatus($secondReport, 'spaces'));
        self::assertSame(1, Market::query()->where('slug', 'demo-market')->count());
        self::assertSame(2, MarketLocation::query()->count());
        self::assertSame(4, Tenant::query()->count());
        self::assertSame(5, MarketSpace::query()->count());
    }

    public function test_execute_updates_existing_demo_market(): void
    {
        Market::query()->create([
            'name' => 'Old Demo Market',
            'slug' => 'demo-market',
            'code' => 'DEMO_MARKET',
            'address' => 'Old address',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
            'settings' => [
                'demo_pilot' => [
                    'synthetic_source' => 'demo_pilot',
                ],
            ],
            'features' => [],
        ]);

        $report = app(DemoPilotProvisioner::class)->execute(
            app(DemoPilotDataBuilder::class)->build(),
        );

        $market = Market::query()->where('slug', 'demo-market')->firstOrFail();

        self::assertSame('partial', $report['status']);
        self::assertSame('updated', $this->sectionStatus($report, 'market'));
        self::assertSame('Demo Market', $market->name);
        self::assertSame('Demo address, 1', $market->address);
        self::assertTrue((bool) data_get($market->features, 'marketplace'));
        self::assertSame(2, MarketLocation::query()->where('market_id', $market->getKey())->count());
        self::assertSame(4, Tenant::query()->where('market_id', $market->getKey())->count());
        self::assertSame(5, MarketSpace::query()->where('market_id', $market->getKey())->count());
    }

    public function test_execute_updates_existing_demo_locations(): void
    {
        $market = Market::query()->create([
            'name' => 'Demo Market',
            'slug' => 'demo-market',
            'code' => 'DEMO_MARKET',
            'address' => 'Demo address, 1',
            'timezone' => 'Asia/Novosibirsk',
            'is_active' => true,
            'settings' => [
                'demo_pilot' => [
                    'synthetic_source' => 'demo_pilot',
                ],
            ],
            'features' => [],
        ]);

        MarketLocation::query()->create([
            'market_id' => $market->getKey(),
            'name' => 'Old Main Hall',
            'code' => 'main-hall',
            'type' => 'old',
            'sort_order' => 99,
            'is_active' => false,
        ]);

        $report = app(DemoPilotProvisioner::class)->execute(
            app(DemoPilotDataBuilder::class)->build(),
        );

        $location = MarketLocation::query()
            ->where('market_id', $market->getKey())
            ->where('code', 'main-hall')
            ->firstOrFail();

        self::assertSame('partial', $report['status']);
        self::assertSame('updated', $this->sectionStatus($report, 'locations'));
        self::assertSame('Main Hall', $location->name);
        self::assertSame('hall', $location->type);
        self::assertSame(10, $location->sort_order);
        self::assertTrue($location->is_active);
        self::assertSame(2, MarketLocation::query()->where('market_id', $market->getKey())->count());
    }

    public function test_execute_updates_existing_demo_tenants(): void
    {
        $market = $this->createDemoMarket();

        Tenant::query()->create([
            'market_id' => $market->getKey(),
            'name' => 'Old Grocery',
            'short_name' => 'Old Grocery',
            'slug' => 'demo-grocery-llc',
            'type' => 'llc',
            'external_id' => 'demo-tenant-grocery',
            'phone' => '+71111111111',
            'email' => 'old@example.test',
            'contact_person' => 'Old Contact',
            'status' => 'inactive',
            'is_active' => false,
            'notes' => 'old',
            'one_c_data' => [
                'synthetic_source' => 'demo_pilot',
                'live_1c' => false,
            ],
            'debt_status' => 'green',
        ]);

        $report = app(DemoPilotProvisioner::class)->execute(
            app(DemoPilotDataBuilder::class)->build(),
        );

        $tenant = Tenant::query()
            ->where('market_id', $market->getKey())
            ->where('external_id', 'demo-tenant-grocery')
            ->firstOrFail();

        self::assertSame('partial', $report['status']);
        self::assertSame('updated', $this->sectionStatus($report, 'tenants'));
        self::assertSame('Demo Grocery LLC', $tenant->name);
        self::assertSame('grocery@demo.marketuchet.local', $tenant->email);
        self::assertSame('active', $tenant->status);
        self::assertTrue($tenant->is_active);
        self::assertSame('orange', $tenant->debt_status);
        self::assertFalse((bool) data_get($tenant->one_c_data, 'live_1c'));
        self::assertSame(4, Tenant::query()->where('market_id', $market->getKey())->count());
    }

    public function test_execute_updates_existing_demo_spaces(): void
    {
        $market = $this->createDemoMarket();
        $location = $this->createDemoLocation($market, 'main-hall');
        $tenant = $this->createDemoTenant($market, 'tenant-grocery');

        MarketSpace::withoutEvents(static function () use ($market, $location, $tenant): void {
            MarketSpace::query()->create([
                'market_id' => $market->getKey(),
                'location_id' => $location->getKey(),
                'tenant_id' => $tenant->getKey(),
                'number' => 'OLD-A-02',
                'code' => 'a-02',
                'display_name' => 'Old grocery',
                'activity_type' => 'old',
                'area_sqm' => 1,
                'rent_rate_value' => 1,
                'rent_rate_unit' => 'old_unit',
                'type' => 'old',
                'status' => 'vacant',
                'is_active' => false,
                'notes' => 'old',
            ]);
        });

        $report = app(DemoPilotProvisioner::class)->execute(
            app(DemoPilotDataBuilder::class)->build(),
        );

        $space = MarketSpace::query()
            ->where('market_id', $market->getKey())
            ->where('code', 'a-02')
            ->firstOrFail();

        self::assertSame('partial', $report['status']);
        self::assertSame('updated', $this->sectionStatus($report, 'spaces'));
        self::assertSame('A-02', $space->number);
        self::assertSame('Grocery stall', $space->display_name);
        self::assertSame('retail', $space->activity_type);
        self::assertSame('22.00', (string) $space->area_sqm);
        self::assertSame('2400.00', (string) $space->rent_rate_value);
        self::assertSame('sqm_month', $space->rent_rate_unit);
        self::assertSame('occupied', $space->status);
        self::assertTrue($space->is_active);
        self::assertSame(5, MarketSpace::query()->where('market_id', $market->getKey())->count());
    }

    public function test_execute_blocks_ambiguous_existing_space_code(): void
    {
        $market = $this->createDemoMarket();
        $location = $this->createDemoLocation($market, 'main-hall');

        MarketSpace::withoutEvents(static function () use ($market, $location): void {
            foreach ([1, 2] as $suffix) {
                MarketSpace::query()->create([
                    'market_id' => $market->getKey(),
                    'location_id' => $location->getKey(),
                    'tenant_id' => null,
                    'number' => 'Duplicate ' . $suffix,
                    'code' => 'a-01',
                    'display_name' => 'Duplicate ' . $suffix,
                    'activity_type' => 'retail',
                    'area_sqm' => 10,
                    'rent_rate_value' => 100,
                    'rent_rate_unit' => 'sqm_month',
                    'type' => 'retail',
                    'status' => 'vacant',
                    'is_active' => true,
                    'notes' => 'duplicate',
                ]);
            }
        });

        $report = app(DemoPilotProvisioner::class)->execute(
            app(DemoPilotDataBuilder::class)->build(),
        );

        self::assertSame('blocked', $report['status']);
        self::assertContains('space code [a-01] matches multiple existing spaces', $report['issues']);
        self::assertSame(2, MarketSpace::query()->where('market_id', $market->getKey())->count());
    }

    public function test_execute_blocks_tenant_slug_conflict(): void
    {
        $market = $this->createDemoMarket();

        Tenant::query()->create([
            'market_id' => $market->getKey(),
            'name' => 'Conflicting Tenant',
            'short_name' => 'Conflicting Tenant',
            'slug' => 'demo-grocery-llc',
            'type' => 'llc',
            'external_id' => 'other-external-id',
            'phone' => '+70000000000',
            'email' => 'conflict@example.test',
            'contact_person' => 'Conflict',
            'status' => 'active',
            'is_active' => true,
            'one_c_data' => [],
            'debt_status' => 'green',
        ]);

        $report = app(DemoPilotProvisioner::class)->execute(
            app(DemoPilotDataBuilder::class)->build(),
        );

        self::assertSame('blocked', $report['status']);
        self::assertContains(
            'tenant slug [demo-grocery-llc] already belongs to another tenant',
            $report['issues'],
        );
        self::assertSame(1, Tenant::query()->count());
    }

    public function test_execute_does_not_overwrite_real_market_with_same_slug(): void
    {
        Market::query()->create([
            'name' => 'Real Market',
            'slug' => 'demo-market',
            'code' => 'DEMO_MARKET',
            'address' => 'Real address',
            'timezone' => 'Asia/Novosibirsk',
            'is_active' => true,
            'settings' => [],
            'features' => [],
        ]);

        $report = app(DemoPilotProvisioner::class)->execute(
            app(DemoPilotDataBuilder::class)->build(),
        );

        self::assertSame('blocked', $report['status']);
        self::assertFalse($report['writes_enabled']);
        self::assertContains(
            'existing market [demo-market] is not marked as demo/pilot synthetic data',
            $report['issues'],
        );
        self::assertSame('Real Market', Market::query()->where('slug', 'demo-market')->value('name'));
        self::assertSame(0, MarketLocation::query()->count());
        self::assertSame(0, Tenant::query()->count());
        self::assertSame(0, MarketSpace::query()->count());
    }

    public function test_execute_blocks_market_code_conflict(): void
    {
        Market::query()->create([
            'name' => 'Other Market',
            'slug' => 'other-market',
            'code' => 'DEMO_MARKET',
            'address' => 'Other address',
            'timezone' => 'Asia/Novosibirsk',
            'is_active' => true,
            'settings' => [],
            'features' => [],
        ]);

        $report = app(DemoPilotProvisioner::class)->execute(
            app(DemoPilotDataBuilder::class)->build(),
        );

        self::assertSame('blocked', $report['status']);
        self::assertContains(
            'market code [DEMO_MARKET] already belongs to another market',
            $report['issues'],
        );
        self::assertSame(1, Market::query()->count());
        self::assertSame(0, MarketLocation::query()->count());
        self::assertSame(0, Tenant::query()->count());
        self::assertSame(0, MarketSpace::query()->count());
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

        self::fail('Missing section [' . $section . '] in provisioner report.');
    }

    private function createDemoMarket(): Market
    {
        return Market::query()->create([
            'name' => 'Demo Market',
            'slug' => 'demo-market',
            'code' => 'DEMO_MARKET',
            'address' => 'Demo address, 1',
            'timezone' => 'Asia/Novosibirsk',
            'is_active' => true,
            'settings' => [
                'demo_pilot' => [
                    'synthetic_source' => 'demo_pilot',
                ],
            ],
            'features' => [],
        ]);
    }

    private function createDemoLocation(Market $market, string $code): MarketLocation
    {
        return MarketLocation::query()->create([
            'market_id' => $market->getKey(),
            'name' => $code === 'main-hall' ? 'Main Hall' : 'Food Court',
            'code' => $code,
            'type' => 'hall',
            'sort_order' => 10,
            'is_active' => true,
        ]);
    }

    private function createDemoTenant(Market $market, string $key): Tenant
    {
        $name = $key === 'tenant-grocery' ? 'Demo Grocery LLC' : 'Demo Tenant LLC';

        return Tenant::withoutEvents(static function () use ($market, $key, $name): Tenant {
            return Tenant::query()->create([
                'market_id' => $market->getKey(),
                'name' => $name,
                'short_name' => $name,
                'slug' => str($name)->slug()->toString(),
                'type' => 'llc',
                'external_id' => 'demo-' . $key,
                'phone' => '+70000000000',
                'email' => $key . '@example.test',
                'contact_person' => 'Demo Contact',
                'status' => 'active',
                'is_active' => true,
                'one_c_data' => [],
                'debt_status' => 'green',
            ]);
        });
    }
}
