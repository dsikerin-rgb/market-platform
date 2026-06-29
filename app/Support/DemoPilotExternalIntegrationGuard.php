<?php

declare(strict_types=1);

namespace App\Support;

class DemoPilotExternalIntegrationGuard
{
    /**
     * @var array<string, string>
     */
    private const REQUIRED_DISABLED = [
        'one_c' => '1C',
        'mail' => 'mail',
        'telegram' => 'Telegram',
        'webhooks' => 'webhooks',
    ];

    /**
     * @param array<string, mixed> $dataSet
     * @return array{status:string, table:string, records:int, details:string, issues:list<string>}
     */
    public function check(array $dataSet): array
    {
        $issues = [];

        if (app(DemoPilotSettings::class)->externalIntegrationsEnabled()) {
            $issues[] = 'demo external integrations config flag must remain disabled';
        }

        if ((bool) data_get($dataSet, 'metadata.external_integrations_enabled', false)) {
            $issues[] = 'demo metadata external_integrations_enabled must be false';
        }

        $integrations = $dataSet['integrations'] ?? null;

        if (! is_array($integrations) || array_is_list($integrations)) {
            $issues[] = 'demo integrations payload must be an associative array';
        } else {
            foreach (self::REQUIRED_DISABLED as $key => $label) {
                $value = strtolower(trim((string) ($integrations[$key] ?? '')));

                if ($value !== 'disabled') {
                    $issues[] = 'demo integration [' . $label . '] must be disabled';
                }
            }
        }

        return [
            'status' => $issues === [] ? 'ready' : 'blocked',
            'table' => 'demo_pilot.integrations',
            'records' => count(self::REQUIRED_DISABLED),
            'details' => $issues === []
                ? 'live 1C, mail, Telegram, and webhooks are disabled'
                : implode('; ', $issues),
            'issues' => array_values(array_unique($issues)),
        ];
    }
}
