<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Models\Market;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class MarketPeriodResolver
{
    public function marketNow(Market $market): CarbonImmutable
    {
        return CarbonImmutable::now($this->resolveTimezone($market));
    }

    public function resolveMarketPeriod(Market $market, ?string $inputPeriod): CarbonImmutable
    {
        $tz = $this->resolveTimezone($market);

        if (is_string($inputPeriod) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $inputPeriod)) {
            try {
                return CarbonImmutable::createFromFormat('Y-m-d', $inputPeriod, $tz)->startOfMonth();
            } catch (\Throwable) {
                // fallback ниже
            }
        }

        return CarbonImmutable::now($tz)->startOfMonth();
    }

    public function normalizePeriodInput(?string $inputPeriod, string $tz): ?CarbonImmutable
    {
        if (! is_string($inputPeriod)) {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $inputPeriod)) {
            try {
                return CarbonImmutable::createFromFormat('Y-m-d', $inputPeriod, $tz)->startOfMonth();
            } catch (\Throwable) {
                return null;
            }
        }

        if (preg_match('/^\d{4}-\d{2}$/', $inputPeriod)) {
            try {
                return CarbonImmutable::createFromFormat('Y-m', $inputPeriod, $tz)->startOfMonth();
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    /**
     * @return array<string, string> ['YYYY-MM-01' => 'MM.YYYY']
     */
    public function availablePeriods(int $marketId, string $tz): array
    {
        $months = [];

        if (
            $marketId > 0
            && Schema::hasTable('tenant_accruals')
            && Schema::hasColumn('tenant_accruals', 'market_id')
            && Schema::hasColumn('tenant_accruals', 'period')
        ) {
            $raw = DB::table('tenant_accruals')
                ->where('market_id', $marketId)
                ->select('period')
                ->distinct()
                ->orderBy('period')
                ->pluck('period')
                ->all();

            foreach ($raw as $value) {
                if (! $value) {
                    continue;
                }

                try {
                    $period = CarbonImmutable::parse((string) $value, $tz)->startOfMonth();
                    $months[$period->toDateString()] = true;
                } catch (\Throwable) {
                    // ignore
                }
            }
        }

        if (Schema::hasTable('operations') && Schema::hasColumn('operations', 'effective_month')) {
            try {
                $raw = DB::table('operations')
                    ->where('market_id', $marketId)
                    ->select('effective_month')
                    ->distinct()
                    ->orderBy('effective_month')
                    ->pluck('effective_month')
                    ->all();

                foreach ($raw as $value) {
                    if (! $value) {
                        continue;
                    }

                    try {
                        $period = CarbonImmutable::parse((string) $value, $tz)->startOfMonth();
                        $months[$period->toDateString()] = true;
                    } catch (\Throwable) {
                        // ignore
                    }
                }
            } catch (\Throwable) {
                // ignore
            }
        }

        $months[CarbonImmutable::now($tz)->startOfMonth()->toDateString()] = true;

        if (count($months) < 3) {
            $now = CarbonImmutable::now($tz)->startOfMonth();
            for ($i = 0; $i < 24; $i++) {
                $months[$now->subMonths($i)->toDateString()] = true;
            }
        }

        $keys = array_keys($months);
        sort($keys);

        $options = [];
        foreach ($keys as $date) {
            try {
                $options[$date] = CarbonImmutable::createFromFormat('Y-m-d', $date, $tz)->format('m.Y');
            } catch (\Throwable) {
                $options[$date] = $date;
            }
        }

        return array_reverse($options, true);
    }

    private function resolveTimezone(Market $market): string
    {
        $tz = (string) ($market->timezone ?? config('app.timezone', 'UTC'));

        try {
            CarbonImmutable::now($tz);
        } catch (\Throwable) {
            $tz = (string) config('app.timezone', 'UTC');
        }

        return $tz;
    }
}
