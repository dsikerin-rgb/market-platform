<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Support\OneC\AccrualPaymentReconciliationReport;
use Filament\Facades\Filament;
use Filament\Pages\Page;

class OneCReconciliation extends Page
{
    protected static ?string $title = 'Сверка 1С';

    protected static ?string $navigationLabel = 'Сверка 1С';

    protected static \UnitEnum|string|null $navigationGroup = null;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-scale';

    protected static ?int $navigationSort = 96;

    protected static ?string $slug = '1c-reconciliation';

    protected string $view = 'filament.pages.one-c-reconciliation';

    public ?string $period = null;

    public string $status = 'all';

    public string $search = '';

    public string $perPage = '10';

    public int $page = 1;

    protected $queryString = [
        'period' => ['except' => null],
        'status' => ['except' => 'all'],
        'search' => ['except' => ''],
        'perPage' => ['except' => '10'],
        'page' => ['except' => 1],
    ];

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return (bool) $user && (
            (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin())
            || (bool) ($user->market_id ?? null)
        );
    }

    public function mount(): void
    {
        $marketId = $this->marketId();
        $this->perPage = $this->normalizePerPage($this->perPage);
        $this->page = max(1, $this->page);

        if ($this->period === null && $marketId !== null) {
            $this->period = app(AccrualPaymentReconciliationReport::class)->latestDataMonth($marketId);
        }
    }

    public function updatedPeriod(): void
    {
        $this->page = 1;
    }

    public function updatedStatus(): void
    {
        $this->page = 1;
    }

    public function updatedSearch(): void
    {
        $this->page = 1;
    }

    public function updatedPerPage(): void
    {
        $this->perPage = $this->normalizePerPage($this->perPage);
        $this->page = 1;
    }

    public function previousPage(): void
    {
        $this->page = max(1, $this->page - 1);
    }

    public function nextPage(): void
    {
        $this->page++;
    }

    /**
     * @return array{
     *     monthYm:string,
     *     monthLabel:string,
     *     rows:list<array<string, mixed>>,
     *     filteredRows:list<array<string, mixed>>,
     *     displayRows:list<array<string, mixed>>,
     *     tenantGroups:list<array<string, mixed>>,
     *     summary:array<string, float|int>,
     *     filteredSummary:array<string, float|int>,
     *     pagination:array<string, int|string|bool>,
     *     emptyReason:string|null,
     *     marketMissing:bool
     * }
     */
    public function getReport(): array
    {
        $marketId = $this->marketId();

        if ($marketId === null) {
            return [
                'monthYm' => '',
                'monthLabel' => '—',
                'rows' => [],
                'filteredRows' => [],
                'displayRows' => [],
                'tenantGroups' => [],
                'summary' => $this->emptySummary(),
                'filteredSummary' => $this->emptySummary(),
                'pagination' => $this->paginationMeta(0, '10', 1),
                'emptyReason' => 'Выберите рынок',
                'marketMissing' => true,
            ];
        }

        $service = app(AccrualPaymentReconciliationReport::class);
        $report = $service->build($marketId, $this->period);
        $filteredRows = $service->filterRows(
            $report['rows'],
            $this->status,
            $this->search,
        );
        $filteredRows = $this->prepareRowsForDisplay($filteredRows);
        $perPage = $this->normalizePerPage($this->perPage);
        $total = count($filteredRows);
        $lastPage = $perPage === 'all' ? 1 : max(1, (int) ceil($total / (int) $perPage));
        $this->page = min(max(1, $this->page), $lastPage);
        $displayRows = $perPage === 'all'
            ? $filteredRows
            : array_slice($filteredRows, ($this->page - 1) * (int) $perPage, (int) $perPage);

        return [
            'monthYm' => $report['monthYm'],
            'monthLabel' => $report['monthLabel'],
            'rows' => $report['rows'],
            'filteredRows' => $filteredRows,
            'displayRows' => $displayRows,
            'tenantGroups' => $this->groupRowsByTenant($displayRows),
            'summary' => $report['summary'],
            'filteredSummary' => $service->summarize($filteredRows),
            'pagination' => $this->paginationMeta($total, $perPage, $this->page),
            'emptyReason' => $report['emptyReason'],
            'marketMissing' => false,
        ];
    }

    private function marketId(): ?int
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return null;
        }

        return app(AccrualPaymentReconciliationReport::class)->resolveMarketIdForUser($user);
    }

    /**
     * @return array<string, float|int>
     */
    private function emptySummary(): array
    {
        return [
            'accrued' => 0.0,
            'paid' => 0.0,
            'delta' => 0.0,
            'debt_count' => 0,
            'overpaid_count' => 0,
            'closed_count' => 0,
            'rows_count' => 0,
        ];
    }

    private function normalizePerPage(mixed $value): string
    {
        $value = (string) $value;

        return in_array($value, ['10', '25', '50', '100', 'all'], true) ? $value : '10';
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function prepareRowsForDisplay(array $rows): array
    {
        if ($this->status !== 'all') {
            return $rows;
        }

        usort($rows, static function (array $left, array $right): int {
            $tenant = strcmp((string) $left['tenant_name'], (string) $right['tenant_name']);

            if ($tenant !== 0) {
                return $tenant;
            }

            $leftContract = (string) $left['contract_label'];
            $rightContract = (string) $right['contract_label'];
            $contract = strcmp($leftContract, $rightContract);

            if ($contract !== 0) {
                return $contract;
            }

            return strcmp((string) ($left['contract_external_id'] ?? ''), (string) ($right['contract_external_id'] ?? ''));
        });

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array{
     *     tenant_key:string,
     *     tenant_name:string,
     *     tenant_url:string|null,
     *     summary:array<string, float|int>,
     *     rows:list<array<string, mixed>>
     * }>
     */
    private function groupRowsByTenant(array $rows): array
    {
        $groups = [];

        foreach ($rows as $row) {
            $tenantKey = (string) ($row['tenant_id'] ?? 'none');

            if (! array_key_exists($tenantKey, $groups)) {
                $groups[$tenantKey] = [
                    'tenant_key' => $tenantKey,
                    'tenant_name' => (string) $row['tenant_name'],
                    'tenant_url' => $row['tenant_url'] ? (string) $row['tenant_url'] : null,
                    'summary' => $this->emptySummary(),
                    'rows' => [],
                ];
            }

            $groups[$tenantKey]['rows'][] = $row;
            $groups[$tenantKey]['summary']['accrued'] += (float) $row['accrued'];
            $groups[$tenantKey]['summary']['paid'] += (float) $row['paid'];
            $groups[$tenantKey]['summary']['delta'] += (float) $row['delta'];
            $groups[$tenantKey]['summary']['rows_count']++;
            $groups[$tenantKey]['summary']['debt_count'] += $row['status'] === 'debt' ? 1 : 0;
            $groups[$tenantKey]['summary']['overpaid_count'] += $row['status'] === 'overpaid' ? 1 : 0;
            $groups[$tenantKey]['summary']['closed_count'] += $row['status'] === 'closed' ? 1 : 0;
        }

        return array_values($groups);
    }

    /**
     * @return array<string, int|string|bool>
     */
    private function paginationMeta(int $total, string $perPage, int $page): array
    {
        $lastPage = $perPage === 'all' ? 1 : max(1, (int) ceil($total / (int) $perPage));
        $from = $total === 0 ? 0 : (($page - 1) * ($perPage === 'all' ? $total : (int) $perPage)) + 1;
        $to = $perPage === 'all'
            ? $total
            : min($total, $page * (int) $perPage);

        return [
            'total' => $total,
            'perPage' => $perPage,
            'page' => $page,
            'lastPage' => $lastPage,
            'from' => $from,
            'to' => $to,
            'hasPrevious' => $page > 1,
            'hasNext' => $page < $lastPage,
        ];
    }
}
