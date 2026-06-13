<?php

namespace App\Filament\Pages;

use App\Filament\Resources\ReportResource;
use App\Filament\Resources\ReportRunResource;
use App\Filament\Resources\TenantAccruals\TenantAccrualResource;
use App\Filament\Resources\TenantContractResource;
use App\Filament\Resources\TenantResource;
use App\Models\Market;
use App\Models\Report;
use App\Models\ReportRun;
use App\Support\OneC\AccrualPaymentReconciliationReport;
use App\Support\AdminCapabilities;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ReportsHub extends Page
{
    private const SECTIONS = [
        'templates',
        'runs',
        'accruals',
        'documents',
        'settlements',
    ];

    protected static ?string $title = 'Отчёты';

    protected static ?string $navigationLabel = 'Отчёты';

    protected static \UnitEnum|string|null $navigationGroup = null;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-document-text';

    protected static ?int $navigationSort = 90;

    protected static ?string $slug = 'reports';

    protected string $view = 'filament.pages.reports-hub';

    public string $section = 'templates';

    protected array $queryString = [
        'section' => ['except' => 'templates'],
    ];

    public function mount(): void
    {
        $this->section = $this->normalizeSection($this->section);

        if ($target = $this->redirectUrlForSection($this->section)) {
            $this->redirect($target, navigate: true);
        }
    }

    public function getHeading(): string|\Illuminate\Contracts\Support\Htmlable|null
    {
        return null;
    }

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return AdminCapabilities::canViewFinance($user);
    }

    protected static function getPageRouteName(): string
    {
        $slug = static::$slug ?: 'reports';

        return "filament.admin.pages.{$slug}";
    }

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function getNavigationUrl(): string
    {
        return static::getUrl();
    }

    public function setSection(string $section): void
    {
        $this->section = $this->normalizeSection($section);
    }

    public function getTemplateUrl(): string
    {
        return ReportResource::getUrl('index');
    }

    public function getRunsUrl(): string
    {
        return ReportRunResource::getUrl('index');
    }

    public function getOneCAccrualsUrl(): string
    {
        return TenantAccrualResource::getUrl('index');
    }

    public function getOneCDocumentsUrl(): string
    {
        return OneCReconciliation::getUrl();
    }

    public function getOneCSettlementsUrl(): string
    {
        return OneCSettlements::getUrl();
    }

    public function getMarketName(): string
    {
        $marketId = $this->marketId();

        if ($marketId === null) {
            return 'Выберите рынок';
        }

        return Market::query()
            ->whereKey($marketId)
            ->value('name')
            ?? 'Выберите рынок';
    }

    public function getReportCount(): int
    {
        return Report::query()
            ->when($this->marketId(), fn ($query, $marketId) => $query->where('market_id', $marketId))
            ->count();
    }

    public function getActiveReportCount(): int
    {
        return Report::query()
            ->when($this->marketId(), fn ($query, $marketId) => $query->where('market_id', $marketId))
            ->where('is_active', true)
            ->count();
    }

    public function getRunCount(): int
    {
        return ReportRun::query()
            ->whereHas('report', fn ($query) => $query->when($this->marketId(), fn ($subQuery, $marketId) => $subQuery->where('market_id', $marketId)))
            ->count();
    }

    public function getFailedRunCount(): int
    {
        return ReportRun::query()
            ->whereHas('report', fn ($query) => $query->when($this->marketId(), fn ($subQuery, $marketId) => $subQuery->where('market_id', $marketId)))
            ->whereIn('status', ['failed', 'error'])
            ->count();
    }

    public function getLastRunLabel(): ?string
    {
        $lastRun = ReportRun::query()
            ->whereHas('report', fn ($query) => $query->when($this->marketId(), fn ($subQuery, $marketId) => $subQuery->where('market_id', $marketId)))
            ->latest('started_at')
            ->first(['started_at']);

        return $lastRun?->started_at?->timezone(config('app.timezone'))->format('d.m.Y H:i');
    }

    public function getLatestRunStatusLabel(): ?string
    {
        $lastRun = ReportRun::query()
            ->whereHas('report', fn ($query) => $query->when($this->marketId(), fn ($subQuery, $marketId) => $subQuery->where('market_id', $marketId)))
            ->latest('started_at')
            ->first(['status']);

        if (! filled($lastRun?->status)) {
            return null;
        }

        return Str::of((string) $lastRun->status)
            ->replace(['_', '-'], ' ')
            ->title()
            ->toString();
    }

    /**
     * @return array<string, mixed>
     */
    public function getAccrualsSummary(): array
    {
        $marketId = $this->marketId();

        if ($marketId === null) {
            return $this->emptySummary('Выберите рынок');
        }

        if (! Schema::hasTable('tenant_accruals') || ! Schema::hasColumn('tenant_accruals', 'period')) {
            return $this->emptySummary('Таблица начислений ещё не создана');
        }

        $columns = Schema::getColumnListing('tenant_accruals');
        $amountColumn = $this->pickFirstExisting($columns, ['total_with_vat', 'total_no_vat', 'amount']);

        if ($amountColumn === null) {
            return $this->emptySummary('В таблице начислений нет суммы');
        }

        $latestPeriod = DB::table('tenant_accruals')
            ->where('market_id', $marketId)
            ->when(in_array('source', $columns, true), fn ($query) => $query->where('source', '1c'))
            ->max('period');

        if (! filled($latestPeriod)) {
            return $this->emptySummary('Нет загруженных начислений 1С');
        }

        $query = DB::table('tenant_accruals')
            ->where('market_id', $marketId)
            ->where('period', $latestPeriod)
            ->when(in_array('source', $columns, true), fn ($query) => $query->where('source', '1c'));

        $rows = (clone $query)->count();
        $linked = in_array('tenant_contract_id', $columns, true)
            ? (clone $query)->whereNotNull('tenant_contract_id')->count()
            : 0;
        $unlinked = in_array('tenant_contract_id', $columns, true)
            ? (clone $query)->whereNull('tenant_contract_id')->count()
            : 0;
        $spaces = in_array('market_space_id', $columns, true)
            ? (clone $query)->whereNotNull('market_space_id')->distinct('market_space_id')->count('market_space_id')
            : null;
        $importedAt = in_array('imported_at', $columns, true)
            ? (clone $query)->max('imported_at')
            : (in_array('created_at', $columns, true) ? (clone $query)->max('created_at') : null);

        return [
            'emptyReason' => null,
            'period' => $this->formatMonth((string) $latestPeriod),
            'total' => (float) (clone $query)->sum($amountColumn),
            'rows' => (int) $rows,
            'linked' => (int) $linked,
            'unlinked' => (int) $unlinked,
            'spaces' => $spaces === null ? null : (int) $spaces,
            'importedAt' => $this->formatDateTime($importedAt),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getAccrualsPreviewRows(): array
    {
        $marketId = $this->marketId();

        if ($marketId === null || ! Schema::hasTable('tenant_accruals') || ! Schema::hasColumn('tenant_accruals', 'period')) {
            return [];
        }

        $columns = Schema::getColumnListing('tenant_accruals');
        $amountColumn = $this->pickFirstExisting($columns, ['total_with_vat', 'total_no_vat', 'amount']);

        if ($amountColumn === null) {
            return [];
        }

        $latestPeriod = DB::table('tenant_accruals')
            ->where('market_id', $marketId)
            ->when(in_array('source', $columns, true), fn ($query) => $query->where('source', '1c'))
            ->max('period');

        if (! filled($latestPeriod)) {
            return [];
        }

        $query = DB::table('tenant_accruals as ta')
            ->where('ta.market_id', $marketId)
            ->where('ta.period', $latestPeriod)
            ->when(in_array('source', $columns, true), fn ($query) => $query->where('ta.source', '1c'));

        if (Schema::hasTable('tenants') && in_array('tenant_id', $columns, true)) {
            $query->leftJoin('tenants as t', 't.id', '=', 'ta.tenant_id');
        }

        if (Schema::hasTable('tenant_contracts') && in_array('tenant_contract_id', $columns, true)) {
            $query->leftJoin('tenant_contracts as tc', 'tc.id', '=', 'ta.tenant_contract_id');
        }

        $rows = $query
            ->selectRaw('ta.id')
            ->selectRaw('ta.period')
            ->selectRaw('coalesce(ta.' . $amountColumn . ', 0) as amount')
            ->selectRaw(in_array('tenant_id', $columns, true) ? 'ta.tenant_id' : 'null as tenant_id')
            ->selectRaw(in_array('tenant_contract_id', $columns, true) ? 'ta.tenant_contract_id' : 'null as tenant_contract_id')
            ->selectRaw(
                Schema::hasTable('tenants') && in_array('tenant_id', $columns, true)
                    ? "coalesce(nullif(t.short_name, ''), nullif(t.name, ''), '—') as tenant_name"
                    : "'—' as tenant_name"
            )
            ->selectRaw(
                Schema::hasTable('tenant_contracts') && in_array('tenant_contract_id', $columns, true)
                    ? "coalesce(nullif(tc.number, ''), nullif(tc.external_id, ''), '—') as contract_name"
                    : "'—' as contract_name"
            )
            ->selectRaw(
                in_array('imported_at', $columns, true)
                    ? 'ta.imported_at as imported_at'
                    : (in_array('created_at', $columns, true) ? 'ta.created_at as imported_at' : 'null as imported_at')
            )
            ->orderByDesc('amount')
            ->orderBy('tenant_name')
            ->limit(8)
            ->get();

        return $rows->map(function (object $row): array {
            $contractLabel = trim((string) ($row->contract_name ?? ''));

            if ($contractLabel !== '' && $contractLabel !== '—' && ! str_starts_with($contractLabel, '№') && ! str_starts_with($contractLabel, '1С:')) {
                $contractLabel = '№ ' . $contractLabel;
            }

            return [
                'period' => $this->formatMonth((string) $row->period),
                'tenant_name' => (string) ($row->tenant_name ?? '—'),
                'tenant_url' => filled($row->tenant_id) ? TenantResource::getUrl('edit', ['record' => (int) $row->tenant_id]) : null,
                'contract_name' => $contractLabel !== '' ? $contractLabel : '—',
                'contract_url' => filled($row->tenant_contract_id) ? TenantContractResource::getUrl('edit', ['record' => (int) $row->tenant_contract_id]) : null,
                'amount' => (float) ($row->amount ?? 0),
                'imported_at' => $this->formatDateTime($row->imported_at ?? null),
            ];
        })->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function getDocumentsSummary(): array
    {
        $marketId = $this->marketId();

        if ($marketId === null) {
            return $this->emptySummary('Выберите рынок');
        }

        $service = app(AccrualPaymentReconciliationReport::class);
        [$fromDate, $toDate] = $service->defaultDateRange($marketId);
        $report = $service->build($marketId, $fromDate, $toDate);
        $summary = $report['summary'];

        return [
            'emptyReason' => $report['emptyReason'],
            'period' => $report['periodLabel'],
            'documents' => (int) $summary['rows_count'],
            'accruals' => (int) $summary['accrual_count'],
            'payments' => (int) $summary['payment_count'],
            'accrued' => (float) $summary['accrued'],
            'paid' => (float) $summary['paid'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getDocumentsPreviewRows(): array
    {
        $marketId = $this->marketId();

        if ($marketId === null) {
            return [];
        }

        $service = app(AccrualPaymentReconciliationReport::class);
        [$fromDate, $toDate] = $service->defaultDateRange($marketId);
        $report = $service->build($marketId, $fromDate, $toDate);

        return collect($report['rows'] ?? [])
            ->take(8)
            ->map(function (array $row): array {
                return [
                    'document_date' => $this->formatShortDate((string) ($row['document_date'] ?? '')),
                    'type' => (string) ($row['type'] ?? ''),
                    'type_label' => (string) ($row['type_label'] ?? '—'),
                    'document_number' => (string) ($row['document_number'] ?? '—'),
                    'tenant_name' => (string) ($row['tenant_name'] ?? '—'),
                    'tenant_url' => $row['tenant_url'] ?? null,
                    'contract_label' => (string) ($row['contract_label'] ?? '—'),
                    'contract_url' => $row['contract_url'] ?? null,
                    'amount' => (float) ($row['amount'] ?? 0),
                    'basis' => (string) ($row['basis'] ?? '—'),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function getSettlementsSummary(): array
    {
        $marketId = $this->marketId();

        if ($marketId === null) {
            return $this->emptySummary('Выберите рынок');
        }

        if (! Schema::hasTable('tenant_settlement_balances')) {
            return $this->emptySummary('Таблица расчётов 1С ещё не создана');
        }

        $latest = DB::table('tenant_settlement_balances')
            ->where('market_id', $marketId)
            ->orderByDesc('period_to')
            ->orderByDesc('imported_at')
            ->first(['period_from', 'period_to', 'account']);

        if (! $latest) {
            return $this->emptySummary('Нет загруженной ОСВ 1С');
        }

        $summary = DB::table('tenant_settlement_balances')
            ->where('market_id', $marketId)
            ->where('period_from', (string) $latest->period_from)
            ->where('period_to', (string) $latest->period_to)
            ->where('account', (string) $latest->account)
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
            ->selectRaw('max(imported_at) as imported_at')
            ->first();

        $closingDebit = (float) ($summary->closing_debit ?? 0);
        $closingCredit = (float) ($summary->closing_credit ?? 0);

        return [
            'emptyReason' => null,
            'period' => $this->formatDateRange((string) $latest->period_from, (string) $latest->period_to),
            'account' => (string) ($latest->account ?: '62'),
            'rows' => (int) ($summary->rows ?? 0),
            'tenants' => (int) ($summary->tenants ?? 0),
            'contracts' => (int) ($summary->contracts ?? 0),
            'organizations' => (int) ($summary->organizations ?? 0),
            'openingDebit' => (float) ($summary->opening_debit ?? 0),
            'openingCredit' => (float) ($summary->opening_credit ?? 0),
            'turnoverDebit' => (float) ($summary->turnover_debit ?? 0),
            'turnoverCredit' => (float) ($summary->turnover_credit ?? 0),
            'closingDebit' => $closingDebit,
            'closingCredit' => $closingCredit,
            'closingNet' => $closingDebit - $closingCredit,
            'importedAt' => $this->formatDateTime($summary->imported_at ?? null),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getSettlementsPreviewRows(): array
    {
        $marketId = $this->marketId();

        if ($marketId === null || ! Schema::hasTable('tenant_settlement_balances')) {
            return [];
        }

        $latest = DB::table('tenant_settlement_balances')
            ->where('market_id', $marketId)
            ->orderByDesc('period_to')
            ->orderByDesc('imported_at')
            ->first(['period_from', 'period_to', 'account']);

        if (! $latest) {
            return [];
        }

        $rows = DB::table('tenant_settlement_balances')
            ->where('market_id', $marketId)
            ->where('period_from', (string) $latest->period_from)
            ->where('period_to', (string) $latest->period_to)
            ->where('account', (string) $latest->account)
            ->select([
                'tenant_id',
                'tenant_contract_id',
                'tenant_name',
                'contract_name',
                'organization_name',
                'account',
            ])
            ->selectRaw('count(*) as rows_count')
            ->selectRaw('coalesce(sum(turnover_debit),0) as turnover_debit')
            ->selectRaw('coalesce(sum(turnover_credit),0) as turnover_credit')
            ->selectRaw('coalesce(sum(closing_debit),0) as closing_debit')
            ->selectRaw('coalesce(sum(closing_credit),0) as closing_credit')
            ->groupBy([
                'tenant_id',
                'tenant_contract_id',
                'tenant_name',
                'contract_name',
                'organization_name',
                'account',
            ])
            ->orderByRaw('(coalesce(sum(closing_debit),0) - coalesce(sum(closing_credit),0)) desc')
            ->orderBy('tenant_name')
            ->orderBy('contract_name')
            ->limit(8)
            ->get();

        return $rows->map(function (object $row): array {
            $net = (float) $row->closing_debit - (float) $row->closing_credit;

            return [
                'tenant_name' => (string) ($row->tenant_name ?? '—'),
                'tenant_url' => filled($row->tenant_id) ? TenantResource::getUrl('edit', ['record' => (int) $row->tenant_id]) : null,
                'contract_name' => (string) ($row->contract_name ?? '—'),
                'contract_url' => filled($row->tenant_contract_id) ? TenantContractResource::getUrl('edit', ['record' => (int) $row->tenant_contract_id]) : null,
                'organization_name' => (string) ($row->organization_name ?? '—'),
                'account' => (string) ($row->account ?? ''),
                'rows_count' => (int) ($row->rows_count ?? 0),
                'turnover_debit' => (float) ($row->turnover_debit ?? 0),
                'turnover_credit' => (float) ($row->turnover_credit ?? 0),
                'closing_debit' => (float) ($row->closing_debit ?? 0),
                'closing_credit' => (float) ($row->closing_credit ?? 0),
                'net' => $net,
                'status' => $net > 0.009 ? 'debt' : ($net < -0.009 ? 'credit' : 'zero'),
            ];
        })->all();
    }

    public function formatRub(mixed $value): string
    {
        return number_format((float) $value, 2, ',', ' ') . ' ₽';
    }

    protected function marketId(): ?int
    {
        $user = filament()->auth()->user();

        if (! $user) {
            return null;
        }

        return app(AccrualPaymentReconciliationReport::class)->resolveMarketIdForUser($user);
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

    /**
     * @return array<string, mixed>
     */
    private function emptySummary(string $reason): array
    {
        return ['emptyReason' => $reason];
    }

    private function formatMonth(string $value): string
    {
        try {
            return CarbonImmutable::parse($value)->format('m.Y');
        } catch (\Throwable) {
            return $value;
        }
    }

    private function formatDateRange(string $fromDate, string $toDate): string
    {
        try {
            return CarbonImmutable::parse($fromDate)->format('d.m.Y') . ' - ' . CarbonImmutable::parse($toDate)->format('d.m.Y');
        } catch (\Throwable) {
            return "{$fromDate} - {$toDate}";
        }
    }

    private function formatDateTime(mixed $value): ?string
    {
        if (! filled($value)) {
            return null;
        }

        try {
            return CarbonImmutable::parse((string) $value)
                ->timezone(config('app.timezone'))
                ->format('d.m.Y H:i');
        } catch (\Throwable) {
            return (string) $value;
        }
    }

    private function formatShortDate(?string $value): string
    {
        if (! filled($value)) {
            return '—';
        }

        try {
            return CarbonImmutable::parse((string) $value)->format('d.m.Y');
        } catch (\Throwable) {
            return (string) $value;
        }
    }

    private function normalizeSection(?string $section): string
    {
        return in_array($section, self::SECTIONS, true)
            ? $section
            : 'templates';
    }

    private function redirectUrlForSection(string $section): ?string
    {
        return match ($section) {
            'templates' => ReportResource::getUrl('index'),
            'runs' => ReportRunResource::getUrl('index'),
            'accruals' => TenantAccrualResource::getUrl('index'),
            'documents' => OneCReconciliation::getUrl(),
            'settlements' => OneCSettlements::getUrl(),
            default => null,
        };
    }
}
