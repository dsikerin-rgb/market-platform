<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\IntegrationExchange;
use App\Models\MarketIntegration;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

class ReportSuspiciousCurrentDuplicateWarningsCommand extends Command
{
    protected $signature = 'contracts:report-duplicate-warnings
        {--market= : Market ID (default: market_id from active 1C integration)}
        {--limit=10 : How many latest exchanges to print}
        {--all : Include exchanges without duplicate warnings too}
        {--with-samples-only : Print only exchanges that contain duplicate warning samples}';

    protected $description = 'Read-only report of suspicious current duplicate contract warnings captured in integration exchanges.';

    public function handle(): int
    {
        $marketId = $this->resolveMarketId();
        $limit = max(0, (int) $this->option('limit'));
        $includeAll = (bool) $this->option('all');
        $withSamplesOnly = (bool) $this->option('with-samples-only');

        $query = IntegrationExchange::query()
            ->where('market_id', $marketId)
            ->where('direction', IntegrationExchange::DIRECTION_IN)
            ->where('entity_type', 'contracts')
            ->orderByDesc('started_at')
            ->orderByDesc('id');

        /** @var Collection<int, IntegrationExchange> $exchanges */
        $exchanges = $query->get();

        $prepared = $exchanges
            ->map(fn (IntegrationExchange $exchange): array => $this->prepareExchangeRow($exchange))
            ->filter(function (array $row) use ($includeAll, $withSamplesOnly): bool {
                if ($withSamplesOnly) {
                    return $row['warning_sample_count'] > 0;
                }

                if ($includeAll) {
                    return true;
                }

                return $row['warning_group_count'] > 0 || $row['warning_row_count'] > 0;
            })
            ->values();

        $reported = $limit > 0
            ? $prepared->take($limit)->values()
            : $prepared;

        $stats = [
            'market_id' => $marketId,
            'scanned_exchange_count' => $exchanges->count(),
            'reported_exchange_count' => $reported->count(),
            'warning_exchange_count' => $reported->filter(
                static fn (array $row): bool => $row['warning_group_count'] > 0 || $row['warning_row_count'] > 0
            )->count(),
            'warning_group_count' => $reported->sum('warning_group_count'),
            'warning_row_count' => $reported->sum('warning_row_count'),
            'warning_sample_count' => $reported->sum('warning_sample_count'),
        ];

        $this->line(json_encode([
            'stats' => $stats,
            'exchanges' => $reported->all(),
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }

    private function resolveMarketId(): int
    {
        $marketId = $this->option('market');
        if (is_numeric($marketId) && (int) $marketId > 0) {
            return (int) $marketId;
        }

        $integration = MarketIntegration::query()
            ->where('type', MarketIntegration::TYPE_1C)
            ->where('status', 'active')
            ->first();

        return (int) ($integration?->market_id ?? 1);
    }

    /**
     * @return array<string, mixed>
     */
    private function prepareExchangeRow(IntegrationExchange $exchange): array
    {
        $payload = is_array($exchange->payload) ? $exchange->payload : [];
        $warnings = Arr::get($payload, 'warnings', []);
        $warningDetails = Arr::get($warnings, 'suspected_current_duplicate_contracts', []);
        $samples = Arr::get($warningDetails, 'samples', []);
        $samples = is_array($samples) ? array_values($samples) : [];

        return [
            'id' => (int) $exchange->id,
            'market_id' => (int) $exchange->market_id,
            'status' => (string) $exchange->status,
            'started_at' => optional($exchange->started_at)?->toDateTimeString(),
            'finished_at' => optional($exchange->finished_at)?->toDateTimeString(),
            'calculated_at' => $this->payloadString($payload, 'calculated_at'),
            'received' => $this->payloadInt($payload, 'received'),
            'created' => $this->payloadInt($payload, 'created'),
            'updated' => $this->payloadInt($payload, 'updated'),
            'skipped' => $this->payloadInt($payload, 'skipped'),
            'warning_group_count' => $this->payloadInt($warnings, 'suspected_current_duplicate_contract_groups'),
            'warning_row_count' => $this->payloadInt($warnings, 'suspected_current_duplicate_contract_rows'),
            'warning_sample_count' => count($samples),
            'warning_samples' => $samples,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function payloadInt(array $payload, string $key): int
    {
        $value = Arr::get($payload, $key);

        if (is_numeric($value)) {
            return (int) $value;
        }

        if (is_string($value) && trim($value) !== '' && is_numeric(trim($value))) {
            return (int) trim($value);
        }

        return 0;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function payloadString(array $payload, string $key): ?string
    {
        $value = Arr::get($payload, $key);

        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }
}
