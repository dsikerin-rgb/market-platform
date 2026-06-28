<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Market;
use App\Models\MarketHoliday;
use App\Support\MarketContext;
use Carbon\Carbon;
use Illuminate\Console\Command;

class GenerateSanitaryDays extends Command
{
    protected $signature = 'market:calendar:generate-sanitary
        {--from= : Start date}
        {--to= : End date}
        {--market_id= : Market id}
        {--all-markets : Allow --execute to affect every market}
        {--dry-run : Run in dry-run mode}
        {--execute : Upsert sanitary days (default: dry-run)}';

    protected $description = 'Генерирует санитарные дни (первый понедельник каждого месяца) для календаря рынка.';

    public function handle(): int
    {
        $marketId = $this->marketIdOption();
        $execute = (bool) $this->option('execute');
        $dryRun = ! $execute || (bool) $this->option('dry-run');

        if ($marketId === false) {
            $this->error('Market ID must be a positive integer.');

            return Command::FAILURE;
        }

        if ($execute && (bool) $this->option('dry-run')) {
            $this->error('Use either --execute or --dry-run, not both.');

            return Command::FAILURE;
        }

        if ($execute && $marketId !== null && (bool) $this->option('all-markets')) {
            $this->error('Use either --market_id or --all-markets with --execute, not both.');

            return Command::FAILURE;
        }

        if ($execute && $marketId === null && ! (bool) $this->option('all-markets')) {
            $this->error('Market ID is required with --execute. Use --market_id=1 or --all-markets.');

            return Command::FAILURE;
        }

        $fromDate = $this->parseDate((string) $this->option('from')) ?? now()->startOfDay();
        $toDate = $this->parseDate((string) $this->option('to')) ?? $fromDate->copy()->addYear();

        if ($toDate->lessThan($fromDate)) {
            $this->error('Дата окончания меньше даты начала.');

            return Command::FAILURE;
        }

        $marketIds = $this->resolveMarketIds($marketId);
        if ($marketIds === []) {
            $this->warn('Нет рынков для генерации санитарных дней.');

            return Command::SUCCESS;
        }

        $rowsTotal = 0;
        foreach ($marketIds as $marketId) {
            $rowsTotal += app(MarketContext::class)->withMarket((int) $marketId, function () use ($marketId, $fromDate, $toDate, $dryRun): int {
                $market = Market::query()->select(['id', 'settings'])->find($marketId);
                if (! $market) {
                    return 0;
                }

                $settings = (array) ($market->settings ?? []);
                $notifyBeforeDays = $this->resolveNotifyBeforeDays($settings);

                $rows = [];
                $cursor = $fromDate->copy()->startOfMonth();
                $endMonth = $toDate->copy()->startOfMonth();

                while ($cursor->lessThanOrEqualTo($endMonth)) {
                    $firstMonday = $cursor->copy()->firstOfMonth(Carbon::MONDAY)->startOfDay();
                    if ($firstMonday->betweenIncluded($fromDate, $toDate)) {
                        $rows[] = [
                            'market_id' => (int) $market->id,
                            'title' => 'Санитарный день',
                            'starts_at' => $firstMonday->toDateString(),
                            'ends_at' => null,
                            'all_day' => true,
                            'description' => 'Плановый санитарный день (первый понедельник месяца).',
                            'notify_before_days' => $notifyBeforeDays,
                            'notify_at' => $firstMonday->copy()->subDays($notifyBeforeDays)->startOfDay(),
                            'source' => 'sanitary_auto',
                            'audience_scope' => 'staff',
                            'updated_at' => now(),
                            'created_at' => now(),
                        ];
                    }

                    $cursor->addMonth();
                }

                if ($rows === []) {
                    return 0;
                }

                if (! $dryRun) {
                    MarketHoliday::query()->upsert(
                        $rows,
                        ['market_id', 'title', 'starts_at'],
                        ['ends_at', 'all_day', 'description', 'notify_before_days', 'notify_at', 'source', 'audience_scope', 'updated_at']
                    );
                }

                return count($rows);
            });
        }

        $this->info(sprintf(
            '%s rows=%d',
            $dryRun ? 'Sanitary days dry-run.' : 'Sanitary days synchronized.',
            $rowsTotal,
        ));

        if ($dryRun) {
            $this->warn('DRY RUN: no changes applied. Use --execute --market_id=... or --execute --all-markets to apply.');
        }

        return Command::SUCCESS;
    }

    /**
     * @return list<int>
     */
    private function resolveMarketIds(?int $marketId): array
    {
        if ($marketId !== null) {
            return [$marketId];
        }

        return Market::query()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function marketIdOption(): int|false|null
    {
        $value = $this->option('market_id');

        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $marketId = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        return is_int($marketId) ? $marketId : false;
    }

    private function parseDate(?string $value): ?Carbon
    {
        if (! filled($value)) {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string,mixed>  $settings
     */
    private function resolveNotifyBeforeDays(array $settings): int
    {
        $value = $settings['sanitary_default_notify_before_days']
            ?? $settings['holiday_default_notify_before_days']
            ?? 7;

        if (! is_numeric($value)) {
            return 7;
        }

        return max(0, (int) $value);
    }
}
