<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Pages\OneCReconciliation;
use App\Filament\Widgets\Concerns\ResolvesDashboardFilterMonth;
use App\Models\Market;
use App\Support\OneC\AccrualPaymentReconciliationReport;
use Carbon\CarbonImmutable;
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

        [$fromDate, $toDate] = $this->resolveDateRange();
        $report = app(AccrualPaymentReconciliationReport::class)->build($marketId, $fromDate, $toDate);
        $rows = $report['rows'];
        $visibleRows = array_slice($rows, 0, self::ROW_LIMIT);

        return [
            'periodLabel' => $report['periodLabel'],
            'rows' => $visibleRows,
            'summary' => $report['summary'],
            'hasMoreRows' => count($rows) > self::ROW_LIMIT,
            'hiddenRowsCount' => max(0, count($rows) - self::ROW_LIMIT),
            'rowLimit' => self::ROW_LIMIT,
            'emptyReason' => $report['emptyReason'],
            'fullUrl' => OneCReconciliation::getUrl([
                'from' => $fromDate,
                'to' => $toDate,
            ]),
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
     * @return array{0:string,1:string}
     */
    private function resolveDateRange(): array
    {
        $raw = $this->resolveDashboardFilterMonthRaw();
        $month = is_string($raw) && preg_match('/^\d{4}-\d{2}$/', $raw) === 1
            ? $raw
            : CarbonImmutable::now()->format('Y-m');

        $start = CarbonImmutable::createFromFormat('!Y-m', $month)->startOfMonth();

        return [$start->toDateString(), $start->endOfMonth()->toDateString()];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyData(string $reason): array
    {
        return [
            'periodLabel' => '—',
            'rows' => [],
            'summary' => [
                'accrued' => 0.0,
                'paid' => 0.0,
                'total' => 0.0,
                'accrual_count' => 0,
                'payment_count' => 0,
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
