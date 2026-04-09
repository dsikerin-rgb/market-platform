<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Concerns;

trait ResolvesDashboardFilterMonth
{
    protected function resolveDashboardFilterMonthRaw(): mixed
    {
        $raw = null;

        if (property_exists($this, 'pageFilters') && is_array($this->pageFilters ?? null)) {
            $raw = $this->pageFilters['dashboard_month']
                ?? $this->pageFilters['dashboard_period']
                ?? $this->pageFilters['month']
                ?? $this->pageFilters['period']
                ?? null;
        }

        if (! $raw && is_array($this->filters ?? null)) {
            $raw = $this->filters['dashboard_month']
                ?? $this->filters['dashboard_period']
                ?? $this->filters['month']
                ?? $this->filters['period']
                ?? null;
        }

        return $raw ?: session('dashboard_month') ?: session('dashboard_period');
    }
}
