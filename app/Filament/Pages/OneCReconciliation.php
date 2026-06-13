<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Support\OneC\AccrualPaymentReconciliationReport;
use App\Support\AdminCapabilities;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Pages\Page;

class OneCReconciliation extends Page
{
    protected static ?string $title = 'Журнал документов 1С';

    protected static ?string $navigationLabel = 'Документы 1С';

    protected static \UnitEnum|string|null $navigationGroup = null;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-document-text';

    protected static ?int $navigationSort = 96;

    protected static ?string $slug = '1c-reconciliation';

    protected string $view = 'filament.pages.one-c-reconciliation';

    public ?string $fromDate = null;

    public ?string $toDate = null;

    public string $type = 'all';

    public string $search = '';

    public string $perPage = '10';

    public int $page = 1;

    protected $queryString = [
        'fromDate' => ['except' => null, 'as' => 'from'],
        'toDate' => ['except' => null, 'as' => 'to'],
        'type' => ['except' => 'all'],
        'search' => ['except' => ''],
        'perPage' => ['except' => '10'],
        'page' => ['except' => 1],
    ];

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return AdminCapabilities::canViewFinance($user);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function getHeading(): string|\Illuminate\Contracts\Support\Htmlable|null
    {
        return null;
    }

    public function getSubheading(): ?string
    {
        return null;
    }

    public function mount(): void
    {
        $marketId = $this->marketId();
        $this->perPage = $this->normalizePerPage($this->perPage);
        $this->page = max(1, $this->page);
        $this->type = $this->normalizeType($this->type);

        if (($this->fromDate === null || $this->toDate === null) && $marketId !== null) {
            [$from, $to] = app(AccrualPaymentReconciliationReport::class)->defaultDateRange($marketId);
            $this->fromDate ??= $from;
            $this->toDate ??= $to;
        }
    }

    public function updatedFromDate(): void
    {
        $this->page = 1;
    }

    public function updatedToDate(): void
    {
        $this->page = 1;
    }

    public function updatedType(): void
    {
        $this->type = $this->normalizeType($this->type);
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
     *     periodLabel:string,
     *     rows:list<array<string, mixed>>,
     *     filteredRows:list<array<string, mixed>>,
     *     displayRows:list<array<string, mixed>>,
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
                'periodLabel' => '—',
                'rows' => [],
                'filteredRows' => [],
                'displayRows' => [],
                'summary' => $this->emptySummary(),
                'filteredSummary' => $this->emptySummary(),
                'pagination' => $this->paginationMeta(0, '10', 1),
                'emptyReason' => 'Выберите рынок',
                'marketMissing' => true,
            ];
        }

        [$fromDate, $toDate] = $this->normalizedDateRange($this->fromDate, $this->toDate);
        $this->fromDate = $fromDate;
        $this->toDate = $toDate;

        $service = app(AccrualPaymentReconciliationReport::class);
        $report = $service->build($marketId, $fromDate, $toDate);
        $filteredRows = $service->filterRows(
            $report['rows'],
            $this->type,
            $this->search,
        );

        $perPage = $this->normalizePerPage($this->perPage);
        $total = count($filteredRows);
        $lastPage = $perPage === 'all' ? 1 : max(1, (int) ceil($total / (int) $perPage));
        $this->page = min(max(1, $this->page), $lastPage);
        $displayRows = $perPage === 'all'
            ? $filteredRows
            : array_slice($filteredRows, ($this->page - 1) * (int) $perPage, (int) $perPage);

        return [
            'periodLabel' => $report['periodLabel'],
            'rows' => $report['rows'],
            'filteredRows' => $filteredRows,
            'displayRows' => $displayRows,
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
            'total' => 0.0,
            'accrual_count' => 0,
            'payment_count' => 0,
            'rows_count' => 0,
        ];
    }

    private function normalizePerPage(mixed $value): string
    {
        $value = (string) $value;

        return in_array($value, ['10', '25', '50', '100', 'all'], true) ? $value : '10';
    }

    private function normalizeType(mixed $value): string
    {
        $value = (string) $value;

        return in_array($value, ['all', 'accrual', 'payment'], true) ? $value : 'all';
    }

    /**
     * @return array{0:string,1:string}
     */
    private function normalizedDateRange(?string $fromDate, ?string $toDate): array
    {
        $from = $this->parseDate($fromDate) ?? CarbonImmutable::now()->startOfMonth();
        $to = $this->parseDate($toDate) ?? $from->endOfMonth();

        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }

        return [$from->toDateString(), $to->toDateString()];
    }

    private function parseDate(?string $value): ?CarbonImmutable
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
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
