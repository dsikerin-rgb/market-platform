<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\DemoPilotSettings;
use Illuminate\Console\Command;
use LogicException;

class DemoProvisionCommand extends Command
{
    protected $signature = 'demo:provision
        {--market-slug= : Demo market slug override}
        {--email-domain= : Demo user email domain override}
        {--dry-run : Show the provisioning plan without writing data}
        {--execute : Apply demo provisioning changes after safety flags are enabled}';

    protected $description = 'Plan demo/pilot market provisioning with write-safe defaults';

    public function handle(DemoPilotSettings $settings): int
    {
        $execute = (bool) $this->option('execute');
        $dryRun = ! $execute || (bool) $this->option('dry-run');

        if ($execute && (bool) $this->option('dry-run')) {
            $this->error('Use either --execute or --dry-run, not both.');

            return self::FAILURE;
        }

        $marketSlug = $this->normalizedOption('market-slug', $settings->marketSlug());
        $emailDomain = $this->normalizedOption('email-domain', $settings->emailDomain());

        $this->line('Demo/pilot provisioning plan');
        $this->line('Mode: ' . ($dryRun ? 'dry-run' : 'execute'));
        $this->line('Market slug: ' . $marketSlug);
        $this->line('Email domain: ' . $emailDomain);
        $this->line('Synthetic source: ' . $settings->syntheticSource());
        $this->line('External integrations: ' . ($settings->externalIntegrationsEnabled() ? 'enabled' : 'disabled'));

        $this->table(['Step', 'Planned action'], $this->planRows($marketSlug, $emailDomain));

        if ($dryRun) {
            $this->warn('DRY RUN: no markets, users, tenants, spaces, contracts, finance records, files, or external integrations were changed.');

            return self::SUCCESS;
        }

        try {
            $settings->assertDataWriteAllowed(DemoPilotSettings::OPERATION_PROVISION);
        } catch (LogicException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->error('Execute mode is not implemented yet. Keep using --dry-run until the data-write package is reviewed.');

        return self::FAILURE;
    }

    private function normalizedOption(string $name, string $fallback): string
    {
        $value = trim((string) ($this->option($name) ?? ''));

        return $value !== '' ? $value : $fallback;
    }

    /**
     * @return list<array{0:string, 1:string}>
     */
    private function planRows(string $marketSlug, string $emailDomain): array
    {
        return [
            ['market', 'Create or reuse synthetic market [' . $marketSlug . '] with demo metadata.'],
            ['roles', 'Prepare director, admin, operator, and tenant demo users under [' . $emailDomain . '].'],
            ['spaces', 'Create synthetic locations, rows, spaces, and occupancy signals.'],
            ['tenants', 'Create synthetic tenants, contacts, contracts, and bindings to spaces.'],
            ['finance', 'Create synthetic accrual/payment/debt records clearly marked as demo data.'],
            ['marketplace', 'Attach demo marketplace categories, products, announcements, and safe local assets.'],
            ['integrations', 'Keep live 1C, mail, Telegram, and other external integrations disabled.'],
        ];
    }
}
