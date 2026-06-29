<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\DemoPilotDataBuilder;
use App\Support\DemoPilotProvisioner;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class DemoPilotProvisionerTest extends TestCase
{
    public function test_preflight_is_ready_when_payload_references_and_schema_match(): void
    {
        $this->mockReadySchema();

        $report = app(DemoPilotProvisioner::class)->preflight(
            app(DemoPilotDataBuilder::class)->build(),
        );

        self::assertSame('ready', $report['status']);
        self::assertFalse($report['writes_enabled']);
        self::assertSame([], $report['issues']);
        self::assertCount(13, $report['sections']);
        self::assertSame('ready', $this->sectionStatus($report, 'integrations'));
    }

    public function test_preflight_blocks_enabled_external_integrations(): void
    {
        $this->mockReadySchema();

        config()->set('demo_pilot.external_integrations_enabled', true);
        $dataSet = app(DemoPilotDataBuilder::class)->build();
        $dataSet['metadata']['external_integrations_enabled'] = true;
        $dataSet['integrations']['one_c'] = 'enabled';

        $report = app(DemoPilotProvisioner::class)->preflight($dataSet);

        self::assertSame('blocked', $report['status']);
        self::assertSame('blocked', $this->sectionStatus($report, 'integrations'));
        self::assertContains('demo external integrations config flag must remain disabled', $report['issues']);
        self::assertContains('demo metadata external_integrations_enabled must be false', $report['issues']);
        self::assertContains('demo integration [1C] must be disabled', $report['issues']);
    }

    public function test_preflight_blocks_broken_payload_references(): void
    {
        $this->mockReadySchema();

        $dataSet = app(DemoPilotDataBuilder::class)->build();
        $dataSet['contracts'][0]['tenant_key'] = 'missing-tenant';

        $report = app(DemoPilotProvisioner::class)->preflight($dataSet);

        self::assertSame('blocked', $report['status']);
        self::assertContains(
            'contracts record [contract-produce] has invalid tenant_key [missing-tenant].',
            $report['issues'],
        );
    }

    public function test_preflight_blocks_missing_schema_columns(): void
    {
        Schema::shouldReceive('hasTable')->andReturn(true);
        Schema::shouldReceive('hasColumn')->andReturnUsing(
            static fn (string $table, string $column): bool => ! ($table === 'market_spaces' && $column === 'tenant_id'),
        );

        $report = app(DemoPilotProvisioner::class)->preflight(
            app(DemoPilotDataBuilder::class)->build(),
        );

        self::assertSame('blocked', $report['status']);
        self::assertContains('spaces: missing columns [tenant_id]', $report['issues']);
    }

    public function test_preflight_reports_schema_check_failures_without_throwing(): void
    {
        Schema::shouldReceive('hasTable')->andThrow(new RuntimeException('database unavailable'));

        $report = app(DemoPilotProvisioner::class)->preflight(
            app(DemoPilotDataBuilder::class)->build(),
        );

        self::assertSame('blocked', $report['status']);
        self::assertContains('market: schema check failed for [markets]: database unavailable', $report['issues']);
    }

    public function test_execute_blocks_when_preflight_has_issues_before_writes(): void
    {
        $this->mockReadySchema();

        $dataSet = app(DemoPilotDataBuilder::class)->build();
        $dataSet['contracts'][0]['tenant_key'] = 'missing-tenant';

        $report = app(DemoPilotProvisioner::class)->execute($dataSet);

        self::assertSame('blocked', $report['status']);
        self::assertFalse($report['writes_enabled']);
        self::assertContains(
            'contracts record [contract-produce] has invalid tenant_key [missing-tenant].',
            $report['issues'],
        );
    }

    public function test_execute_is_blocked_when_write_flags_are_disabled(): void
    {
        $this->mockReadySchema();

        $report = app(DemoPilotProvisioner::class)->execute(
            app(DemoPilotDataBuilder::class)->build(),
        );

        self::assertSame('blocked', $report['status']);
        self::assertFalse($report['writes_enabled']);
        self::assertContains(
            'Demo/pilot data write is disabled for operation [provision].',
            $report['issues'],
        );
    }

    private function mockReadySchema(): void
    {
        Schema::shouldReceive('hasTable')->andReturn(true);
        Schema::shouldReceive('hasColumn')->andReturn(true);
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
