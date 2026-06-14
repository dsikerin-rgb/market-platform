<?php

declare(strict_types=1);

namespace App\Support\OneC;

use App\Models\IntegrationExchange;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Schema;

class OneCDailyExchangeHealthReport
{
    public const WINDOW_HOURS = 30;

    /**
     * @var array<string, array{label: string, required_success_count: int}>
     */
    private const REQUIRED_EXCHANGES = [
        'contract_debts' => [
            'label' => 'Долги',
            'required_success_count' => 1,
        ],
        'payments' => [
            'label' => 'Оплаты',
            'required_success_count' => 2,
        ],
        'contracts' => [
            'label' => 'Договоры',
            'required_success_count' => 1,
        ],
        'accruals' => [
            'label' => 'Начисления',
            'required_success_count' => 1,
        ],
        'settlements' => [
            'label' => 'Расчеты/сальдо',
            'required_success_count' => 2,
        ],
    ];

    /**
     * @return array{
     *     ok: bool,
     *     market_id: int,
     *     checked_at: CarbonImmutable,
     *     stale_before: CarbonImmutable,
     *     window_hours: int,
     *     expected_success_count: int,
     *     recent_success_count: int,
     *     issues: list<array{
     *         entity_type: string,
     *         label: string,
     *         status: string,
     *         required_success_count: int,
     *         recent_success_count: int,
     *         latest_ok_at: CarbonInterface|null,
     *         latest_error_at: CarbonInterface|null,
     *         latest_ok_label: string|null,
     *         latest_error_label: string|null,
     *         message: string
     *     }>
     * }
     */
    public function build(int $marketId, ?string $timezone = null, ?CarbonImmutable $now = null): array
    {
        $tz = $this->resolveTimezone($timezone);
        $checkedAt = $now ? $now->setTimezone($tz) : CarbonImmutable::now($tz);
        $staleBefore = $checkedAt->subHours(self::WINDOW_HOURS);
        $empty = $this->emptyReport($marketId, $checkedAt, $staleBefore);

        if ($marketId <= 0 || ! Schema::hasTable('integration_exchanges')) {
            return $empty;
        }

        $entities = array_keys(self::REQUIRED_EXCHANGES);
        $hasHistory = IntegrationExchange::query()
            ->where('market_id', $marketId)
            ->where('direction', IntegrationExchange::DIRECTION_IN)
            ->whereIn('entity_type', $entities)
            ->exists();

        if (! $hasHistory) {
            return $empty;
        }

        $issues = [];
        $recentSuccessTotal = 0;
        $expectedSuccessTotal = 0;

        foreach (self::REQUIRED_EXCHANGES as $entityType => $config) {
            $required = (int) $config['required_success_count'];
            $expectedSuccessTotal += $required;

            $latestOk = $this->latestExchange($marketId, $entityType, IntegrationExchange::STATUS_OK);
            $latestError = $this->latestExchange($marketId, $entityType, IntegrationExchange::STATUS_ERROR);
            $latestOkAt = $this->exchangeTimestamp($latestOk);
            $latestErrorAt = $this->exchangeTimestamp($latestError);
            $recentSuccessCount = $this->recentSuccessCount($marketId, $entityType, $staleBefore);
            $recentSuccessTotal += min($recentSuccessCount, $required);

            $hasLatestErrorAfterSuccess = $latestErrorAt !== null
                && (
                    $latestOkAt === null
                    || $latestErrorAt->greaterThan($latestOkAt)
                );

            if ($hasLatestErrorAfterSuccess) {
                $issues[] = $this->makeIssue(
                    entityType: $entityType,
                    label: $config['label'],
                    status: 'error',
                    requiredSuccessCount: $required,
                    recentSuccessCount: $recentSuccessCount,
                    latestOkAt: $latestOkAt,
                    latestErrorAt: $latestErrorAt,
                    timezone: $tz,
                    message: 'Последний обмен завершился ошибкой.',
                );

                continue;
            }

            if ($recentSuccessCount < $required) {
                $issues[] = $this->makeIssue(
                    entityType: $entityType,
                    label: $config['label'],
                    status: $latestOk === null ? 'missing' : 'stale',
                    requiredSuccessCount: $required,
                    recentSuccessCount: $recentSuccessCount,
                    latestOkAt: $latestOkAt,
                    latestErrorAt: $latestErrorAt,
                    timezone: $tz,
                    message: $latestOk === null
                        ? 'Успешных обменов еще не было.'
                        : 'Нет полного успешного обмена в контрольном окне.',
                );
            }
        }

        return [
            ...$empty,
            'ok' => $issues === [],
            'expected_success_count' => $expectedSuccessTotal,
            'recent_success_count' => $recentSuccessTotal,
            'issues' => $issues,
        ];
    }

    /**
     * @return array<string, array{label: string, required_success_count: int}>
     */
    public static function requiredExchanges(): array
    {
        return self::REQUIRED_EXCHANGES;
    }

    /**
     * @return array{
     *     ok: bool,
     *     market_id: int,
     *     checked_at: CarbonImmutable,
     *     stale_before: CarbonImmutable,
     *     window_hours: int,
     *     expected_success_count: int,
     *     recent_success_count: int,
     *     issues: list<array<string, mixed>>
     * }
     */
    private function emptyReport(int $marketId, CarbonImmutable $checkedAt, CarbonImmutable $staleBefore): array
    {
        return [
            'ok' => true,
            'market_id' => $marketId,
            'checked_at' => $checkedAt,
            'stale_before' => $staleBefore,
            'window_hours' => self::WINDOW_HOURS,
            'expected_success_count' => 0,
            'recent_success_count' => 0,
            'issues' => [],
        ];
    }

    private function latestExchange(int $marketId, string $entityType, string $status): ?IntegrationExchange
    {
        return IntegrationExchange::query()
            ->where('market_id', $marketId)
            ->where('direction', IntegrationExchange::DIRECTION_IN)
            ->where('entity_type', $entityType)
            ->where('status', $status)
            ->orderByRaw('COALESCE(finished_at, started_at, created_at) DESC')
            ->orderByDesc('id')
            ->first();
    }

    private function recentSuccessCount(int $marketId, string $entityType, CarbonImmutable $staleBefore): int
    {
        return IntegrationExchange::query()
            ->where('market_id', $marketId)
            ->where('direction', IntegrationExchange::DIRECTION_IN)
            ->where('entity_type', $entityType)
            ->where('status', IntegrationExchange::STATUS_OK)
            ->where(function ($query) use ($staleBefore): void {
                $query->where('finished_at', '>=', $staleBefore->utc())
                    ->orWhere(function ($query) use ($staleBefore): void {
                        $query->whereNull('finished_at')
                            ->where('started_at', '>=', $staleBefore->utc());
                    })
                    ->orWhere(function ($query) use ($staleBefore): void {
                        $query->whereNull('finished_at')
                            ->whereNull('started_at')
                            ->where('created_at', '>=', $staleBefore->utc());
                    });
            })
            ->count();
    }

    private function exchangeTimestamp(?IntegrationExchange $exchange): ?CarbonInterface
    {
        if (! $exchange) {
            return null;
        }

        return $exchange->finished_at ?? $exchange->started_at ?? $exchange->created_at;
    }

    /**
     * @return array{
     *     entity_type: string,
     *     label: string,
     *     status: string,
     *     required_success_count: int,
     *     recent_success_count: int,
     *     latest_ok_at: CarbonInterface|null,
     *     latest_error_at: CarbonInterface|null,
     *     latest_ok_label: string|null,
     *     latest_error_label: string|null,
     *     message: string
     * }
     */
    private function makeIssue(
        string $entityType,
        string $label,
        string $status,
        int $requiredSuccessCount,
        int $recentSuccessCount,
        ?CarbonInterface $latestOkAt,
        ?CarbonInterface $latestErrorAt,
        string $timezone,
        string $message,
    ): array {
        return [
            'entity_type' => $entityType,
            'label' => $label,
            'status' => $status,
            'required_success_count' => $requiredSuccessCount,
            'recent_success_count' => $recentSuccessCount,
            'latest_ok_at' => $latestOkAt,
            'latest_error_at' => $latestErrorAt,
            'latest_ok_label' => $this->formatDateTime($latestOkAt, $timezone),
            'latest_error_label' => $this->formatDateTime($latestErrorAt, $timezone),
            'message' => $message,
        ];
    }

    private function resolveTimezone(?string $timezone): string
    {
        $tz = trim((string) ($timezone ?? ''));

        if ($tz === '') {
            $tz = (string) config('app.timezone', 'UTC');
        }

        try {
            CarbonImmutable::now($tz);
        } catch (\Throwable) {
            $tz = (string) config('app.timezone', 'UTC');
        }

        return $tz;
    }

    private function formatDateTime(?CarbonInterface $value, string $timezone): ?string
    {
        if (! $value) {
            return null;
        }

        try {
            return CarbonImmutable::instance($value)
                ->setTimezone($timezone)
                ->format('d.m.Y H:i');
        } catch (\Throwable) {
            return null;
        }
    }
}
