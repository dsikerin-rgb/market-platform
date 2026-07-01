<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class DemoPublicReadinessCommandSourceTest extends TestCase
{
    public function test_public_readiness_command_covers_demo_access_prerequisites(): void
    {
        $source = file_get_contents(app_path('Console/Commands/DemoPublicReadinessCommand.php'));

        self::assertIsString($source);
        self::assertStringContainsString("protected \$signature = 'demo:public-readiness", $source);
        self::assertStringContainsString("'market-owner-director'", $source);
        self::assertStringContainsString("'market-admin'", $source);
        self::assertStringContainsString("'demo-market-admin'", $source);
        self::assertStringContainsString("data_get(\$market->settings, 'demo_pilot.synthetic_source')", $source);
        self::assertStringContainsString("data_get(\$user->notification_preferences, 'demo_pilot.synthetic_source')", $source);
    }

    public function test_public_readiness_command_keeps_rollout_guards_read_only(): void
    {
        $source = file_get_contents(app_path('Console/Commands/DemoPublicReadinessCommand.php'));

        self::assertIsString($source);
        self::assertStringContainsString('externalIntegrationsEnabled()', $source);
        self::assertStringContainsString("config('demo_pilot.allow_production_data_writes', false)", $source);
        self::assertStringContainsString('provisionEnabled()', $source);
        self::assertStringContainsString('resetEnabled()', $source);
        self::assertStringContainsString('accessPasswordIssue()', $source);
        self::assertStringNotContainsString('accessPassword()', $source);
    }
}
