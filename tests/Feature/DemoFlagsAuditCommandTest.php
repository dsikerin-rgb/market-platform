<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class DemoFlagsAuditCommandTest extends TestCase
{
    public function test_flags_audit_does_not_print_configured_password(): void
    {
        config()->set('demo_pilot.access_password', 'Secret-Demo-Password-123');
        config()->set('demo_pilot.public_login_enabled', false);
        config()->set('demo_pilot.external_integrations_enabled', false);
        config()->set('demo_pilot.allow_production_data_writes', false);

        $exitCode = Artisan::call('demo:flags-audit');
        $output = Artisan::output();

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('DEMO_PILOT_ACCESS_PASSWORD', $output);
        self::assertStringContainsString('configured', $output);
        self::assertStringNotContainsString('Secret-Demo-Password-123', $output);
    }

    public function test_flags_audit_fails_when_public_login_and_external_integrations_are_enabled(): void
    {
        config()->set('demo_pilot.public_login_enabled', true);
        config()->set('demo_pilot.external_integrations_enabled', true);

        $exitCode = Artisan::call('demo:flags-audit');
        $output = Artisan::output();

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('public demo login is enabled while external demo integrations are enabled.', $output);
    }
}
