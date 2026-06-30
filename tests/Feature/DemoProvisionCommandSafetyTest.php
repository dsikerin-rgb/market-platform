<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\DemoPilotSettings;
use App\Support\DemoPilotProvisioner;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DemoProvisionCommandSafetyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::shouldReceive('hasTable')->andReturn(true);
        Schema::shouldReceive('hasColumn')->andReturn(true);
    }

    public function test_dry_run_outputs_plan_without_writes(): void
    {
        $this->artisan('demo:provision --dry-run')
            ->expectsOutput('Demo/pilot provisioning plan')
            ->expectsOutput('Mode: dry-run')
            ->expectsOutput('Market slug: demo-market')
            ->expectsOutput('Email domain: demo.marketuchet.local')
            ->expectsOutput('External integrations: disabled')
            ->expectsOutput('Demo access password: random per user; reset required')
            ->expectsOutput('Demo access owner emails: 321_123@bk.ru')
            ->expectsOutputToContain('marketplace_products')
            ->expectsOutputToContain('announcements')
            ->expectsOutput('Provisioning preflight: ready')
            ->expectsOutputToContain('DRY RUN: no markets, users, tenants, spaces, map shapes, contracts, finance records, files, or external integrations were changed.')
            ->assertExitCode(0);
    }

    public function test_dry_run_does_not_print_configured_access_password(): void
    {
        config()->set('demo_pilot.access_password', 'DemoAccess-2026!');
        config()->set('demo_pilot.owner_emails', '321_123@bk.ru, owner@example.test');

        $this->artisan('demo:provision --dry-run')
            ->expectsOutput('Demo access password: configured via DEMO_PILOT_ACCESS_PASSWORD')
            ->expectsOutput('Demo access owner emails: 321_123@bk.ru, owner@example.test')
            ->doesntExpectOutputToContain('DemoAccess-2026!')
            ->assertExitCode(0);
    }

    public function test_dry_run_accepts_safe_overrides(): void
    {
        $this->artisan('demo:provision --dry-run --market-slug=pilot-alpha --email-domain=pilot.example.test')
            ->expectsOutput('Market slug: pilot-alpha')
            ->expectsOutput('Email domain: pilot.example.test')
            ->assertExitCode(0);
    }

    public function test_execute_is_blocked_by_default(): void
    {
        $this->artisan('demo:provision --execute')
            ->expectsOutputToContain('Demo/pilot data write is disabled for operation [' . DemoPilotSettings::OPERATION_PROVISION . '].')
            ->assertExitCode(1);
    }

    public function test_execute_is_blocked_when_configured_access_password_is_too_short(): void
    {
        config()->set('demo_pilot.enabled', true);
        config()->set('demo_pilot.provision_enabled', true);
        config()->set('demo_pilot.access_password', 'short');

        $this->artisan('demo:provision --execute')
            ->expectsOutput('Provisioning preflight: blocked')
            ->expectsOutputToContain('demo access password must be at least 12 characters when configured.')
            ->assertExitCode(1);
    }

    public function test_dry_run_and_execute_cannot_be_combined(): void
    {
        $this->artisan('demo:provision --dry-run --execute')
            ->expectsOutput('Use either --execute or --dry-run, not both.')
            ->assertExitCode(1);
    }

    public function test_execute_runs_first_write_adapter_when_flags_are_enabled(): void
    {
        config()->set('demo_pilot.enabled', true);
        config()->set('demo_pilot.provision_enabled', true);
        $this->app->instance(DemoPilotProvisioner::class, new class extends DemoPilotProvisioner
        {
            /**
             * @param array<string, mixed> $dataSet
             * @return array{status:string, writes_enabled:bool, sections:list<array{section:string, table:string, records:int, status:string, details:string}>, issues:list<string>}
             */
            public function preflight(array $dataSet): array
            {
                return $this->report('ready', false, 'ready', 'all required columns exist');
            }

            /**
             * @param array<string, mixed> $dataSet
             * @return array{status:string, writes_enabled:bool, sections:list<array{section:string, table:string, records:int, status:string, details:string}>, issues:list<string>}
             */
            public function execute(array $dataSet): array
            {
                return $this->report('partial', true, 'created', 'created market id [1] for slug [demo-market]');
            }

            /**
             * @return array{status:string, writes_enabled:bool, sections:list<array{section:string, table:string, records:int, status:string, details:string}>, issues:list<string>}
             */
            private function report(string $status, bool $writesEnabled, string $sectionStatus, string $details): array
            {
                return [
                    'status' => $status,
                    'writes_enabled' => $writesEnabled,
                    'sections' => [
                        [
                            'section' => 'market',
                            'table' => 'markets',
                            'records' => 1,
                            'status' => $sectionStatus,
                            'details' => $details,
                        ],
                    ],
                    'issues' => [],
                ];
            }
        });

        $this->artisan('demo:provision --execute')
            ->expectsOutput('Execute adapter write phase: enabled')
            ->expectsOutput('Provisioning write: partial')
            ->assertExitCode(0);
    }
}
