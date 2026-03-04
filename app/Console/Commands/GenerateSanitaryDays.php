<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Market;
use App\Models\MarketHoliday;
use Carbon\Carbon;
use Illuminate\Console\Command;

class GenerateSanitaryDays extends Command
{
    protected $signature = 'market:calendar:generate-sanitary {--from=} {--to=} {--market_id=}';

    protected $description = 'Генерирует санитарные дни (первый понедельник каждого месяца) для календаря рынка.';

    public function handle(): int
    {
        $fromDate = $this->parseDate((string) $this->option('from')) ?? now()->startOfDay();
        $toDate = $this->parseDate((string) $this->option('to')) ?? $fromDate->copy()->addYear();

        if ($toDate->lessThan($fromDate)) {
            $this->error('Дата окончания меньше даты начала.');

            return Command::FAILURE;
        }

        $marketIds = $this->resolveMarketIds();
        if ($marketIds === []) {
            $this->warn('Нет рынков для генерации санитарных дней.');

            return Command::SUCCESS;
        }

        $rowsTotal = 0;
        foreach ($marketIds as $marketId) {
            $market = Market::query()->select(['id', 'settings'])->find($marketId);
            if (! $market) {
                continue;
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
                continue;
            }

            MarketHoliday::query()->upsert(
                $rows,
                ['market_id', 'title', 'starts_at'],
                ['ends_at', 'all_day', 'description', 'notify_before_days', 'notify_at', 'source', 'audience_scope', 'updated_at']
            );

            $rowsTotal += count($rows);
        }

        $this->info(sprintf('Санитарные дни синхронизированы: %d записей.', $rowsTotal));

        return Command::SUCCESS;
    }

    /**
     * @return list<int>
     */
    private function resolveMarketIds(): array
    {
        $marketId = $this->option('market_id');

        if (filled($marketId)) {
            return [(int) $marketId];
        }

        return Market::query()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
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
     * @param array<string,mixed> $settings
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
