<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Market;
use App\Models\MarketLocation;
use App\Models\MarketSpace;
use App\Models\Tenant;
use App\Models\TenantAccrual;
use App\Models\TenantContract;
use App\Models\TenantPayment;
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

        Schema::create('tenant_contracts', function (Blueprint $table): void {
            $table->id();
            $table->string('external_id')->nullable();
            $table->unsignedBigInteger('market_id');
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('market_space_id')->nullable();
            $table->string('number', 255);
            $table->string('status', 20);
            $table->date('starts_at');
            $table->date('ends_at')->nullable();
            $table->date('signed_at')->nullable();
            $table->decimal('monthly_rent', 12, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('space_mapping_mode', 20)->default('auto');
            $table->timestamp('space_mapping_updated_at')->nullable();
            $table->unsignedBigInteger('space_mapping_updated_by_user_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('tenant_accruals', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('market_id');
            $table->unsignedBigInteger('tenant_id');
            $table->string('contract_external_id')->nullable();
            $table->string('organization_external_id')->nullable();
            $table->string('organization_name')->nullable();
            $table->string('account', 64)->nullable();
            $table->string('document_external_id')->nullable();
            $table->string('document_number')->nullable();
            $table->date('document_date')->nullable();
            $table->text('document_name')->nullable();
            $table->string('service_name')->nullable();
            $table->text('line_description')->nullable();
            $table->text('purpose')->nullable();
            $table->unsignedBigInteger('tenant_contract_id')->nullable();
            $table->string('contract_link_status')->nullable();
            $table->string('contract_link_source')->nullable();
            $table->string('contract_link_note')->nullable();
            $table->unsignedBigInteger('market_space_id')->nullable();
            $table->date('period');
            $table->string('source_place_code')->nullable();
            $table->string('source_place_name')->nullable();
            $table->string('activity_type')->nullable();
            $table->decimal('area_sqm', 10, 2)->nullable();
            $table->decimal('rent_rate', 14, 2)->nullable();
            $table->integer('days')->nullable();
            $table->string('currency', 3)->default('RUB');
            $table->decimal('rent_amount', 14, 2)->nullable();
            $table->decimal('management_fee', 14, 2)->nullable();
            $table->decimal('utilities_amount', 14, 2)->nullable();
            $table->decimal('electricity_amount', 14, 2)->nullable();
            $table->decimal('total_no_vat', 14, 2)->nullable();
            $table->decimal('vat_rate', 6, 4)->nullable();
            $table->decimal('total_with_vat', 14, 2)->nullable();
            $table->text('discount_note')->nullable();
            $table->decimal('cash_amount', 14, 2)->nullable();
            $table->text('notes')->nullable();
            $table->string('status')->default('imported');
            $table->string('source')->default('excel');
            $table->string('source_file')->nullable();
            $table->unsignedInteger('source_row_number')->nullable();
            $table->char('source_row_hash', 64)->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();
        });

        Schema::create('tenant_payments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('market_id');
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('tenant_contract_id')->nullable();
            $table->string('tenant_external_id');
            $table->string('contract_external_id')->nullable();
            $table->string('payment_external_id')->nullable();
            $table->string('document_number')->nullable();
            $table->date('payment_date');
            $table->date('period');
            $table->string('organization_external_id')->nullable();
            $table->string('organization_name')->nullable();
            $table->string('account', 64)->nullable();
            $table->string('debit_account', 64)->nullable();
            $table->decimal('amount', 14, 2);
            $table->string('currency', 3)->default('RUB');
            $table->text('purpose')->nullable();
            $table->string('source')->default('1c');
            $table->string('source_file')->default('1c:payments');
            $table->json('payload')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->char('source_row_hash', 64);
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
        self::assertSame('created', $this->sectionStatus($firstReport, 'contracts'));
        self::assertSame('created', $this->sectionStatus($firstReport, 'accruals'));
        self::assertSame('created', $this->sectionStatus($firstReport, 'payments'));
        self::assertSame('partial', $secondReport['status']);
        self::assertSame('unchanged', $this->sectionStatus($secondReport, 'market'));
        self::assertSame('unchanged', $this->sectionStatus($secondReport, 'locations'));
        self::assertSame('unchanged', $this->sectionStatus($secondReport, 'tenants'));
        self::assertSame('unchanged', $this->sectionStatus($secondReport, 'spaces'));
        self::assertSame('unchanged', $this->sectionStatus($secondReport, 'contracts'));
        self::assertSame('unchanged', $this->sectionStatus($secondReport, 'accruals'));
        self::assertSame('unchanged', $this->sectionStatus($secondReport, 'payments'));
        self::assertSame(1, Market::query()->where('slug', 'demo-market')->count());
        self::assertSame(2, MarketLocation::query()->count());
        self::assertSame(4, Tenant::query()->count());
        self::assertSame(5, MarketSpace::query()->count());
        self::assertSame(4, TenantContract::query()->count());
        self::assertSame(4, TenantAccrual::query()->count());
        self::assertSame(3, TenantPayment::query()->count());
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
        self::assertSame(4, TenantContract::query()->where('market_id', $market->getKey())->count());
        self::assertSame(4, TenantAccrual::query()->where('market_id', $market->getKey())->count());
        self::assertSame(3, TenantPayment::query()->where('market_id', $market->getKey())->count());
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

    public function test_execute_updates_existing_demo_contracts(): void
    {
        $market = $this->createDemoMarket();
        $location = $this->createDemoLocation($market, 'main-hall');
        $tenant = $this->createDemoTenant($market, 'tenant-grocery');
        $space = $this->createDemoSpace($market, $location, $tenant, 'a-02');

        TenantContract::withoutEvents(static function () use ($market, $tenant, $space): void {
            TenantContract::query()->create([
                'external_id' => 'demo-contract-grocery',
                'market_id' => $market->getKey(),
                'tenant_id' => $tenant->getKey(),
                'market_space_id' => $space->getKey(),
                'number' => 'OLD-GROCERY',
                'status' => 'draft',
                'starts_at' => '2025-01-01',
                'ends_at' => '2025-12-31',
                'signed_at' => '2025-01-01',
                'monthly_rent' => 1,
                'currency' => 'USD',
                'is_active' => false,
                'space_mapping_mode' => 'auto',
                'notes' => 'old',
            ]);
        });

        $report = app(DemoPilotProvisioner::class)->execute(
            app(DemoPilotDataBuilder::class)->build(),
        );

        $contract = TenantContract::query()
            ->where('market_id', $market->getKey())
            ->where('external_id', 'demo-contract-grocery')
            ->firstOrFail();

        self::assertSame('partial', $report['status']);
        self::assertSame('updated', $this->sectionStatus($report, 'contracts'));
        self::assertSame('D-GROCERY', $contract->number);
        self::assertSame('active', $contract->status);
        self::assertSame('52800.00', (string) $contract->monthly_rent);
        self::assertSame('RUB', $contract->currency);
        self::assertTrue($contract->is_active);
        self::assertSame(TenantContract::SPACE_MAPPING_MODE_MANUAL, $contract->space_mapping_mode);
        self::assertSame(4, TenantContract::query()->where('market_id', $market->getKey())->count());
    }

    public function test_execute_blocks_ambiguous_existing_contract_external_id(): void
    {
        $market = $this->createDemoMarket();
        $location = $this->createDemoLocation($market, 'main-hall');
        $tenant = $this->createDemoTenant($market, 'tenant-produce');
        $space = $this->createDemoSpace($market, $location, $tenant, 'a-01');

        TenantContract::withoutEvents(static function () use ($market, $tenant, $space): void {
            foreach ([1, 2] as $suffix) {
                TenantContract::query()->create([
                    'external_id' => 'demo-contract-produce',
                    'market_id' => $market->getKey(),
                    'tenant_id' => $tenant->getKey(),
                    'market_space_id' => $space->getKey(),
                    'number' => 'Duplicate ' . $suffix,
                    'status' => 'active',
                    'starts_at' => '2026-01-01',
                    'ends_at' => null,
                    'signed_at' => '2025-12-15',
                    'monthly_rent' => 39775,
                    'currency' => 'RUB',
                    'is_active' => true,
                    'space_mapping_mode' => 'manual',
                    'notes' => 'duplicate',
                ]);
            }
        });

        $report = app(DemoPilotProvisioner::class)->execute(
            app(DemoPilotDataBuilder::class)->build(),
        );

        self::assertSame('blocked', $report['status']);
        self::assertContains('contract external_id [demo-contract-produce] matches multiple existing contracts', $report['issues']);
        self::assertSame(2, TenantContract::query()->where('market_id', $market->getKey())->count());
    }

    public function test_execute_updates_existing_demo_finance_records(): void
    {
        $market = $this->createDemoMarket();
        $location = $this->createDemoLocation($market, 'main-hall');
        $tenant = $this->createDemoTenant($market, 'tenant-grocery');
        $space = $this->createDemoSpace($market, $location, $tenant, 'a-02');
        $contract = $this->createDemoContract($market, $tenant, $space, 'contract-grocery');
        $accrualHash = $this->demoSourceRowHash('accruals', 'accrual-grocery');
        $paymentHash = $this->demoSourceRowHash('payments', 'payment-grocery');

        TenantAccrual::query()->create([
            'market_id' => $market->getKey(),
            'tenant_id' => $tenant->getKey(),
            'tenant_contract_id' => $contract->getKey(),
            'market_space_id' => $space->getKey(),
            'period' => '2026-06-01',
            'document_date' => '2026-06-30',
            'rent_amount' => 1,
            'management_fee' => 1,
            'utilities_amount' => 1,
            'electricity_amount' => 1,
            'total_no_vat' => 1,
            'vat_rate' => 1,
            'total_with_vat' => 1,
            'cash_amount' => 1,
            'source' => 'demo_pilot',
            'source_row_hash' => $accrualHash,
            'payload' => '{"old":true}',
            'imported_at' => '2026-06-01 00:00:00',
        ]);

        TenantPayment::query()->create([
            'market_id' => $market->getKey(),
            'tenant_id' => $tenant->getKey(),
            'tenant_contract_id' => $contract->getKey(),
            'tenant_external_id' => 'demo-tenant-grocery',
            'contract_external_id' => 'demo-contract-grocery',
            'payment_external_id' => 'demo-payment-grocery',
            'payment_date' => '2026-06-01',
            'period' => '2026-06-01',
            'amount' => 1,
            'currency' => 'RUB',
            'source' => 'demo_pilot',
            'source_file' => 'demo_pilot',
            'payload' => ['old' => true],
            'imported_at' => '2026-06-01 00:00:00',
            'source_row_hash' => $paymentHash,
        ]);

        $report = app(DemoPilotProvisioner::class)->execute(
            app(DemoPilotDataBuilder::class)->build(),
        );

        $accrual = TenantAccrual::query()
            ->where('market_id', $market->getKey())
            ->where('source_row_hash', $accrualHash)
            ->firstOrFail();
        $payment = TenantPayment::query()
            ->where('market_id', $market->getKey())
            ->where('source_row_hash', $paymentHash)
            ->firstOrFail();
        $accrualPayload = json_decode((string) $accrual->payload, true);

        self::assertSame('partial', $report['status']);
        self::assertSame('updated', $this->sectionStatus($report, 'accruals'));
        self::assertSame('updated', $this->sectionStatus($report, 'payments'));
        self::assertSame(52800.0, (float) $accrual->rent_amount);
        self::assertSame(9500.0, (float) $accrual->cash_amount);
        self::assertSame('demo_pilot', $accrual->source);
        self::assertFalse((bool) ($accrualPayload['live_1c'] ?? true));
        self::assertSame(43300.0, (float) $payment->amount);
        self::assertFalse((bool) data_get($payment->payload, 'live_1c'));
        self::assertSame(4, TenantAccrual::query()->where('market_id', $market->getKey())->count());
        self::assertSame(3, TenantPayment::query()->where('market_id', $market->getKey())->count());
    }

    public function test_execute_blocks_ambiguous_existing_accrual_hash(): void
    {
        $market = $this->createDemoMarket();
        $location = $this->createDemoLocation($market, 'main-hall');
        $tenant = $this->createDemoTenant($market, 'tenant-produce');
        $space = $this->createDemoSpace($market, $location, $tenant, 'a-01');
        $contract = $this->createDemoContract($market, $tenant, $space, 'contract-produce');
        $hash = $this->demoSourceRowHash('accruals', 'accrual-produce');

        foreach ([1, 2] as $suffix) {
            TenantAccrual::query()->create([
                'market_id' => $market->getKey(),
                'tenant_id' => $tenant->getKey(),
                'tenant_contract_id' => $contract->getKey(),
                'market_space_id' => $space->getKey(),
                'period' => '2026-06-01',
                'document_date' => '2026-06-30',
                'rent_amount' => $suffix,
                'management_fee' => 0,
                'utilities_amount' => 0,
                'electricity_amount' => 0,
                'total_no_vat' => $suffix,
                'vat_rate' => 0,
                'total_with_vat' => $suffix,
                'cash_amount' => 0,
                'source' => 'demo_pilot',
                'source_row_hash' => $hash,
                'payload' => '{"duplicate":true}',
                'imported_at' => '2026-06-01 00:00:00',
            ]);
        }

        $report = app(DemoPilotProvisioner::class)->execute(
            app(DemoPilotDataBuilder::class)->build(),
        );

        self::assertSame('blocked', $report['status']);
        self::assertContains('accrual source hash [' . $hash . '] matches multiple existing accruals', $report['issues']);
        self::assertSame('skipped', $this->sectionStatus($report, 'payments'));
        self::assertSame(2, TenantAccrual::query()->where('market_id', $market->getKey())->count());
        self::assertSame(0, TenantPayment::query()->where('market_id', $market->getKey())->count());
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
        self::assertSame(0, TenantContract::query()->count());
        self::assertSame(0, TenantAccrual::query()->count());
        self::assertSame(0, TenantPayment::query()->count());
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
        self::assertSame(0, TenantContract::query()->count());
        self::assertSame(0, TenantAccrual::query()->count());
        self::assertSame(0, TenantPayment::query()->count());
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

    private function createDemoSpace(Market $market, MarketLocation $location, Tenant $tenant, string $code): MarketSpace
    {
        return MarketSpace::withoutEvents(static function () use ($market, $location, $tenant, $code): MarketSpace {
            return MarketSpace::query()->create([
                'market_id' => $market->getKey(),
                'location_id' => $location->getKey(),
                'tenant_id' => $tenant->getKey(),
                'number' => strtoupper($code),
                'code' => $code,
                'display_name' => 'Demo Space ' . strtoupper($code),
                'activity_type' => 'retail',
                'area_sqm' => 10,
                'rent_rate_value' => 100,
                'rent_rate_unit' => 'sqm_month',
                'type' => 'retail',
                'status' => 'occupied',
                'is_active' => true,
                'notes' => 'demo',
            ]);
        });
    }

    private function createDemoContract(Market $market, Tenant $tenant, MarketSpace $space, string $key): TenantContract
    {
        return TenantContract::withoutEvents(static function () use ($market, $tenant, $space, $key): TenantContract {
            return TenantContract::query()->create([
                'external_id' => 'demo-' . $key,
                'market_id' => $market->getKey(),
                'tenant_id' => $tenant->getKey(),
                'market_space_id' => $space->getKey(),
                'number' => 'D-' . strtoupper(str_replace('contract-', '', $key)),
                'status' => 'active',
                'starts_at' => '2026-01-01',
                'ends_at' => null,
                'signed_at' => '2025-12-15',
                'monthly_rent' => 100,
                'currency' => 'RUB',
                'is_active' => true,
                'space_mapping_mode' => 'manual',
                'notes' => 'demo',
            ]);
        });
    }

    private function demoSourceRowHash(string $section, string $key): string
    {
        return hash('sha256', 'demo_pilot:' . $section . ':' . $key);
    }
}
