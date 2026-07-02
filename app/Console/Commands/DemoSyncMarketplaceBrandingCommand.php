<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Market;
use App\Support\DemoPilotDataBuilder;
use App\Support\DemoPilotSettings;
use Illuminate\Console\Command;

class DemoSyncMarketplaceBrandingCommand extends Command
{
    protected $signature = 'demo:sync-marketplace-branding
        {--market= : Demo market id or slug; defaults to DEMO_PILOT_MARKET_SLUG}
        {--execute : Apply changes. Without this option the command is read-only}
        {--json : Output machine-readable JSON}';

    protected $description = 'Sync only synthetic demo market marketplace branding settings';

    public function handle(DemoPilotSettings $settings, DemoPilotDataBuilder $builder): int
    {
        $execute = (bool) $this->option('execute');
        $marketKey = trim((string) ($this->option('market') ?: $settings->marketSlug()));

        if ($marketKey === '') {
            return $this->finish([
                'status' => 'blocked',
                'details' => 'demo market slug is empty',
            ]);
        }

        $market = $this->resolveMarket($marketKey);

        if (! $market instanceof Market) {
            return $this->finish([
                'status' => 'blocked',
                'details' => 'demo market was not found',
                'market' => $marketKey,
            ]);
        }

        $source = trim((string) data_get($market->settings, 'demo_pilot.synthetic_source', ''));
        if ($source !== $settings->syntheticSource()) {
            return $this->finish([
                'status' => 'blocked',
                'details' => 'market is not marked as configured synthetic demo data',
                'market_id' => (int) $market->getKey(),
                'market_slug' => (string) ($market->slug ?? ''),
            ]);
        }

        $desiredMarketplace = (array) data_get(
            $builder->build((string) ($market->slug ?: $settings->marketSlug())),
            'market.settings.marketplace',
            [],
        );

        if ($desiredMarketplace === []) {
            return $this->finish([
                'status' => 'blocked',
                'details' => 'demo marketplace branding payload is empty',
                'market_id' => (int) $market->getKey(),
            ]);
        }

        $settingsPayload = (array) ($market->settings ?? []);
        $currentMarketplace = (array) data_get($settingsPayload, 'marketplace', []);
        $nextMarketplace = array_replace($currentMarketplace, $desiredMarketplace);
        $changedKeys = $this->changedKeys($currentMarketplace, $nextMarketplace);

        if ($changedKeys === []) {
            return $this->finish([
                'status' => 'unchanged',
                'details' => 'demo marketplace branding already matches payload',
                'market_id' => (int) $market->getKey(),
                'market_slug' => (string) ($market->slug ?? ''),
                'changed_keys' => [],
                'execute' => $execute,
            ]);
        }

        if (! $execute) {
            return $this->finish([
                'status' => 'dry_run',
                'details' => 'no data was written; pass --execute to apply',
                'market_id' => (int) $market->getKey(),
                'market_slug' => (string) ($market->slug ?? ''),
                'changed_keys' => $changedKeys,
                'execute' => false,
            ]);
        }

        data_set($settingsPayload, 'marketplace', $nextMarketplace);
        $market->forceFill(['settings' => $settingsPayload])->save();

        return $this->finish([
            'status' => 'updated',
            'details' => 'demo marketplace branding settings were updated',
            'market_id' => (int) $market->getKey(),
            'market_slug' => (string) ($market->slug ?? ''),
            'changed_keys' => $changedKeys,
            'execute' => true,
        ]);
    }

    private function resolveMarket(string $marketKey): ?Market
    {
        $query = Market::query();

        if (is_numeric($marketKey)) {
            return $query->whereKey((int) $marketKey)->first();
        }

        return $query->where('slug', $marketKey)->first();
    }

    /**
     * @param array<string, mixed> $current
     * @param array<string, mixed> $next
     * @return list<string>
     */
    private function changedKeys(array $current, array $next): array
    {
        $keys = [];

        foreach ($next as $key => $value) {
            if (($current[$key] ?? null) !== $value) {
                $keys[] = (string) $key;
            }
        }

        sort($keys);

        return $keys;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function finish(array $payload): int
    {
        $status = (string) ($payload['status'] ?? 'blocked');

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

            return $status === 'blocked' ? self::FAILURE : self::SUCCESS;
        }

        $line = $status . ': ' . (string) ($payload['details'] ?? '');
        $status === 'blocked' ? $this->error($line) : $this->info($line);

        if (isset($payload['market_id'])) {
            $this->line('market_id: ' . (int) $payload['market_id']);
        }

        if (isset($payload['market_slug'])) {
            $this->line('market_slug: ' . (string) $payload['market_slug']);
        }

        $changedKeys = $payload['changed_keys'] ?? null;
        if (is_array($changedKeys)) {
            $this->line('changed_keys: ' . ($changedKeys === [] ? '-' : implode(', ', $changedKeys)));
        }

        return $status === 'blocked' ? self::FAILURE : self::SUCCESS;
    }
}
