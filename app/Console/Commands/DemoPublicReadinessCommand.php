<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Market;
use App\Models\User;
use App\Support\DemoPilotSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Throwable;

class DemoPublicReadinessCommand extends Command
{
    /**
     * @var list<string>
     */
    private const PUBLIC_DEMO_ROLES = [
        'market-owner-director',
        'market-admin',
        'demo-market-admin',
    ];

    protected $signature = 'demo:public-readiness {--json : Output machine-readable JSON}';

    protected $description = 'Check public demo login readiness without writing data or printing secrets';

    public function handle(DemoPilotSettings $settings): int
    {
        $checks = [];

        $market = $this->checkMarket($settings, $checks);
        $this->checkPublicUser($settings, $market, $checks);
        $this->checkPublicLoginSettings($settings, $checks);
        $this->checkWriteAndIntegrationFlags($settings, $checks);

        $failed = collect($checks)->contains(static fn (array $check): bool => $check['status'] === 'fail');
        $status = $failed ? 'not_ready' : ($settings->publicLoginEnabled() ? 'ready_enabled' : 'ready_to_enable');

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode([
                'status' => $status,
                'environment' => app()->environment(),
                'market_slug' => $settings->marketSlug(),
                'public_login_email' => $settings->publicLoginEmail(),
                'checks' => $checks,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $failed ? self::FAILURE : self::SUCCESS;
        }

        $this->line('Public demo readiness');
        $this->line('Environment: ' . app()->environment());
        $this->line('Market slug: ' . $settings->marketSlug());
        $this->line('Public demo user: ' . $settings->publicLoginEmail());
        $this->table(['Check', 'Status', 'Details'], $this->rows($checks));

        if ($failed) {
            $this->error('Public demo is not ready. Fix failed checks before enabling the public login flag.');

            return self::FAILURE;
        }

        if ($settings->publicLoginEnabled()) {
            $this->info('Public demo is enabled and passes readiness checks.');

            return self::SUCCESS;
        }

        $this->info('Public demo is ready to enable after an approved flag change.');

        return self::SUCCESS;
    }

    /**
     * @param list<array{name:string, status:string, details:string}> $checks
     */
    private function checkMarket(DemoPilotSettings $settings, array &$checks): ?Market
    {
        if (! $this->hasTable('markets', $checks)) {
            return null;
        }

        try {
            $market = Market::query()
                ->where('slug', $settings->marketSlug())
                ->first();
        } catch (Throwable) {
            $this->addCheck($checks, 'demo market', 'fail', 'market lookup failed; check database connection and schema.');

            return null;
        }

        if (! $market instanceof Market) {
            $this->addCheck($checks, 'demo market', 'fail', 'market with configured slug was not found.');

            return null;
        }

        $source = data_get($market->settings, 'demo_pilot.synthetic_source');
        if ($source !== $settings->syntheticSource()) {
            $this->addCheck($checks, 'demo market source', 'fail', 'market is not marked as the configured synthetic demo source.');
        } else {
            $this->addCheck($checks, 'demo market source', 'ok', 'market is marked as synthetic demo data.');
        }

        if ((bool) $market->is_active) {
            $this->addCheck($checks, 'demo market active', 'ok', 'market is active.');
        } else {
            $this->addCheck($checks, 'demo market active', 'fail', 'market is inactive; public demo users may not be able to work normally.');
        }

        $this->addCheck(
            $checks,
            'demo market content',
            'ok',
            sprintf('market id=%d, spaces=%d, users=%d.', (int) $market->id, $this->safeCount($market, 'spaces'), $this->safeCount($market, 'users')),
        );

        return $market;
    }

    /**
     * @param list<array{name:string, status:string, details:string}> $checks
     */
    private function checkPublicUser(DemoPilotSettings $settings, ?Market $market, array &$checks): void
    {
        if (! $this->hasTable('users', $checks)) {
            return;
        }

        try {
            $user = User::query()
                ->whereRaw('LOWER(email) = ?', [$settings->publicLoginEmail()])
                ->first();
        } catch (Throwable) {
            $this->addCheck($checks, 'public demo user', 'fail', 'user lookup failed; check database connection and schema.');

            return;
        }

        if (! $user instanceof User) {
            $this->addCheck($checks, 'public demo user', 'fail', 'configured public demo user was not found.');

            return;
        }

        $this->addCheck($checks, 'public demo user', 'ok', sprintf('user id=%d was found.', (int) $user->id));

        if ($market instanceof Market && (int) ($user->market_id ?? 0) !== (int) $market->id) {
            $this->addCheck($checks, 'public demo user market', 'fail', 'user is attached to a different market.');
        } elseif ($market instanceof Market) {
            $this->addCheck($checks, 'public demo user market', 'ok', 'user is attached to the demo market.');
        }

        $source = data_get($user->notification_preferences, 'demo_pilot.synthetic_source');
        if ($source !== null && $source !== '' && $source !== $settings->syntheticSource()) {
            $this->addCheck($checks, 'public demo user source', 'fail', 'user synthetic source marker does not match configured demo source.');
        } else {
            $this->addCheck($checks, 'public demo user source', 'ok', 'user source marker is compatible with public demo.');
        }

        if ($this->hasAllowedRole($user)) {
            $this->addCheck($checks, 'public demo user role', 'ok', 'user has a role that can open the admin demo.');
        } else {
            $this->addCheck($checks, 'public demo user role', 'fail', 'user must have market-owner-director, market-admin, or demo-market-admin role.');
        }
    }

    /**
     * @param list<array{name:string, status:string, details:string}> $checks
     */
    private function checkPublicLoginSettings(DemoPilotSettings $settings, array &$checks): void
    {
        $this->addCheck(
            $checks,
            'public login flag',
            $settings->publicLoginEnabled() ? 'ok' : 'warn',
            $settings->publicLoginEnabled()
                ? 'DEMO_PILOT_PUBLIC_LOGIN_ENABLED is enabled.'
                : 'DEMO_PILOT_PUBLIC_LOGIN_ENABLED is disabled; safe state before an approved rollout.',
        );

        $this->addCheck($checks, 'public login redirect', 'ok', 'redirect path is ' . $settings->publicLoginRedirectPath() . '.');

        $passwordIssue = $settings->accessPasswordIssue();
        if ($passwordIssue !== null) {
            $this->addCheck($checks, 'demo access password', 'fail', $passwordIssue);

            return;
        }

        $this->addCheck($checks, 'demo access password', 'ok', 'no unsafe password configuration detected; secret value is not printed.');
    }

    /**
     * @param list<array{name:string, status:string, details:string}> $checks
     */
    private function checkWriteAndIntegrationFlags(DemoPilotSettings $settings, array &$checks): void
    {
        $this->addCheck(
            $checks,
            'external integrations',
            $settings->externalIntegrationsEnabled() ? 'fail' : 'ok',
            $settings->externalIntegrationsEnabled()
                ? 'external demo integrations must be disabled before public demo access.'
                : 'external demo integrations are disabled.',
        );

        $allowProdWrites = (bool) config('demo_pilot.allow_production_data_writes', false);
        $this->addCheck(
            $checks,
            'production demo writes',
            $allowProdWrites ? 'fail' : 'ok',
            $allowProdWrites
                ? 'DEMO_PILOT_ALLOW_PROD_WRITES must be disabled before public demo access.'
                : 'production demo writes are disabled.',
        );

        $this->addCheck(
            $checks,
            'demo provision flag',
            $settings->provisionEnabled() ? 'fail' : 'ok',
            $settings->provisionEnabled()
                ? 'DEMO_PILOT_PROVISION_ENABLED must be disabled before public demo access.'
                : 'demo provision writes are disabled.',
        );

        $this->addCheck(
            $checks,
            'demo reset flag',
            $settings->resetEnabled() ? 'fail' : 'ok',
            $settings->resetEnabled()
                ? 'DEMO_PILOT_RESET_ENABLED must be disabled before public demo access.'
                : 'demo reset writes are disabled.',
        );
    }

    /**
     * @param list<array{name:string, status:string, details:string}> $checks
     */
    private function hasTable(string $table, array &$checks): bool
    {
        try {
            if (Schema::hasTable($table)) {
                return true;
            }
        } catch (Throwable) {
            $this->addCheck($checks, 'database schema: ' . $table, 'fail', 'could not inspect database schema.');

            return false;
        }

        $this->addCheck($checks, 'database schema: ' . $table, 'fail', 'required table [' . $table . '] was not found.');

        return false;
    }

    private function hasAllowedRole(User $user): bool
    {
        if (! method_exists($user, 'hasAnyRole')) {
            return false;
        }

        try {
            return (bool) $user->hasAnyRole(self::PUBLIC_DEMO_ROLES);
        } catch (Throwable) {
            return false;
        }
    }

    private function safeCount(Market $market, string $relation): int
    {
        try {
            return (int) $market->{$relation}()->count();
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * @param list<array{name:string, status:string, details:string}> $checks
     */
    private function addCheck(array &$checks, string $name, string $status, string $details): void
    {
        $checks[] = [
            'name' => $name,
            'status' => $status,
            'details' => $details,
        ];
    }

    /**
     * @param list<array{name:string, status:string, details:string}> $checks
     * @return list<array{0:string, 1:string, 2:string}>
     */
    private function rows(array $checks): array
    {
        return array_map(
            static fn (array $check): array => [
                $check['name'],
                $check['status'],
                $check['details'],
            ],
            $checks,
        );
    }
}
