<?php

declare(strict_types=1);

namespace App\Support;

use LogicException;

class DemoPilotSettings
{
    public const OPERATION_PROVISION = 'provision';
    public const OPERATION_RESET = 'reset';

    public function enabled(): bool
    {
        return (bool) config('demo_pilot.enabled', false);
    }

    public function provisionEnabled(): bool
    {
        return (bool) config('demo_pilot.provision_enabled', false);
    }

    public function resetEnabled(): bool
    {
        return (bool) config('demo_pilot.reset_enabled', false);
    }

    public function externalIntegrationsEnabled(): bool
    {
        return (bool) config('demo_pilot.external_integrations_enabled', false);
    }

    public function marketSlug(): string
    {
        return trim((string) config('demo_pilot.market_slug', 'demo-market')) ?: 'demo-market';
    }

    public function emailDomain(): string
    {
        return trim((string) config('demo_pilot.email_domain', 'demo.marketuchet.local')) ?: 'demo.marketuchet.local';
    }

    public function syntheticSource(): string
    {
        return trim((string) config('demo_pilot.synthetic_source', 'demo_pilot')) ?: 'demo_pilot';
    }

    public function canWriteData(string $operation): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        if (! $this->operationEnabled($operation)) {
            return false;
        }

        if (app()->environment('production') && ! (bool) config('demo_pilot.allow_production_data_writes', false)) {
            return false;
        }

        return true;
    }

    public function assertDataWriteAllowed(string $operation): void
    {
        if ($this->canWriteData($operation)) {
            return;
        }

        throw new LogicException(sprintf(
            'Demo/pilot data write is disabled for operation [%s].',
            $operation,
        ));
    }

    private function operationEnabled(string $operation): bool
    {
        return match ($operation) {
            self::OPERATION_PROVISION => $this->provisionEnabled(),
            self::OPERATION_RESET => $this->resetEnabled(),
            default => false,
        };
    }
}
