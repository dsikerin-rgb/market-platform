<?php

namespace App\Console\Commands;

use App\Models\Market;
use App\Models\MarketHoliday;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class SyncMarketHolidays extends Command
{
    protected $signature = 'market:holidays:sync {--from=} {--to=} {--market_id=}';

    protected $description = 'Синхронизация праздников рынка из CSV файла.';

    public function handle(): int
    {
        $from = $this->option('from');
        $to = $this->option('to');

        $fromDate = $this->parseDate($from) ?? now()->startOfDay();
        $toDate = $this->parseDate($to) ?? $fromDate->copy()->addYear();

        if ($toDate->lessThan($fromDate)) {
            $this->error('Дата окончания меньше даты начала.');

            return Command::FAILURE;
        }

        $path = database_path('data/market_holidays_ru_2026_2027.csv');

        if (! is_readable($path)) {
            $this->error('CSV файл с праздниками не найден: ' . $path);

            return Command::FAILURE;
        }

        $rows = $this->readCsv($path);

        if (empty($rows)) {
            $this->warn('CSV файл не содержит данных.');

            return Command::SUCCESS;
        }

        $marketIds = $this->resolveMarketIds();

        if (empty($marketIds)) {
            $this->warn('Нет рынков для синхронизации.');

            return Command::SUCCESS;
        }

        $totalUpserts = 0;

        foreach ($marketIds as $marketId) {
            $market = Market::query()->select(['id', 'settings'])->find($marketId);

            if (! $market) {
                continue;
            }

            $defaultNotifyDays = $this->resolveDefaultNotifyDays($market);

            $payload = [];

            foreach ($rows as $row) {
                $start = $this->parseDate($row['starts_at'] ?? null);

                if (! $start) {
                    continue;
                }

                $end = $this->parseDate($row['ends_at'] ?? null) ?? $start->copy();

                if ($start->greaterThan($toDate) || $end->lessThan($fromDate)) {
                    continue;
                }

                $notifyBeforeDays = is_numeric($row['notify_before_days'] ?? null)
                    ? (int) $row['notify_before_days']
                    : null;

                $effectiveNotifyDays = $notifyBeforeDays ?? $defaultNotifyDays;
                $notifyAt = $effectiveNotifyDays !== null
                    ? $start->copy()->startOfDay()->subDays($effectiveNotifyDays)
                    : null;

                $payload[] = [
                    'market_id' => $marketId,
                    'title' => (string) ($row['title'] ?? ''),
                    'starts_at' => $start->toDateString(),
                    'ends_at' => ($row['ends_at'] ?? null) ? $end->toDateString() : null,
                    'all_day' => $this->truthy($row['all_day'] ?? null),
                    'description' => $this->nullableString($row['description'] ?? null),
                    'notify_before_days' => $notifyBeforeDays,
                    'notify_at' => $notifyAt,
                    'source' => 'file',
                    'updated_at' => now(),
                    'created_at' => now(),
                ];
            }

            if (empty($payload)) {
                continue;
            }

            MarketHoliday::query()->upsert(
                $payload,
                ['market_id', 'title', 'starts_at'],
                ['ends_at', 'all_day', 'description', 'notify_before_days', 'notify_at', 'source', 'updated_at']
            );

            $totalUpserts += count($payload);
        }

        if ($totalUpserts === 0) {
            $message = 'CSV файл не содержит праздников в указанном диапазоне.';
            $this->warn($message);
            Log::warning($message, [
                'from' => $fromDate->toDateString(),
                'to' => $toDate->toDateString(),
            ]);
        } else {
            $this->info(sprintf('Праздники синхронизированы: %d записей.', $totalUpserts));
        }

        return Command::SUCCESS;
    }

    /**
     * @return list<int>
     */
    private function resolveMarketIds(): array
    {
        $marketId = $this->option('market_id');

        if (filled($marketId)) {
            return [
                (int) $marketId,
            ];
        }

        return Market::query()->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * @return array<int, array<string, string|null>>
     */
    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'r');

        if (! $handle) {
            return [];
        }

        $header = fgetcsv($handle);

        if (! $header || ! is_array($header)) {
            fclose($handle);

            return [];
        }

        $rows = [];

        while (($row = fgetcsv($handle)) !== false) {
            $row = array_pad($row, count($header), null);
            $row = array_combine($header, $row);

            if (! is_array($row)) {
                continue;
            }

            $rows[] = $row;
        }

        fclose($handle);

        return $rows;
    }

    private function parseDate(?string $value): ?Carbon
    {
        if (! $value) {
            return null;
        }

        try {
            return Carbon::parse($value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveDefaultNotifyDays(Market $market): ?int
    {
        $settings = (array) ($market->settings ?? []);
        $value = $settings['holiday_default_notify_before_days'] ?? null;

        if (is_numeric($value)) {
            return max(0, (int) $value);
        }

        return 7;
    }

    private function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $value = strtolower(trim((string) $value));

        return in_array($value, ['1', 'true', 'yes', 'y', 'on'], true);
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
