<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Resources\TenantContractResource;
use App\Filament\Resources\TenantResource;
use App\Filament\Widgets\Concerns\ResolvesDashboardFilterMonth;
use App\Models\Market;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OneCAccrualPaymentReconciliationDetailWidget extends Widget
{
    use InteractsWithPageFilters;
    use ResolvesDashboardFilterMonth;

    protected string $view = 'filament.widgets.one-c-accrual-payment-reconciliation-detail-widget';

    protected int|string|array $columnSpan = 'full';

    private const ROW_LIMIT = 50;

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

        if (! Schema::hasTable('tenant_accruals') && ! Schema::hasTable('tenant_payments')) {
            return $this->emptyData('Нет данных 1С');
        }

        $market = Market::query()
            ->select(['id', 'timezone'])
            ->find($marketId);
        $tz = $this->resolveTimezone($market?->timezone);
        [$monthYm, $monthStart, $monthEnd] = $this->resolveMonthRange($tz, $marketId);
        $rows = $this->buildRows($marketId, $monthStart, $monthEnd);

        $summary = [
            'accrued' => 0.0,
            'paid' => 0.0,
            'delta' => 0.0,
            'debt_count' => 0,
            'overpaid_count' => 0,
            'closed_count' => 0,
            'rows_count' => count($rows),
        ];

        foreach ($rows as $row) {
            $summary['accrued'] += $row['accrued'];
            $summary['paid'] += $row['paid'];
            $summary['delta'] += $row['delta'];

            if ($row['status'] === 'debt') {
                $summary['debt_count']++;
            } elseif ($row['status'] === 'overpaid') {
                $summary['overpaid_count']++;
            } else {
                $summary['closed_count']++;
            }
        }

        usort($rows, static function (array $left, array $right): int {
            $leftOpen = $left['status'] === 'closed' ? 0 : 1;
            $rightOpen = $right['status'] === 'closed' ? 0 : 1;

            if ($leftOpen !== $rightOpen) {
                return $rightOpen <=> $leftOpen;
            }

            $byDelta = abs($right['delta']) <=> abs($left['delta']);

            if ($byDelta !== 0) {
                return $byDelta;
            }

            return strcmp($left['tenant_name'], $right['tenant_name']);
        });

        $visibleRows = array_slice($rows, 0, self::ROW_LIMIT);

        return [
            'monthLabel' => $this->formatMonthLabel($monthYm, $tz),
            'rows' => $visibleRows,
            'summary' => $summary,
            'hasMoreRows' => count($rows) > self::ROW_LIMIT,
            'hiddenRowsCount' => max(0, count($rows) - self::ROW_LIMIT),
            'rowLimit' => self::ROW_LIMIT,
            'emptyReason' => null,
        ];
    }

    /**
     * @return list<array{
     *     key:string,
     *     tenant_id:int|null,
     *     tenant_name:string,
     *     tenant_url:string|null,
     *     contract_id:int|null,
     *     contract_label:string,
     *     contract_url:string|null,
     *     contract_external_id:string|null,
     *     accrued:float,
     *     paid:float,
     *     delta:float,
     *     status:string,
     *     status_label:string,
     *     accrual_rows:int,
     *     payment_rows:int
     * }>
     */
    private function buildRows(int $marketId, CarbonImmutable $monthStart, CarbonImmutable $monthEnd): array
    {
        $items = [];

        foreach ($this->loadAccrualGroups($marketId, $monthStart, $monthEnd) as $group) {
            $key = $this->makeGroupKey($group['tenant_id'], $group['contract_id'], $group['contract_external_id']);
            $items[$key] ??= $this->makeEmptyItem($key, $group['tenant_id'], $group['contract_id'], $group['contract_external_id']);
            $items[$key]['accrued'] += $group['amount'];
            $items[$key]['accrual_rows'] += $group['rows'];
        }

        foreach ($this->loadPaymentGroups($marketId, $monthStart, $monthEnd) as $group) {
            $key = $this->makeGroupKey($group['tenant_id'], $group['contract_id'], $group['contract_external_id']);
            $items[$key] ??= $this->makeEmptyItem($key, $group['tenant_id'], $group['contract_id'], $group['contract_external_id']);
            $items[$key]['paid'] += $group['amount'];
            $items[$key]['payment_rows'] += $group['rows'];
        }

        $tenantNames = $this->loadTenantNames($items);
        $contractLabels = $this->loadContractLabels($items);

        foreach ($items as &$item) {
            $tenantId = $item['tenant_id'];
            $contractId = $item['contract_id'];

            $item['tenant_name'] = $tenantId !== null
                ? ($tenantNames[$tenantId] ?? ('Арендатор #' . $tenantId))
                : 'Без арендатора';
            $item['tenant_url'] = $tenantId !== null
                ? TenantResource::getUrl('edit', ['record' => $tenantId])
                : null;

            $fallbackContract = $item['contract_external_id'] !== null
                ? ('1С: ' . $item['contract_external_id'])
                : 'Без договора';
            $item['contract_label'] = $contractId !== null
                ? ($contractLabels[$contractId] ?? ('Договор #' . $contractId))
                : $fallbackContract;
            $item['contract_url'] = $contractId !== null
                ? TenantContractResource::getUrl('edit', ['record' => $contractId])
                : null;

            $item['accrued'] = round($item['accrued'], 2);
            $item['paid'] = round($item['paid'], 2);
            $item['delta'] = round($item['accrued'] - $item['paid'], 2);
            [$item['status'], $item['status_label']] = $this->resolveStatus($item['delta']);
        }
        unset($item);

        return array_values($items);
    }

    /**
     * @return list<array{tenant_id:int|null,contract_id:int|null,contract_external_id:string|null,amount:float,rows:int}>
     */
    private function loadAccrualGroups(int $marketId, CarbonImmutable $monthStart, CarbonImmutable $monthEnd): array
    {
        if (! Schema::hasTable('tenant_accruals') || ! Schema::hasColumn('tenant_accruals', 'period')) {
            return [];
        }

        $columns = Schema::getColumnListing('tenant_accruals');
        $amountColumn = $this->pickFirstExisting($columns, ['total_with_vat', 'total_no_vat', 'amount']);

        if ($amountColumn === null) {
            return [];
        }

        $select = ['tenant_id', 'period', $amountColumn];

        foreach (['tenant_contract_id', 'contract_external_id'] as $column) {
            if (in_array($column, $columns, true)) {
                $select[] = $column;
            }
        }

        $query = DB::table('tenant_accruals')
            ->where('market_id', $marketId)
            ->where('period', '>=', $monthStart->toDateString())
            ->where('period', '<', $monthEnd->toDateString());

        if (in_array('source', $columns, true)) {
            $query->where('source', '1c');
        }

        try {
            $rows = $query->get($select);
        } catch (\Throwable) {
            return [];
        }

        return $this->groupRows($rows, $amountColumn);
    }

    /**
     * @return list<array{tenant_id:int|null,contract_id:int|null,contract_external_id:string|null,amount:float,rows:int}>
     */
    private function loadPaymentGroups(int $marketId, CarbonImmutable $monthStart, CarbonImmutable $monthEnd): array
    {
        if (! Schema::hasTable('tenant_payments') || ! Schema::hasColumn('tenant_payments', 'period')) {
            return [];
        }

        $columns = Schema::getColumnListing('tenant_payments');
        $select = ['tenant_id', 'period', 'amount'];

        foreach (['tenant_contract_id', 'contract_external_id'] as $column) {
            if (in_array($column, $columns, true)) {
                $select[] = $column;
            }
        }

        $query = DB::table('tenant_payments')
            ->where('market_id', $marketId)
            ->where('period', '>=', $monthStart->toDateString())
            ->where('period', '<', $monthEnd->toDateString());

        try {
            $rows = $query->get($select);
        } catch (\Throwable) {
            return [];
        }

        return $this->groupRows($rows, 'amount');
    }

    /**
     * @param \Illuminate\Support\Collection<int, object> $rows
     * @return list<array{tenant_id:int|null,contract_id:int|null,contract_external_id:string|null,amount:float,rows:int}>
     */
    private function groupRows($rows, string $amountColumn): array
    {
        $groups = [];

        foreach ($rows as $row) {
            $tenantId = is_numeric($row->tenant_id ?? null) ? (int) $row->tenant_id : null;
            $contractId = is_numeric($row->tenant_contract_id ?? null) ? (int) $row->tenant_contract_id : null;
            $contractExternalId = trim((string) ($row->contract_external_id ?? ''));
            $contractExternalId = $contractExternalId !== '' ? $contractExternalId : null;
            $key = $this->makeGroupKey($tenantId, $contractId, $contractExternalId);

            $groups[$key] ??= [
                'tenant_id' => $tenantId,
                'contract_id' => $contractId,
                'contract_external_id' => $contractExternalId,
                'amount' => 0.0,
                'rows' => 0,
            ];

            $groups[$key]['amount'] += (float) ($row->{$amountColumn} ?? 0.0);
            $groups[$key]['rows']++;
        }

        return array_values($groups);
    }

    /**
     * @param array<string, array<string, mixed>> $items
     * @return array<int, string>
     */
    private function loadTenantNames(array $items): array
    {
        if (! Schema::hasTable('tenants')) {
            return [];
        }

        $ids = array_values(array_unique(array_filter(array_map(
            static fn (array $item): ?int => $item['tenant_id'],
            $items,
        ))));

        if ($ids === []) {
            return [];
        }

        return DB::table('tenants')
            ->whereIn('id', $ids)
            ->get(['id', 'short_name', 'name'])
            ->mapWithKeys(static function (object $row): array {
                $name = trim((string) ($row->short_name ?? '')) ?: trim((string) ($row->name ?? ''));

                return [(int) $row->id => ($name !== '' ? $name : ('Арендатор #' . $row->id))];
            })
            ->all();
    }

    /**
     * @param array<string, array<string, mixed>> $items
     * @return array<int, string>
     */
    private function loadContractLabels(array $items): array
    {
        if (! Schema::hasTable('tenant_contracts')) {
            return [];
        }

        $ids = array_values(array_unique(array_filter(array_map(
            static fn (array $item): ?int => $item['contract_id'],
            $items,
        ))));

        if ($ids === []) {
            return [];
        }

        return DB::table('tenant_contracts')
            ->whereIn('id', $ids)
            ->get(['id', 'number', 'external_id'])
            ->mapWithKeys(static function (object $row): array {
                $number = trim((string) ($row->number ?? ''));
                $externalId = trim((string) ($row->external_id ?? ''));
                $label = $number !== ''
                    ? ('№ ' . $number)
                    : ($externalId !== '' ? ('1С: ' . $externalId) : ('Договор #' . $row->id));

                return [(int) $row->id => $label];
            })
            ->all();
    }

    private function makeGroupKey(?int $tenantId, ?int $contractId, ?string $contractExternalId): string
    {
        if ($contractId !== null) {
            return 'contract:' . $contractId;
        }

        if ($contractExternalId !== null && $contractExternalId !== '') {
            return 'external:' . ($tenantId ?? 0) . ':' . $contractExternalId;
        }

        return 'tenant:' . ($tenantId ?? 0);
    }

    /**
     * @return array<string, mixed>
     */
    private function makeEmptyItem(string $key, ?int $tenantId, ?int $contractId, ?string $contractExternalId): array
    {
        return [
            'key' => $key,
            'tenant_id' => $tenantId,
            'tenant_name' => '',
            'tenant_url' => null,
            'contract_id' => $contractId,
            'contract_label' => '',
            'contract_url' => null,
            'contract_external_id' => $contractExternalId,
            'accrued' => 0.0,
            'paid' => 0.0,
            'delta' => 0.0,
            'status' => 'closed',
            'status_label' => 'Закрыто',
            'accrual_rows' => 0,
            'payment_rows' => 0,
        ];
    }

    /**
     * @return array{0:string,1:string}
     */
    private function resolveStatus(float $delta): array
    {
        if ($delta > 0.009) {
            return ['debt', 'Долг'];
        }

        if ($delta < -0.009) {
            return ['overpaid', 'Переплата'];
        }

        return ['closed', 'Закрыто'];
    }

    /**
     * @return array{0:string,1:CarbonImmutable,2:CarbonImmutable}
     */
    private function resolveMonthRange(string $tz, int $marketId): array
    {
        $raw = $this->resolveDashboardFilterMonthRaw();

        if (is_string($raw) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1) {
            $raw = substr($raw, 0, 7);
        }

        $ym = (is_string($raw) && preg_match('/^\d{4}-\d{2}$/', $raw) === 1)
            ? $raw
            : (string) ($this->resolveLatestDataMonth($marketId) ?: CarbonImmutable::now($tz)->format('Y-m'));

        $start = CarbonImmutable::createFromFormat('!Y-m', $ym, $tz)->startOfMonth();

        return [$ym, $start, $start->addMonth()];
    }

    private function resolveLatestDataMonth(int $marketId): ?string
    {
        $latest = null;

        foreach (['tenant_accruals', 'tenant_payments'] as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'period')) {
                continue;
            }

            try {
                $value = DB::table($table)
                    ->where('market_id', $marketId)
                    ->orderByDesc('period')
                    ->value('period');
            } catch (\Throwable) {
                continue;
            }

            $month = $this->normalizePeriodToMonth($value);

            if ($month !== null && ($latest === null || $month > $latest)) {
                $latest = $month;
            }
        }

        return $latest;
    }

    private function normalizePeriodToMonth(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $raw = trim((string) $value);

        if (preg_match('/^\d{4}-\d{2}/', $raw) === 1) {
            return substr($raw, 0, 7);
        }

        try {
            return CarbonImmutable::parse($raw)->format('Y-m');
        } catch (\Throwable) {
            return null;
        }
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

    private function resolveTimezone(?string $marketTimezone): string
    {
        $tz = trim((string) $marketTimezone);

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

    /**
     * @param list<string> $columns
     * @param list<string> $candidates
     */
    private function pickFirstExisting(array $columns, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (in_array($candidate, $columns, true)) {
                return $candidate;
            }
        }

        return null;
    }

    private function formatMonthLabel(string $ym, string $tz): string
    {
        try {
            return CarbonImmutable::createFromFormat('!Y-m', $ym, $tz)->format('m.Y');
        } catch (\Throwable) {
            return $ym;
        }
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
        ];
    }
}
