<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\DemoPilotSettings;
use Illuminate\Console\Command;

class DemoFlagsAuditCommand extends Command
{
    protected $signature = 'demo:flags-audit';

    protected $description = 'Audit demo/pilot flags without printing secrets or writing data';

    public function handle(DemoPilotSettings $settings): int
    {
        $rows = [
            ['DEMO_PILOT_ENABLED', $this->enabledLabel($settings->enabled()), 'Base demo/pilot contour gate'],
            ['DEMO_PILOT_PROVISION_ENABLED', $this->enabledLabel($settings->provisionEnabled()), 'Allows demo:provision writes when base gate is enabled'],
            ['DEMO_PILOT_RESET_ENABLED', $this->enabledLabel($settings->resetEnabled()), 'Allows demo:reset writes when base gate is enabled'],
            ['DEMO_PILOT_ALLOW_PROD_WRITES', $this->enabledLabel((bool) config('demo_pilot.allow_production_data_writes', false)), 'Must stay disabled except for an approved production data operation'],
            ['DEMO_PILOT_PUBLIC_LOGIN_ENABLED', $this->enabledLabel($settings->publicLoginEnabled()), 'Allows /demo public entry into the synthetic demo user'],
            ['DEMO_PILOT_EXTERNAL_INTEGRATIONS_ENABLED', $this->enabledLabel($settings->externalIntegrationsEnabled()), 'Must stay disabled for synthetic demo data'],
            ['DEMO_PILOT_ACCESS_PASSWORD', $settings->accessPassword() === null ? 'missing' : 'configured', 'Secret value is intentionally not printed'],
            ['DEMO_PILOT_PUBLIC_LOGIN_EMAIL', $settings->publicLoginEmail(), 'Resolved public demo user email'],
            ['DEMO_PILOT_PUBLIC_LOGIN_REDIRECT', $settings->publicLoginRedirectPath(), 'Resolved public demo redirect path'],
        ];

        $this->line('Demo/pilot flag audit');
        $this->line('Environment: ' . app()->environment());
        $this->line('Market slug: ' . $settings->marketSlug());
        $this->line('Synthetic source: ' . $settings->syntheticSource());
        $this->table(['Flag/config', 'State', 'Meaning'], $rows);

        $issues = $this->issues($settings);

        if ($issues === []) {
            $this->info('Demo/pilot flags are rollback-ready.');

            return self::SUCCESS;
        }

        $this->warn('Demo/pilot flag issues:');

        foreach ($issues as $issue) {
            $this->warn('- ' . $issue);
        }

        return self::FAILURE;
    }

    private function enabledLabel(bool $enabled): string
    {
        return $enabled ? 'enabled' : 'disabled';
    }

    /**
     * @return list<string>
     */
    private function issues(DemoPilotSettings $settings): array
    {
        $issues = [];

        if ($settings->accessPasswordIssue() !== null) {
            $issues[] = $settings->accessPasswordIssue();
        }

        if ($settings->publicLoginEnabled() && $settings->externalIntegrationsEnabled()) {
            $issues[] = 'public demo login is enabled while external demo integrations are enabled.';
        }

        if ($settings->publicLoginEnabled() && (bool) config('demo_pilot.allow_production_data_writes', false)) {
            $issues[] = 'public demo login is enabled while production demo writes are allowed.';
        }

        if (app()->environment('production') && $settings->externalIntegrationsEnabled()) {
            $issues[] = 'external demo integrations must stay disabled in production.';
        }

        return $issues;
    }
}
