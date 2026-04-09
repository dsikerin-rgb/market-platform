<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Concerns;

trait ResolvesDashboardFilterMonth
{
    protected function resolveDashboardFilterMonthRaw(): mixed
    {
        if (property_exists($this, 'pageFilters') && is_array($this->pageFilters ?? null)) {
            $raw = $this->pageFilters['dashboard_month']
                ?? $this->pageFilters['dashboard_period']
                ?? null;

            if ($raw) {
                return $raw;
            }
        }

        if (is_array($this->filters ?? null)) {
            $raw = $this->filters['dashboard_month']
                ?? $this->filters['dashboard_period']
                ?? null;

            if ($raw) {
                return $raw;
            }
        }

        $raw = session('dashboard_month') ?: session('dashboard_period');

        if ($raw) {
            return $raw;
        }

        if (property_exists($this, 'pageFilters') && is_array($this->pageFilters ?? null)) {
            $raw = $this->pageFilters['month']
                ?? $this->pageFilters['period']
                ?? null;

            if ($raw) {
                return $raw;
            }
        }

        if (is_array($this->filters ?? null)) {
            return $this->filters['month']
                ?? $this->filters['period']
                ?? null;
        }

        return null;
    }
}
