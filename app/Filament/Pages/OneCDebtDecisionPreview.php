<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Resources\MarketSpaceResource;
use App\Filament\Resources\TenantResource;
use App\Services\Debt\DebtDecisionPolicy;
use App\Services\Debt\DebtDecisionPreviewReport;
use App\Support\OneC\AccrualPaymentReconciliationReport;
use App\Support\AdminCapabilities;
use App\Support\Search\LooseSearch;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OneCDebtDecisionPreview extends Page
{
    protected static ?string $title = 'Предпросмотр цветов ОСВ';

    protected static ?string $navigationLabel = 'Цвета ОСВ';

    protected static \UnitEnum|string|null $navigationGroup = null;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-eye';

    protected static ?int $navigationSort = 98;

    protected static ?string $slug = '1c-debt-decision-preview';

    protected string $view = 'filament.pages.one-c-debt-decision-preview';

    public string $account = '62';

    public string $agingPolicy = DebtDecisionPolicy::AGING_SETTLEMENT_NET_BALANCE;

    public string $status = 'mismatches';

    public string $search = '';

    public string $perPage = '25';

    public int $page = 1;

    public bool $embedded = false;

    protected $queryString = [
        'account' => ['except' => '62'],
        'agingPolicy' => ['except' => DebtDecisionPolicy::AGING_SETTLEMENT_NET_BALANCE, 'as' => 'aging'],
        'status' => ['except' => 'mismatches'],
        'search' => ['except' => ''],
        'perPage' => ['except' => '25'],
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

    public function mount(bool $embedded = false): void
    {
        $this->embedded = $embedded;
        $this->account = $this->normalizeAccount($this->account);
        $this->agingPolicy = $this->normalizeAgingPolicy($this->agingPolicy);
        $this->status = $this->normalizeStatus($this->status);
        $this->perPage = $this->normalizePerPage($this->perPage);
        $this->page = max(1, $this->page);
    }

    public function updatedAccount(): void
    {
        $this->account = $this->normalizeAccount($this->account);
        $this->page = 1;
    }

    public function updatedAgingPolicy(): void
    {
        $this->agingPolicy = $this->normalizeAgingPolicy($this->agingPolicy);
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
            return $this->emptyReport('Таблица ОСВ 1С еще не создана');
        }

        $account = $this->normalizeAccount($this->account);
        $agingPolicy = $this->normalizeAgingPolicy($this->agingPolicy);
        $this->account = $account;
        $this->agingPolicy = $agingPolicy;

        $raw = app(DebtDecisionPreviewReport::class)->build($marketId, $account, $agingPolicy);
        $rows = collect($raw['rows'] ?? []);

        $rows = $this->applyStatusFilter($rows, $this->status);
        $rows = $this->applySearchFilter($rows, $this->search);

        $total = $rows->count();
        $perPage = $this->normalizePerPage($this->perPage);
        $lastPage = $perPage === 'all' ? 1 : max(1, (int) ceil($total / (int) $perPage));
        $this->page = min(max(1, $this->page), $lastPage);

        $displayRows = $perPage === 'all'
            ? $rows
            : $rows->slice(($this->page - 1) * (int) $perPage, (int) $perPage);

        return [
            'summary' => $raw['summary'],
            'rows' => $displayRows->map(fn (array $row): array => $this->formatRow($row))->values()->all(),
            'accounts' => $this->accounts($marketId),
            'pagination' => $this->paginationMeta($total, $perPage, $this->page),
            'filteredTotal' => $total,
            'emptyReason' => $total === 0 ? 'По выбранным фильтрам строк нет' : null,
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

    private function normalizeAccount(mixed $value): string
    {
        $value = trim((string) $value);

        return $value !== '' ? mb_substr($value, 0, 32) : '62';
    }

    private function normalizeAgingPolicy(mixed $value): string
    {
        $value = (string) $value;

        return in_array($value, [
            DebtDecisionPolicy::AGING_INVOICE_DAY,
            DebtDecisionPolicy::AGING_PERIOD_START,
            DebtDecisionPolicy::AGING_SETTLEMENT_DOCUMENT,
            DebtDecisionPolicy::AGING_SETTLEMENT_DOCUMENT_INVOICE_DAY,
            DebtDecisionPolicy::AGING_SETTLEMENT_NET_BALANCE,
        ], true)
            ? $value
            : DebtDecisionPolicy::AGING_SETTLEMENT_NET_BALANCE;
    }

    private function normalizeStatus(mixed $value): string
    {
        $value = (string) $value;

        return in_array($value, ['all', 'mismatches', 'more_severe', 'less_severe', 'same', 'scope_differs', 'closed_by_osv', 'missing_in_map'], true)
            ? $value
            : 'mismatches';
    }

    private function normalizePerPage(mixed $value): string
    {
        $value = (string) $value;

        return in_array($value, ['10', '25', '50', '100', 'all'], true) ? $value : '25';
    }

    /**
     * @param Collection<int, array<string, mixed>> $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function applyStatusFilter(Collection $rows, string $status): Collection
    {
        return (match ($this->normalizeStatus($status)) {
            'mismatches' => $rows->filter(fn (array $row): bool => (bool) $row['mismatch']),
            'more_severe' => $rows->filter(fn (array $row): bool => $row['severity_change'] === 'more_severe'),
            'less_severe' => $rows->filter(fn (array $row): bool => $row['severity_change'] === 'less_severe'),
            'same' => $rows->filter(fn (array $row): bool => ! (bool) $row['mismatch']),
            'scope_differs' => $rows->filter(fn (array $row): bool => $row['mismatch_reason'] === 'scope_differs'),
            'closed_by_osv' => $rows->filter(fn (array $row): bool => $row['mismatch_reason'] === 'osv_closed_or_credit_while_current_map_has_debt'),
            'missing_in_map' => $rows->filter(fn (array $row): bool => $row['mismatch_reason'] === 'osv_has_debt_missing_from_current_map'),
            default => $rows,
        })->values();
    }

    /**
     * @param Collection<int, array<string, mixed>> $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function applySearchFilter(Collection $rows, string $search): Collection
    {
        if (trim($search) === '') {
            return $rows;
        }

        return $rows->filter(static function (array $row) use ($search): bool {
            $candidate = (array) ($row['osv_candidate'] ?? []);
            $haystack = implode(' ', [
                $row['space_number'] ?? '',
                $row['tenant_name'] ?? '',
                $row['mismatch_reason'] ?? '',
                $candidate['reason'] ?? '',
                implode(' ', (array) ($candidate['contract_names'] ?? [])),
                implode(' ', (array) ($candidate['contracts'] ?? [])),
            ]);

            return LooseSearch::matchesText($haystack, $search);
        })->values();
    }

    /**
     * @return list<string>
     */
    private function accounts(int $marketId): array
    {
        return DB::table('tenant_settlement_balances')
            ->where('market_id', $marketId)
            ->distinct()
            ->orderBy('account')
            ->pluck('account')
            ->map(fn (mixed $value): string => (string) $value)
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatRow(array $row): array
    {
        $candidate = (array) ($row['osv_candidate'] ?? []);
        $current = (array) ($row['current_map'] ?? []);
        $spaceId = (int) ($row['space_id'] ?? 0);
        $tenantId = (int) ($row['tenant_id'] ?? 0);

        return [
            'space_number' => (string) ($row['space_number'] ?? ''),
            'space_url' => $spaceId > 0 ? MarketSpaceResource::getUrl('edit', ['record' => $spaceId]) : null,
            'tenant_name' => (string) ($row['tenant_name'] ?? ''),
            'tenant_url' => $tenantId > 0 ? TenantResource::getUrl('edit', ['record' => $tenantId]) : null,
            'current_status' => (string) ($current['status'] ?? 'none'),
            'current_scope' => (string) ($current['scope'] ?? 'none'),
            'current_debt_amount' => is_numeric($current['debt_amount'] ?? null) ? (float) $current['debt_amount'] : null,
            'candidate_status' => (string) ($candidate['status'] ?? 'none'),
            'candidate_scope' => (string) ($candidate['scope'] ?? 'none'),
            'candidate_debt_amount' => (float) ($candidate['debt_amount'] ?? 0),
            'candidate_due_date' => $candidate['due_date'] ?? null,
            'candidate_overdue_days' => $candidate['overdue_days'] ?? null,
            'contract_names' => (array) ($candidate['contract_names'] ?? []),
            'mismatch' => (bool) ($row['mismatch'] ?? false),
            'mismatch_reason' => $row['mismatch_reason'] ?? null,
            'mismatch_label' => $this->reasonLabel($row['mismatch_reason'] ?? null),
            'severity_change' => (string) ($row['severity_change'] ?? 'same_severity'),
            'severity_label' => $this->severityLabel((string) ($row['severity_change'] ?? 'same_severity')),
            'reason' => (string) ($candidate['reason'] ?? ''),
        ];
    }

    private function reasonLabel(?string $reason): string
    {
        return match ($reason) {
            'osv_closed_or_credit_while_current_map_has_debt' => 'ОСВ закрывает долг, карта показывает долг',
            'osv_has_debt_missing_from_current_map' => 'В ОСВ есть долг, на карте его нет',
            'current_map_more_severe_than_osv' => 'Карта строже ОСВ',
            'osv_document_date_makes_debt_much_older' => 'Дата документа делает долг старше',
            'scope_differs' => 'Отличается привязка к месту/арендатору',
            'debt_amount_differs' => 'Отличается сумма долга',
            'status_bucket_differs' => 'Отличается цветовой диапазон',
            'same_status', null => 'Совпадает',
            default => (string) $reason,
        };
    }

    private function severityLabel(string $severityChange): string
    {
        return match ($severityChange) {
            'more_severe' => 'ОСВ строже',
            'less_severe' => 'ОСВ мягче',
            default => 'Без изменения',
        };
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
            'summary' => [
                'active_spaces_with_tenant' => 0,
                'mismatches' => 0,
                'current_map_statuses' => [],
                'osv_candidate_statuses' => [],
                'mismatch_reasons' => [],
                'severity_changes' => [],
            ],
            'rows' => [],
            'accounts' => [],
            'pagination' => $this->paginationMeta(0, '25', 1),
            'filteredTotal' => 0,
            'emptyReason' => $reason,
        ];
    }
}
