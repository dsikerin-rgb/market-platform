<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Pages\OneCReconciliation;
use App\Filament\Widgets\Concerns\ResolvesDashboardFilterMonth;
use App\Models\Market;
use App\Support\OneC\AccrualPaymentReconciliationReport;
use Filament\Facades\Filament;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;

class OneCAccrualPaymentReconciliationDetailWidget extends Widget
{
    use InteractsWithPageFilters;
    use ResolvesDashboardFilterMonth;

    protected string $view = 'filament.widgets.one-c-accrual-payment-reconciliation-detail-widget';

    protected int|string|array $columnSpan = [
        'default' => 'full',
        'md' => 2,
        'xl' => 3,
    ];

    private const ROW_LIMIT = 10;

    public static function canView(): bool
    {
        $user = Filament::auth()->user();

        return (bool) $user && (
            (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin())
            || (bool) ($user->market_id ?? null)
        );
    }

    protected function getViewData(): array
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return $this->emptyData('Нет пользователя');
        }

        $marketId = $this->resolveMarketIdForWidget($user);

        if (! $marketId) {
            return $this->emptyData('Выберите рынок');
        }

        $raw = $this->resolveDashboardFilterMonthRaw();
        $period = is_string($raw) ? $raw : null;
        $report = app(AccrualPaymentReconciliationReport::class)->build($marketId, $period);
        $rows = $report['rows'];
        $visibleRows = array_slice($rows, 0, self::ROW_LIMIT);
        $monthYm = (string) $report['monthYm'];

        return [
            'monthLabel' => $report['monthLabel'],
            'rows' => $visibleRows,
            'summary' => $report['summary'],
            'hasMoreRows' => count($rows) > self::ROW_LIMIT,
            'hiddenRowsCount' => max(0, count($rows) - self::ROW_LIMIT),
            'rowLimit' => self::ROW_LIMIT,
            'emptyReason' => $report['emptyReason'],
            'fullUrl' => OneCReconciliation::getUrl($monthYm !== '' ? ['period' => $monthYm] : []),
        ];
    }

    private function resolveMarketIdForWidget($user): ?int
    {
        $isSuperAdmin = method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin();

        if (! $isSuperAdmin) {
            return $user->market_id ? (int) $user->market_id : null;
        }

        $panelId = Filament::getCurrentPanel()?->getId() ?? 'admin';
        $value =
            session('dashboard_market_id')
            ?? session("filament.{$panelId}.selected_market_id")
            ?? session("filament_{$panelId}_market_id")
            ?? session('filament.admin.selected_market_id');

        if (filled($value)) {
            return (int) $value;
        }

        $marketId = Market::query()
            ->orderBy('id')
            ->value('id');

        return $marketId ? (int) $marketId : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyData(string $reason): array
    {
        return [
            'monthLabel' => '—',
            'rows' => [],
            'summary' => [
                'accrued' => 0.0,
                'paid' => 0.0,
                'delta' => 0.0,
                'debt_count' => 0,
                'overpaid_count' => 0,
                'closed_count' => 0,
                'rows_count' => 0,
            ],
            'hasMoreRows' => false,
            'hiddenRowsCount' => 0,
            'rowLimit' => self::ROW_LIMIT,
            'emptyReason' => $reason,
            'fullUrl' => null,
        ];
    }
}
