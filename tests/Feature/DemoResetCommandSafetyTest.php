<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\DemoPilotResetter;
use App\Support\DemoPilotSettings;
use Tests\TestCase;

class DemoResetCommandSafetyTest extends TestCase
{
    public function test_dry_run_outputs_plan_without_deletes(): void
    {
        $this->app->instance(DemoPilotResetter::class, $this->resetter('ready', false));

        $this->artisan('demo:reset --dry-run')
            ->expectsOutput('Demo/pilot reset plan')
            ->expectsOutput('Mode: dry-run')
            ->expectsOutput('Market slug: demo-market')
            ->expectsOutput('Email domain: demo.marketuchet.local')
            ->expectsOutput('External integrations: disabled')
            ->expectsOutput('Reset preflight: ready')
            ->expectsOutputToContain('DRY RUN: no demo records, users, tenants, spaces, contracts, finance records, files, or external integrations were deleted.')
            ->assertExitCode(0);
    }

    public function test_dry_run_accepts_safe_overrides(): void
    {
        $this->app->instance(DemoPilotResetter::class, $this->resetter('ready', false));

        $this->artisan('demo:reset --dry-run --market-slug=pilot-alpha --email-domain=pilot.example.test')
            ->expectsOutput('Market slug: pilot-alpha')
            ->expectsOutput('Email domain: pilot.example.test')
            ->assertExitCode(0);
    }

    public function test_execute_is_blocked_by_default(): void
    {
        $this->app->instance(DemoPilotResetter::class, $this->resetter('ready', false));

        $this->artisan('demo:reset --execute')
            ->expectsOutputToContain('Demo/pilot data write is disabled for operation [' . DemoPilotSettings::OPERATION_RESET . '].')
            ->assertExitCode(1);
    }

    public function test_dry_run_and_execute_cannot_be_combined(): void
    {
        $this->artisan('demo:reset --dry-run --execute')
            ->expectsOutput('Use either --execute or --dry-run, not both.')
            ->assertExitCode(1);
    }

    public function test_execute_runs_reset_adapter_when_flags_are_enabled(): void
    {
        config()->set('demo_pilot.enabled', true);
        config()->set('demo_pilot.reset_enabled', true);
        $this->app->instance(DemoPilotResetter::class, $this->resetter('reset', true));

        $this->artisan('demo:reset --execute')
            ->expectsOutput('Reset adapter write phase: enabled')
            ->expectsOutput('Reset write: reset')
            ->assertExitCode(0);
    }

    private function resetter(string $status, bool $writesEnabled): DemoPilotResetter
    {
        return new class($status, $writesEnabled) extends DemoPilotResetter
        {
            public function __construct(
                private readonly string $status,
                private readonly bool $writesEnabled,
            ) {
            }

            /**
             * @param array<string, mixed> $dataSet
             * @return array{status:string, writes_enabled:bool, market_id:int|null, sections:list<array{section:string, table:string, records:int, status:string, details:string}>, issues:list<string>}
             */
            public function plan(array $dataSet): array
            {
                return $this->report('ready', false, 'ready', 'all required columns exist');
            }

            /**
             * @param array<string, mixed> $dataSet
             * @return array{status:string, writes_enabled:bool, market_id:int|null, sections:list<array{section:string, table:string, records:int, status:string, details:string}>, issues:list<string>}
             */
            public function execute(array $dataSet): array
            {
                return $this->report($this->status, $this->writesEnabled, 'deleted', 'deleted [1] records');
            }

            /**
             * @return array{status:string, writes_enabled:bool, market_id:int|null, sections:list<array{section:string, table:string, records:int, status:string, details:string}>, issues:list<string>}
             */
            private function report(string $status, bool $writesEnabled, string $sectionStatus, string $details): array
            {
                return [
                    'status' => $status,
                    'writes_enabled' => $writesEnabled,
                    'market_id' => 1,
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
        };
    }
}
