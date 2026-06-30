<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\DemoPilotDataBuilder;
use App\Support\DemoPilotProvisioner;
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

    public function handle(DemoPilotSettings $settings, DemoPilotDataBuilder $builder, DemoPilotProvisioner $provisioner): int
    {
        $execute = (bool) $this->option('execute');
        $dryRun = ! $execute || (bool) $this->option('dry-run');

        if ($execute && (bool) $this->option('dry-run')) {
            $this->error('Use either --execute or --dry-run, not both.');

            return self::FAILURE;
        }

        $marketSlug = $this->normalizedOption('market-slug', $settings->marketSlug());
        $emailDomain = $this->normalizedOption('email-domain', $settings->emailDomain());
        $dataSet = $builder->build($marketSlug, $emailDomain);

        $this->line('Demo/pilot provisioning plan');
        $this->line('Mode: ' . ($dryRun ? 'dry-run' : 'execute'));
        $this->line('Market slug: ' . $dataSet['metadata']['market_slug']);
        $this->line('Email domain: ' . $dataSet['metadata']['email_domain']);
        $this->line('Synthetic source: ' . $settings->syntheticSource());
        $this->line('External integrations: ' . ($settings->externalIntegrationsEnabled() ? 'enabled' : 'disabled'));
        $this->line('Demo access password: ' . ($settings->accessPassword() === null ? 'random per user; reset required' : 'configured via DEMO_PILOT_ACCESS_PASSWORD'));
        $this->line('Demo access owner emails: ' . ($settings->ownerEmails() === [] ? 'not configured' : implode(', ', $settings->ownerEmails())));

        $this->table(['Section', 'Records'], $this->countRows($builder->counts($dataSet)));
        $this->table(['Step', 'Planned action'], $this->planRows($dataSet));
        $this->table(['Role', 'Name', 'Email', 'Password'], $this->accessRows($dataSet, $settings));

        $preflight = $provisioner->preflight($dataSet);

        $this->line('Provisioning preflight: ' . $preflight['status']);
        $this->table(['Section', 'Table', 'Records', 'Schema'], $this->preflightRows($preflight['sections']));

        if ($dryRun) {
            if ($preflight['issues'] !== []) {
                $this->warn('Preflight issues:');

                foreach ($preflight['issues'] as $issue) {
                    $this->warn('- ' . $issue);
                }

                return self::FAILURE;
            }

            $this->warn('DRY RUN: no markets, users, tenants, spaces, map shapes, contracts, finance records, files, or external integrations were changed.');

            return self::SUCCESS;
        }

        try {
            $settings->assertDataWriteAllowed(DemoPilotSettings::OPERATION_PROVISION);
        } catch (LogicException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $execution = $provisioner->execute($dataSet);

        $this->line('Execute adapter write phase: ' . ($execution['writes_enabled'] ? 'enabled' : 'disabled'));
        $this->line('Provisioning write: ' . $execution['status']);
        $this->table(['Section', 'Table', 'Records', 'Result'], $this->preflightRows($execution['sections']));

        foreach ($execution['issues'] as $issue) {
            $this->error($issue);
        }

        return $execution['issues'] === [] ? self::SUCCESS : self::FAILURE;
    }

    private function normalizedOption(string $name, string $fallback): string
    {
        $value = trim((string) ($this->option($name) ?? ''));

        return $value !== '' ? $value : $fallback;
    }

    /**
     * @param array<string, mixed> $dataSet
     * @return list<array{0:string, 1:string}>
     */
    private function planRows(array $dataSet): array
    {
        return [
            ['market', 'Create or reuse synthetic market [' . $dataSet['metadata']['market_slug'] . '] with demo metadata.'],
            ['roles', 'Prepare director, admin, operator, and tenant demo users under [' . $dataSet['metadata']['email_domain'] . '].'],
            ['spaces', 'Create synthetic locations, rows, spaces, and occupancy signals.'],
            ['map_shapes', 'Create simple demo map shapes bound to synthetic market spaces.'],
            ['tenants', 'Create synthetic tenants, contacts, contracts, and bindings to spaces.'],
            ['finance', 'Create synthetic accrual/payment/debt records clearly marked as demo data.'],
            ['marketplace', 'Attach demo marketplace categories, products, announcements, and safe local assets.'],
            ['integrations', 'Keep live 1C, mail, Telegram, and other external integrations disabled.'],
        ];
    }

    /**
     * @param array<string, mixed> $dataSet
     * @return list<array{0:string, 1:string, 2:string, 3:string}>
     */
    private function accessRows(array $dataSet, DemoPilotSettings $settings): array
    {
        $records = is_array($dataSet['users'] ?? null) ? $dataSet['users'] : [];
        $passwordStatus = $settings->accessPassword() === null
            ? 'random; reset required'
            : 'configured shared password';
        $rows = [];

        foreach ($records as $record) {
            if (! is_array($record)) {
                continue;
            }

            $rows[] = [
                $this->roleLabel((string) ($record['role'] ?? '')),
                trim((string) ($record['name'] ?? '')),
                mb_strtolower(trim((string) ($record['email'] ?? '')), 'UTF-8'),
                $passwordStatus,
            ];
        }

        return $rows;
    }

    private function roleLabel(string $role): string
    {
        return match (trim($role)) {
            'market-owner-director', 'director' => 'director',
            'market-admin', 'admin' => 'admin',
            'market-operator', 'operator' => 'operator',
            'merchant', 'tenant' => 'tenant',
            default => trim($role),
        };
    }

    /**
     * @param array<string, int> $counts
     * @return list<array{0:string, 1:int}>
     */
    private function countRows(array $counts): array
    {
        $rows = [];

        foreach ($counts as $section => $count) {
            $rows[] = [$section, $count];
        }

        return $rows;
    }

    /**
     * @param list<array{section:string, table:string, records:int, status:string, details:string}> $sections
     * @return list<array{0:string, 1:string, 2:int, 3:string}>
     */
    private function preflightRows(array $sections): array
    {
        return array_map(
            static fn (array $section): array => [
                $section['section'],
                $section['table'],
                $section['records'],
                $section['status'] . ': ' . $section['details'],
            ],
            $sections,
        );
    }
}
