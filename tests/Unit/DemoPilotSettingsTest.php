<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\DemoPilotSettings;
use LogicException;
use Tests\TestCase;

class DemoPilotSettingsTest extends TestCase
{
    public function test_demo_pilot_is_disabled_by_default(): void
    {
        $settings = app(DemoPilotSettings::class);

        self::assertFalse($settings->enabled());
        self::assertFalse($settings->provisionEnabled());
        self::assertFalse($settings->resetEnabled());
        self::assertFalse($settings->externalIntegrationsEnabled());
        self::assertFalse($settings->canWriteData(DemoPilotSettings::OPERATION_PROVISION));
        self::assertFalse($settings->canWriteData(DemoPilotSettings::OPERATION_RESET));
    }

    public function test_data_writes_require_global_and_operation_flags(): void
    {
        $settings = app(DemoPilotSettings::class);

        config()->set('demo_pilot.provision_enabled', true);
        self::assertFalse($settings->canWriteData(DemoPilotSettings::OPERATION_PROVISION));

        config()->set('demo_pilot.enabled', true);
        self::assertTrue($settings->canWriteData(DemoPilotSettings::OPERATION_PROVISION));
        self::assertFalse($settings->canWriteData(DemoPilotSettings::OPERATION_RESET));
    }

    public function test_production_data_writes_require_explicit_prod_barrier_flag(): void
    {
        $settings = app(DemoPilotSettings::class);

        config()->set('demo_pilot.enabled', true);
        config()->set('demo_pilot.provision_enabled', true);
        config()->set('demo_pilot.allow_production_data_writes', false);
        $this->app->detectEnvironment(fn (): string => 'production');

        self::assertFalse($settings->canWriteData(DemoPilotSettings::OPERATION_PROVISION));

        config()->set('demo_pilot.allow_production_data_writes', true);

        self::assertTrue($settings->canWriteData(DemoPilotSettings::OPERATION_PROVISION));
    }

    public function test_unknown_operations_are_not_allowed(): void
    {
        $settings = app(DemoPilotSettings::class);

        config()->set('demo_pilot.enabled', true);
        config()->set('demo_pilot.provision_enabled', true);

        self::assertFalse($settings->canWriteData('unknown'));
    }

    public function test_assert_data_write_allowed_throws_when_disabled(): void
    {
        $this->expectException(LogicException::class);

        app(DemoPilotSettings::class)->assertDataWriteAllowed(DemoPilotSettings::OPERATION_PROVISION);
    }

    public function test_defaults_are_stable_for_future_synthetic_records(): void
    {
        $settings = app(DemoPilotSettings::class);

        self::assertSame('demo-market', $settings->marketSlug());
        self::assertSame('demo.marketuchet.local', $settings->emailDomain());
        self::assertSame('demo_pilot', $settings->syntheticSource());

        config()->set('demo_pilot.market_slug', '  ');
        config()->set('demo_pilot.email_domain', '');
        config()->set('demo_pilot.synthetic_source', null);

        self::assertSame('demo-market', $settings->marketSlug());
        self::assertSame('demo.marketuchet.local', $settings->emailDomain());
        self::assertSame('demo_pilot', $settings->syntheticSource());
    }
}
