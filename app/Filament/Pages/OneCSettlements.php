<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Resources\TenantContractResource;
use App\Filament\Resources\TenantResource;
use App\Support\OneC\AccrualPaymentReconciliationReport;
use App\Support\AdminCapabilities;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OneCSettlements extends Page
{
    protected static ?string $title = 'Расчеты 1С';

    protected static ?string $navigationLabel = 'Расчеты 1С';

    protected static \UnitEnum|string|null $navigationGroup = null;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-scale';

    protected static ?int $navigationSort = 97;

    protected static ?string $slug = '1c-settlements';

    protected string $view = 'filament.pages.one-c-settlements';

    public ?string $fromDate = null;

    public ?string $toDate = null;

    public string $account = '62';

    public string $status = 'all';

    public string $search = '';

    public ?int $tenantId = null;

    public string $perPage = '10';

    public int $page = 1;

    protected $queryString = [
        'fromDate' => ['except' => null, 'as' => 'from'],
        'toDate' => ['except' => null, 'as' => 'to'],
        'account' => ['except' => '62'],
        'status' => ['except' => 'all'],
        'search' => ['except' => ''],
        'tenantId' => ['except' => null],
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
        return false;
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
        $this->status = $this->normalizeStatus($this->status);
        $this->account = $this->normalizeAccount($this->account);
        $this->tenantId = $this->normalizeTenantId($this->tenantId);

        if (($this->fromDate === null || $this->toDate === null) && $marketId !== null) {
            [$from, $to, $account] = $this->defaultSnapshot($marketId);
            $this->fromDate ??= $from;
            $this->toDate ??= $to;
            $this->account = $this->account !== '' ? $this->account : $account;
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

    public function updatedAccount(): void
    {
        $this->account = $this->normalizeAccount($this->account);
        $this->page = 1;
    }

    public function updatedStatus(): void
    {
        $this->status = $this->normalizeStatus($this->status);
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
     * @return array<string, mixed>
     */
    public function getReport(): array
    {
        $marketId = $this->marketId();

        if ($marketId === null) {
            return $this->emptyReport('Выберите рынок');
        }

        if (! Schema::hasTable('tenant_settlement_balances')) {
            return $this->emptyReport('Таблица расчетов 1С еще не создана');
        }

        [$fromDate, $toDate] = $this->normalizedDateRange($this->fromDate, $this->toDate);
        $this->fromDate = $fromDate;
        $this->toDate = $toDate;
        $account = $this->normalizeAccount($this->account);
        $this->account = $account;

        $summary = $this->summaryQuery($marketId, $fromDate, $toDate, $account)->first();
        $accounts = $this->accountsForPeriod($marketId, $fromDate, $toDate);
        $organizationRows = $this->organizationRows($marketId, $fromDate, $toDate, $account);
        $unresolvedRows = $this->unresolvedRows($marketId, $fromDate, $toDate, $account);

        $filteredQuery = $this->groupedRowsQuery($marketId, $fromDate, $toDate, $account);
        $this->applyTenantScope($filteredQuery, $this->tenantId);
        $this->applySearch($filteredQuery, $this->search);
        $this->applyStatus($filteredQuery, $this->status);

        $filteredRows = $filteredQuery->get();
        $total = $filteredRows->count();

        $perPage = $this->normalizePerPage($this->perPage);
        $lastPage = $perPage === 'all' ? 1 : max(1, (int) ceil($total / (int) $perPage));
        $this->page = min(max(1, $this->page), $lastPage);

        $displayRows = $perPage === 'all'
            ? $filteredRows
            : $filteredRows->slice(($this->page - 1) * (int) $perPage, (int) $perPage);

        return [
            'periodLabel' => $this->formatPeriodLabel($fromDate, $toDate),
            'summary' => $this->normalizeSummary($summary),
            'filteredSummary' => $this->summarizeGroupedRows($filteredRows),
            'tenantContext' => $this->tenantContext($this->tenantId, $marketId),
            'accounts' => $accounts,
            'organizationRows' => $organizationRows,
            'unresolvedRows' => $unresolvedRows,
            'rows' => $displayRows->map(fn (object $row): array => $this->formatGroupedRow($row))->values()->all(),
            'pagination' => $this->paginationMeta($total, $perPage, $this->page),
            'emptyReason' => ((int) ($summary->rows ?? 0)) === 0 ? 'За выбранный период данные ОСВ 1С не загружены' : null,
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
     * @return array{0:string,1:string,2:string}
     */
    private function defaultSnapshot(int $marketId): array
    {
        if (! Schema::hasTable('tenant_settlement_balances')) {
            $now = CarbonImmutable::now();

            return [$now->startOfMonth()->toDateString(), $now->endOfMonth()->toDateString(), '62'];
        }

        $latest = DB::table('tenant_settlement_balances')
            ->where('market_id', $marketId)
            ->orderByDesc('period_to')
            ->orderByDesc('imported_at')
            ->first(['period_from', 'period_to', 'account']);

        if (! $latest) {
            $now = CarbonImmutable::now();

            return [$now->startOfMonth()->toDateString(), $now->endOfMonth()->toDateString(), '62'];
        }

        return [
            (string) $latest->period_from,
            (string) $latest->period_to,
            (string) ($latest->account ?: '62'),
        ];
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

    private function normalizePerPage(mixed $value): string
    {
        $value = (string) $value;

        return in_array($value, ['10', '25', '50', '100', 'all'], true) ? $value : '10';
    }

    private function normalizeStatus(mixed $value): string
    {
        $value = (string) $value;

        return in_array($value, ['all', 'debt', 'credit', 'zero', 'unlinked'], true) ? $value : 'all';
    }

    private function normalizeAccount(mixed $value): string
    {
        $value = trim((string) $value);

        return $value !== '' ? mb_substr($value, 0, 32) : '62';
    }

    private function normalizeTenantId(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $id = (int) $value;

        return $id > 0 ? $id : null;
    }

    private function baseQuery(int $marketId, string $fromDate, string $toDate, string $account): Builder
    {
        return DB::table('tenant_settlement_balances')
            ->where('market_id', $marketId)
            ->where('period_from', $fromDate)
            ->where('period_to', $toDate)
            ->where('account', $account);
    }

    private function summaryQuery(int $marketId, string $fromDate, string $toDate, string $account): Builder
    {
        return $this->baseQuery($marketId, $fromDate, $toDate, $account)
            ->selectRaw('count(*) as rows')
            ->selectRaw('count(distinct tenant_external_id) as tenants')
            ->selectRaw('count(distinct contract_external_id) as contracts')
            ->selectRaw('count(distinct organization_external_id) as organizations')
            ->selectRaw('coalesce(sum(opening_debit),0) as opening_debit')
            ->selectRaw('coalesce(sum(opening_credit),0) as opening_credit')
            ->selectRaw('coalesce(sum(turnover_debit),0) as turnover_debit')
            ->selectRaw('coalesce(sum(turnover_credit),0) as turnover_credit')
            ->selectRaw('coalesce(sum(closing_debit),0) as closing_debit')
            ->selectRaw('coalesce(sum(closing_credit),0) as closing_credit')
            ->selectRaw('max(imported_at) as imported_at');
    }

    private function groupedRowsQuery(int $marketId, string $fromDate, string $toDate, string $account): Builder
    {
        return $this->baseQuery($marketId, $fromDate, $toDate, $account)
            ->select([
                'tenant_id',
                'tenant_contract_id',
                'tenant_external_id',
                'tenant_name',
                'contract_external_id',
                'contract_name',
                'organization_name',
                'account',
            ])
            ->selectRaw('count(*) as rows_count')
            ->selectRaw('coalesce(sum(opening_debit),0) as opening_debit')
            ->selectRaw('coalesce(sum(opening_credit),0) as opening_credit')
            ->selectRaw('coalesce(sum(turnover_debit),0) as turnover_debit')
            ->selectRaw('coalesce(sum(turnover_credit),0) as turnover_credit')
            ->selectRaw('coalesce(sum(closing_debit),0) as closing_debit')
            ->selectRaw('coalesce(sum(closing_credit),0) as closing_credit')
            ->groupBy([
                'tenant_id',
                'tenant_contract_id',
                'tenant_external_id',
                'tenant_name',
                'contract_external_id',
                'contract_name',
                'organization_name',
                'account',
            ])
            ->orderByRaw('(coalesce(sum(closing_debit),0) - coalesce(sum(closing_credit),0)) desc')
            ->orderBy('tenant_name')
            ->orderBy('contract_name');
    }

    private function applySearch(Builder $query, string $search): void
    {
        $search = mb_strtolower(trim($search));

        if ($search === '') {
            return;
        }

        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';

        $query->where(function (Builder $nested) use ($like): void {
            $nested
                ->whereRaw('lower(coalesce(tenant_name, ?)) like ?', ['', $like])
                ->orWhereRaw('lower(coalesce(contract_name, ?)) like ?', ['', $like])
                ->orWhereRaw('lower(coalesce(organization_name, ?)) like ?', ['', $like])
                ->orWhereRaw('lower(coalesce(settlement_document_name, ?)) like ?', ['', $like]);
        });
    }

    private function applyTenantScope(Builder $query, ?int $tenantId): void
    {
        if ($tenantId === null) {
            return;
        }

        $query->where('tenant_id', $tenantId);
    }

    private function applyStatus(Builder $query, string $status): void
    {
        match ($status) {
            'debt' => $query->havingRaw('(coalesce(sum(closing_debit),0) - coalesce(sum(closing_credit),0)) > 0.009'),
            'credit' => $query->havingRaw('(coalesce(sum(closing_credit),0) - coalesce(sum(closing_debit),0)) > 0.009'),
            'zero' => $query->havingRaw('abs(coalesce(sum(closing_debit),0) - coalesce(sum(closing_credit),0)) <= 0.009'),
            'unlinked' => $query->whereNull('tenant_contract_id'),
            default => null,
        };
    }

    /**
     * @return list<string>
     */
    private function accountsForPeriod(int $marketId, string $fromDate, string $toDate): array
    {
        return DB::table('tenant_settlement_balances')
            ->where('market_id', $marketId)
            ->where('period_from', $fromDate)
            ->where('period_to', $toDate)
            ->distinct()
            ->orderBy('account')
            ->pluck('account')
            ->map(fn (mixed $value): string => (string) $value)
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function organizationRows(int $marketId, string $fromDate, string $toDate, string $account): array
    {
        return $this->baseQuery($marketId, $fromDate, $toDate, $account)
            ->select('organization_name')
            ->selectRaw('count(*) as rows')
            ->selectRaw('count(distinct tenant_external_id) as tenants')
            ->selectRaw('coalesce(sum(opening_debit),0) as opening_debit')
            ->selectRaw('coalesce(sum(opening_credit),0) as opening_credit')
            ->selectRaw('coalesce(sum(turnover_debit),0) as turnover_debit')
            ->selectRaw('coalesce(sum(turnover_credit),0) as turnover_credit')
            ->selectRaw('coalesce(sum(closing_debit),0) as closing_debit')
            ->selectRaw('coalesce(sum(closing_credit),0) as closing_credit')
            ->groupBy('organization_name')
            ->orderBy('organization_name')
            ->get()
            ->map(fn (object $row): array => $this->normalizeSummary($row))
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function unresolvedRows(int $marketId, string $fromDate, string $toDate, string $account): array
    {
        return $this->baseQuery($marketId, $fromDate, $toDate, $account)
            ->whereNull('tenant_contract_id')
            ->select('tenant_name', 'contract_name', 'settlement_document_name', 'closing_debit', 'closing_credit', 'organization_name')
            ->orderBy('tenant_name')
            ->orderBy('contract_name')
            ->limit(10)
            ->get()
            ->map(fn (object $row): array => [
                'tenant_name' => (string) ($row->tenant_name ?? '—'),
                'contract_name' => (string) ($row->contract_name ?? '—'),
                'settlement_document_name' => (string) ($row->settlement_document_name ?? '—'),
                'closing_debit' => (float) $row->closing_debit,
                'closing_credit' => (float) $row->closing_credit,
                'organization_name' => (string) ($row->organization_name ?? '—'),
            ])
            ->values()
            ->all();
    }

    /**
     * @param \Illuminate\Support\Collection<int, object> $rows
     * @return array<string, float|int>
     */
    private function summarizeGroupedRows($rows): array
    {
        return [
            'rows' => $rows->count(),
            'tenants' => $rows->pluck('tenant_external_id')->filter()->unique()->count(),
            'contracts' => $rows->pluck('contract_external_id')->filter()->unique()->count(),
            'opening_debit' => (float) $rows->sum('opening_debit'),
            'opening_credit' => (float) $rows->sum('opening_credit'),
            'turnover_debit' => (float) $rows->sum('turnover_debit'),
            'turnover_credit' => (float) $rows->sum('turnover_credit'),
            'closing_debit' => (float) $rows->sum('closing_debit'),
            'closing_credit' => (float) $rows->sum('closing_credit'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeSummary(?object $row): array
    {
        return [
            'organization_name' => (string) ($row->organization_name ?? ''),
            'rows' => (int) ($row->rows ?? 0),
            'tenants' => (int) ($row->tenants ?? 0),
            'contracts' => (int) ($row->contracts ?? 0),
            'organizations' => (int) ($row->organizations ?? 0),
            'opening_debit' => (float) ($row->opening_debit ?? 0),
            'opening_credit' => (float) ($row->opening_credit ?? 0),
            'turnover_debit' => (float) ($row->turnover_debit ?? 0),
            'turnover_credit' => (float) ($row->turnover_credit ?? 0),
            'closing_debit' => (float) ($row->closing_debit ?? 0),
            'closing_credit' => (float) ($row->closing_credit ?? 0),
            'imported_at' => $row->imported_at ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatGroupedRow(object $row): array
    {
        $net = (float) $row->closing_debit - (float) $row->closing_credit;

        return [
            'tenant_name' => (string) ($row->tenant_name ?? '—'),
            'tenant_url' => $row->tenant_id ? TenantResource::getUrl('edit', ['record' => (int) $row->tenant_id]) : null,
            'contract_name' => (string) ($row->contract_name ?? '—'),
            'contract_url' => $row->tenant_contract_id ? TenantContractResource::getUrl('edit', ['record' => (int) $row->tenant_contract_id]) : null,
            'organization_name' => (string) ($row->organization_name ?? '—'),
            'account' => (string) ($row->account ?? ''),
            'rows_count' => (int) $row->rows_count,
            'opening_debit' => (float) $row->opening_debit,
            'opening_credit' => (float) $row->opening_credit,
            'turnover_debit' => (float) $row->turnover_debit,
            'turnover_credit' => (float) $row->turnover_credit,
            'closing_debit' => (float) $row->closing_debit,
            'closing_credit' => (float) $row->closing_credit,
            'net' => $net,
            'status' => $net > 0.009 ? 'debt' : ($net < -0.009 ? 'credit' : 'zero'),
            'linked' => filled($row->tenant_contract_id),
        ];
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

    /**
     * @return array<string, mixed>
     */
    private function emptyReport(string $reason): array
    {
        return [
            'periodLabel' => '—',
            'summary' => $this->normalizeSummary(null),
            'filteredSummary' => $this->summarizeGroupedRows(collect()),
            'accounts' => [],
            'organizationRows' => [],
            'unresolvedRows' => [],
            'rows' => [],
            'pagination' => $this->paginationMeta(0, '10', 1),
            'tenantContext' => null,
            'emptyReason' => $reason,
        ];
    }

    /**
     * @return array{name:string,url:string}|null
     */
    private function tenantContext(?int $tenantId, int $marketId): ?array
    {
        if ($tenantId === null) {
            return null;
        }

        $tenant = DB::table('tenants')
            ->where('id', $tenantId)
            ->where('market_id', $marketId)
            ->first(['id', 'name', 'short_name']);

        if (! $tenant) {
            return null;
        }

        $name = trim((string) ($tenant->short_name ?: $tenant->name));

        return [
            'name' => $name !== '' ? $name : ('Арендатор #' . $tenantId),
            'url' => TenantResource::getUrl('edit', ['record' => $tenantId]),
        ];
    }

    private function formatPeriodLabel(string $fromDate, string $toDate): string
    {
        return CarbonImmutable::parse($fromDate)->format('d.m.Y')
            . ' - '
            . CarbonImmutable::parse($toDate)->format('d.m.Y');
    }
}
