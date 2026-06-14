<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\IntegrationExchange;
use App\Models\Market;
use App\Support\OneC\OneCDailyExchangeHealthReport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OneCDailyExchangeHealthReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_history_does_not_raise_warning(): void
    {
        $market = $this->createMarket();

        $report = app(OneCDailyExchangeHealthReport::class)->build(
            (int) $market->id,
            'Asia/Novosibirsk',
            CarbonImmutable::parse('2026-06-14 12:00:00', 'Asia/Novosibirsk'),
        );

        $this->assertTrue($report['ok']);
        $this->assertSame([], $report['issues']);
    }

    public function test_complete_daily_exchange_set_is_healthy(): void
    {
        $market = $this->createMarket();
        $now = CarbonImmutable::parse('2026-06-14 12:00:00', 'Asia/Novosibirsk');

        $this->createHealthyDailySet((int) $market->id, $now);

        $report = app(OneCDailyExchangeHealthReport::class)->build((int) $market->id, 'Asia/Novosibirsk', $now);

        $this->assertTrue($report['ok']);
        $this->assertSame(7, $report['expected_success_count']);
        $this->assertSame(7, $report['recent_success_count']);
        $this->assertSame([], $report['issues']);
    }

    public function test_missing_second_payments_run_is_reported(): void
    {
        $market = $this->createMarket();
        $now = CarbonImmutable::parse('2026-06-14 12:00:00', 'Asia/Novosibirsk');

        $this->createHealthyDailySet((int) $market->id, $now, paymentsCount: 1);

        $report = app(OneCDailyExchangeHealthReport::class)->build((int) $market->id, 'Asia/Novosibirsk', $now);

        $this->assertFalse($report['ok']);
        $issue = collect($report['issues'])->firstWhere('entity_type', 'payments');

        $this->assertNotNull($issue);
        $this->assertSame('Оплаты', $issue['label']);
        $this->assertSame('stale', $issue['status']);
        $this->assertSame(1, $issue['recent_success_count']);
        $this->assertSame(2, $issue['required_success_count']);
    }

    public function test_latest_error_after_success_is_reported(): void
    {
        $market = $this->createMarket();
        $now = CarbonImmutable::parse('2026-06-14 12:00:00', 'Asia/Novosibirsk');

        $this->createHealthyDailySet((int) $market->id, $now);
        $this->createExchange((int) $market->id, 'accruals', IntegrationExchange::STATUS_ERROR, $now->subHour());

        $report = app(OneCDailyExchangeHealthReport::class)->build((int) $market->id, 'Asia/Novosibirsk', $now);

        $this->assertFalse($report['ok']);
        $issue = collect($report['issues'])->firstWhere('entity_type', 'accruals');

        $this->assertNotNull($issue);
        $this->assertSame('error', $issue['status']);
        $this->assertSame('Последний обмен завершился ошибкой.', $issue['message']);
    }

    private function createMarket(): Market
    {
        return Market::query()->create([
            'name' => 'Эко Ярмарка',
            'timezone' => 'Asia/Novosibirsk',
            'is_active' => true,
        ]);
    }

    private function createHealthyDailySet(
        int $marketId,
        CarbonImmutable $now,
        int $paymentsCount = 2,
        int $settlementsCount = 2,
    ): void {
        $this->createExchange($marketId, 'contract_debts', IntegrationExchange::STATUS_OK, $now->subHours(2));
        $this->createExchange($marketId, 'contracts', IntegrationExchange::STATUS_OK, $now->subHours(2)->subMinutes(10));
        $this->createExchange($marketId, 'accruals', IntegrationExchange::STATUS_OK, $now->subHours(2)->subMinutes(20));

        for ($i = 0; $i < $paymentsCount; $i++) {
            $this->createExchange($marketId, 'payments', IntegrationExchange::STATUS_OK, $now->subHours(2)->subMinutes(30 + $i));
        }

        for ($i = 0; $i < $settlementsCount; $i++) {
            $this->createExchange($marketId, 'settlements', IntegrationExchange::STATUS_OK, $now->subHours(2)->subMinutes(40 + $i));
        }
    }

    private function createExchange(
        int $marketId,
        string $entityType,
        string $status,
        CarbonImmutable $finishedAt,
    ): IntegrationExchange {
        return IntegrationExchange::query()->create([
            'market_id' => $marketId,
            'direction' => IntegrationExchange::DIRECTION_IN,
            'entity_type' => $entityType,
            'status' => $status,
            'payload' => [
                'endpoint' => '/api/1c/' . str_replace('_', '-', $entityType),
                'received' => 1,
            ],
            'started_at' => $finishedAt->subMinute(),
            'finished_at' => $finishedAt,
        ]);
    }
}
