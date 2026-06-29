<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Market;
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
        self::assertSame('partial', $secondReport['status']);
        self::assertSame('unchanged', $this->sectionStatus($secondReport, 'market'));
        self::assertSame(1, Market::query()->where('slug', 'demo-market')->count());
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
}
