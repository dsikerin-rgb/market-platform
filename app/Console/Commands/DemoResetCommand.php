<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\DemoPilotDataBuilder;
use App\Support\DemoPilotResetter;
use App\Support\DemoPilotSettings;
use Illuminate\Console\Command;
use LogicException;

class DemoResetCommand extends Command
{
    protected $signature = 'demo:reset
        {--market-slug= : Demo market slug override}
        {--email-domain= : Demo user email domain override}
        {--dry-run : Show the reset plan without deleting data}
        {--execute : Delete synthetic demo/pilot records after safety flags are enabled}';

    protected $description = 'Plan or reset synthetic demo/pilot records with write-safe defaults';

    public function handle(DemoPilotSettings $settings, DemoPilotDataBuilder $builder, DemoPilotResetter $resetter): int
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
        $plan = $resetter->plan($dataSet);

        $this->line('Demo/pilot reset plan');
        $this->line('Mode: ' . ($dryRun ? 'dry-run' : 'execute'));
        $this->line('Market slug: ' . $dataSet['metadata']['market_slug']);
        $this->line('Email domain: ' . $dataSet['metadata']['email_domain']);
        $this->line('Synthetic source: ' . $settings->syntheticSource());
        $this->line('External integrations: ' . ($settings->externalIntegrationsEnabled() ? 'enabled' : 'disabled'));
        $this->line('Reset preflight: ' . $plan['status']);
        $this->table(['Section', 'Table', 'Records', 'Plan'], $this->sectionRows($plan['sections']));

        if ($dryRun) {
            if ($plan['issues'] !== []) {
                $this->warn('Reset issues:');

                foreach ($plan['issues'] as $issue) {
                    $this->warn('- ' . $issue);
                }

                return self::FAILURE;
            }

            $this->warn('DRY RUN: no demo records, users, tenants, spaces, contracts, finance records, files, or external integrations were deleted.');

            return self::SUCCESS;
        }

        try {
            $settings->assertDataWriteAllowed(DemoPilotSettings::OPERATION_RESET);
        } catch (LogicException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $execution = $resetter->execute($dataSet);

        $this->line('Reset adapter write phase: ' . ($execution['writes_enabled'] ? 'enabled' : 'disabled'));
        $this->line('Reset write: ' . $execution['status']);
        $this->table(['Section', 'Table', 'Deleted', 'Result'], $this->sectionRows($execution['sections']));

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
     * @param list<array{section:string, table:string, records:int, status:string, details:string}> $sections
     * @return list<array{0:string, 1:string, 2:int, 3:string}>
     */
    private function sectionRows(array $sections): array
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
