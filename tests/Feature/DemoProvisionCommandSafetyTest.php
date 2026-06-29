<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\DemoPilotSettings;
use Tests\TestCase;

class DemoProvisionCommandSafetyTest extends TestCase
{
    public function test_dry_run_outputs_plan_without_writes(): void
    {
        $this->artisan('demo:provision --dry-run')
            ->expectsOutput('Demo/pilot provisioning plan')
            ->expectsOutput('Mode: dry-run')
            ->expectsOutput('Market slug: demo-market')
            ->expectsOutput('Email domain: demo.marketuchet.local')
            ->expectsOutput('External integrations: disabled')
            ->expectsOutputToContain('marketplace_products')
            ->expectsOutputToContain('announcements')
            ->expectsOutputToContain('DRY RUN: no markets, users, tenants, spaces, contracts, finance records, files, or external integrations were changed.')
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

    public function test_dry_run_and_execute_cannot_be_combined(): void
    {
        $this->artisan('demo:provision --dry-run --execute')
            ->expectsOutput('Use either --execute or --dry-run, not both.')
            ->assertExitCode(1);
    }

    public function test_execute_still_fails_until_write_implementation_exists(): void
    {
        config()->set('demo_pilot.enabled', true);
        config()->set('demo_pilot.provision_enabled', true);

        $this->artisan('demo:provision --execute')
            ->expectsOutput('Execute mode is not implemented yet. Keep using --dry-run until the data-write package is reviewed.')
            ->assertExitCode(1);
    }
}
