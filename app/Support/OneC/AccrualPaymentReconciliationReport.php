<?php

declare(strict_types=1);

namespace App\Support\OneC;

use App\Filament\Resources\TenantContractResource;
use App\Filament\Resources\TenantResource;
use App\Models\Market;
use App\Support\MarketContext;
use App\Support\Search\LooseSearch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AccrualPaymentReconciliationReport
{
    /**
     * @return array{
     *     periodLabel:string,
     *     rows:list<array<string, mixed>>,
     *     summary:array<string, float|int>,
     *     emptyReason:string|null
     * }
     */
    public function build(int $marketId, string $fromDate, string $toDate): array
    {
        if (! Schema::hasTable('tenant_accruals') && ! Schema::hasTable('tenant_payments')) {
            return $this->emptyData('Нет данных 1С', $fromDate, $toDate);
        }

        [$from, $to] = $this->normalizeDateRange($fromDate, $toDate);
        $rows = [
            ...$this->loadAccrualDocuments($marketId, $from, $to),
            ...$this->loadPaymentDocuments($marketId, $from, $to),
        ];

        $this->hydrateLabels($rows);

        usort($rows, static function (array $left, array $right): int {
            $date = strcmp((string) $right['document_date'], (string) $left['document_date']);

            if ($date !== 0) {
                return $date;
            }

            $type = strcmp((string) $left['type_label'], (string) $right['type_label']);

            if ($type !== 0) {
                return $type;
            }

            return strcmp((string) $left['tenant_name'], (string) $right['tenant_name']);
        });

        return [
            'periodLabel' => $this->formatPeriodLabel($from, $to),
            'rows' => $rows,
            'summary' => $this->summarize($rows),
            'emptyReason' => null,
        ];
    }

    /**
     * @return array{0:string,1:string}
     */
    public function defaultDateRange(int $marketId): array
    {
        $latest = $this->latestDataDate($marketId);

        if ($latest === null) {
            $now = CarbonImmutable::now();

            return [$now->startOfMonth()->toDateString(), $now->endOfMonth()->toDateString()];
        }

        $month = CarbonImmutable::parse($latest)->startOfMonth();

        return [$month->toDateString(), $month->endOfMonth()->toDateString()];
    }

    public function resolveMarketIdForUser(mixed $user): ?int
    {
        $isSuperAdmin = method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin();

        if (! $isSuperAdmin) {
            return $user->market_id ? (int) $user->market_id : null;
        }

        $value = app(MarketContext::class)->selectedMarketIdFromSession();

        if (filled($value)) {
            return (int) $value;
        }

        $marketId = Market::query()
            ->orderBy('id')
            ->value('id');

        return $marketId ? (int) $marketId : null;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public function filterRows(array $rows, string $type = 'all', string $search = ''): array
    {
        return array_values(array_filter($rows, static function (array $row) use ($type, $search): bool {
            if (in_array($type, ['accrual', 'payment'], true) && $row['type'] !== $type) {
                return false;
            }

            if (trim($search) === '') {
                return true;
            }

            $haystack = implode(' ', [
                (string) $row['tenant_name'],
                (string) $row['contract_label'],
                (string) $row['document_number'],
                (string) $row['basis'],
                (string) $row['organization_name'],
                (string) $row['account'],
            ]);

            return LooseSearch::matchesText($haystack, $search);
        }));
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, float|int>
     */
    public function summarize(array $rows): array
    {
        $summary = [
            'accrued' => 0.0,
            'paid' => 0.0,
            'total' => 0.0,
            'accrual_count' => 0,
            'payment_count' => 0,
            'rows_count' => count($rows),
        ];

        foreach ($rows as $row) {
            $amount = (float) $row['amount'];
            $summary['total'] += $amount;

            if ($row['type'] === 'payment') {
                $summary['paid'] += $amount;
                $summary['payment_count']++;
            } else {
                $summary['accrued'] += $amount;
                $summary['accrual_count']++;
            }
        }

        return $summary;
    }

    private function latestDataDate(int $marketId): ?string
    {
        $latest = null;

        if (Schema::hasTable('tenant_accruals') && Schema::hasColumn('tenant_accruals', 'period')) {
            $value = DB::table('tenant_accruals')
                ->where('market_id', $marketId)
                ->orderByDesc('period')
                ->value('period');

            $latest = $this->normalizeDateString($value);
        }

        if (Schema::hasTable('tenant_payments') && Schema::hasColumn('tenant_payments', 'payment_date')) {
            $value = DB::table('tenant_payments')
                ->where('market_id', $marketId)
                ->orderByDesc('payment_date')
                ->value('payment_date');

            $paymentDate = $this->normalizeDateString($value);

            if ($paymentDate !== null && ($latest === null || $paymentDate > $latest)) {
                $latest = $paymentDate;
            }
        }

        return $latest;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadAccrualDocuments(int $marketId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if (! Schema::hasTable('tenant_accruals') || ! Schema::hasColumn('tenant_accruals', 'period')) {
            return [];
        }

        $columns = Schema::getColumnListing('tenant_accruals');
        $amountColumn = $this->pickFirstExisting($columns, ['total_with_vat', 'total_no_vat', 'amount']);

        if ($amountColumn === null) {
            return [];
        }

        $select = ['id', 'tenant_id', 'period', $amountColumn];

        foreach ([
            'tenant_contract_id',
            'contract_external_id',
            'organization_name',
            'account',
            'document_number',
            'document_date',
            'document_name',
            'service_name',
            'line_description',
            'purpose',
            'notes',
            'discount_note',
            'source_row_number',
            'source_file',
            'payload',
        ] as $column) {
            if (in_array($column, $columns, true)) {
                $select[] = $column;
            }
        }

        $query = DB::table('tenant_accruals')
            ->where('market_id', $marketId)
            ->where('period', '>=', $from->toDateString())
            ->where('period', '<=', $to->toDateString());

        if (in_array('source', $columns, true)) {
            $query->where('source', '1c');
        }

        try {
            $rows = $query->get($select);
        } catch (\Throwable) {
            return [];
        }

        return $rows->map(function (object $row) use ($amountColumn): array {
            $payload = $this->decodePayload($row->payload ?? null);
            $documentNumber = $this->firstNonEmpty([
                $row->document_number ?? null,
                $this->firstPayloadValue($payload, [
                    'document_number',
                    'documentNumber',
                    'doc_number',
                    'number',
                    'document',
                ]),
                $row->document_name ?? null,
            ]);
            $basis = $this->firstNonEmpty([
                $row->line_description ?? null,
                $row->service_name ?? null,
                $row->purpose ?? null,
                $this->firstPayloadValue($payload, ['basis', 'purpose', 'description', 'comment']),
                $row->notes ?? null,
                $row->discount_note ?? null,
            ]);
            $documentDate = $this->firstNonEmpty([
                $row->document_date ?? null,
                $row->period ?? null,
            ]);

            return [
                'key' => 'accrual:' . (int) $row->id,
                'type' => 'accrual',
                'type_label' => 'Начисление',
                'document_date' => $this->normalizeDateString($documentDate) ?? '',
                'document_number' => $documentNumber ?: ('строка ' . (int) $row->id),
                'tenant_id' => is_numeric($row->tenant_id ?? null) ? (int) $row->tenant_id : null,
                'tenant_name' => '',
                'tenant_url' => null,
                'contract_id' => is_numeric($row->tenant_contract_id ?? null) ? (int) $row->tenant_contract_id : null,
                'contract_label' => '',
                'contract_url' => null,
                'contract_external_id' => $this->stringOrNull($row->contract_external_id ?? null),
                'amount' => round((float) ($row->{$amountColumn} ?? 0.0), 2),
                'organization_name' => $this->stringOrNull($row->organization_name ?? null),
                'account' => $this->stringOrNull($row->account ?? null),
                'basis' => $basis ?: '—',
                'source_file' => $this->stringOrNull($row->source_file ?? null),
            ];
        })->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadPaymentDocuments(int $marketId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if (! Schema::hasTable('tenant_payments') || ! Schema::hasColumn('tenant_payments', 'payment_date')) {
            return [];
        }

        $columns = Schema::getColumnListing('tenant_payments');
        $select = ['id', 'tenant_id', 'payment_date', 'amount'];

        foreach ([
            'tenant_contract_id',
            'contract_external_id',
            'payment_external_id',
            'document_number',
            'organization_name',
            'account',
            'purpose',
            'source_file',
        ] as $column) {
            if (in_array($column, $columns, true)) {
                $select[] = $column;
            }
        }

        $query = DB::table('tenant_payments')
            ->where('market_id', $marketId)
            ->where('payment_date', '>=', $from->toDateString())
            ->where('payment_date', '<=', $to->toDateString());

        try {
            $rows = $query->get($select);
        } catch (\Throwable) {
            return [];
        }

        return $rows->map(function (object $row): array {
            $documentNumber = $this->firstNonEmpty([
                $row->document_number ?? null,
                $row->payment_external_id ?? null,
            ]);

            return [
                'key' => 'payment:' . (int) $row->id,
                'type' => 'payment',
                'type_label' => 'Оплата',
                'document_date' => $this->normalizeDateString($row->payment_date ?? null) ?? '',
                'document_number' => $documentNumber ?: ('строка ' . (int) $row->id),
                'tenant_id' => is_numeric($row->tenant_id ?? null) ? (int) $row->tenant_id : null,
                'tenant_name' => '',
                'tenant_url' => null,
                'contract_id' => is_numeric($row->tenant_contract_id ?? null) ? (int) $row->tenant_contract_id : null,
                'contract_label' => '',
                'contract_url' => null,
                'contract_external_id' => $this->stringOrNull($row->contract_external_id ?? null),
                'amount' => round((float) ($row->amount ?? 0.0), 2),
                'organization_name' => $this->stringOrNull($row->organization_name ?? null),
                'account' => $this->stringOrNull($row->account ?? null),
                'basis' => $this->stringOrNull($row->purpose ?? null) ?? '—',
                'source_file' => $this->stringOrNull($row->source_file ?? null),
            ];
        })->all();
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function hydrateLabels(array &$rows): void
    {
        $tenantNames = $this->loadTenantNames($rows);
        $contractLabels = $this->loadContractLabels($rows);

        foreach ($rows as &$row) {
            $tenantId = $row['tenant_id'];
            $contractId = $row['contract_id'];

            $row['tenant_name'] = $tenantId !== null
                ? ($tenantNames[$tenantId] ?? ('Арендатор #' . $tenantId))
                : 'Без арендатора';
            $row['tenant_url'] = $tenantId !== null
                ? TenantResource::getUrl('edit', ['record' => $tenantId])
                : null;

            $fallbackContract = $row['contract_external_id'] !== null
                ? ('1С: ' . $row['contract_external_id'])
                : 'Без договора';
            $row['contract_label'] = $contractId !== null
                ? ($contractLabels[$contractId] ?? ('Договор #' . $contractId))
                : $fallbackContract;
            $row['contract_url'] = $contractId !== null
                ? TenantContractResource::getUrl('edit', ['record' => $contractId])
                : null;
        }
        unset($row);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<int, string>
     */
    private function loadTenantNames(array $rows): array
    {
        if (! Schema::hasTable('tenants')) {
            return [];
        }

        $ids = array_values(array_unique(array_filter(array_map(
            static fn (array $row): ?int => $row['tenant_id'],
            $rows,
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
     * @param list<array<string, mixed>> $rows
     * @return array<int, string>
     */
    private function loadContractLabels(array $rows): array
    {
        if (! Schema::hasTable('tenant_contracts')) {
            return [];
        }

        $ids = array_values(array_unique(array_filter(array_map(
            static fn (array $row): ?int => $row['contract_id'],
            $rows,
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

    /**
     * @return array{0:CarbonImmutable,1:CarbonImmutable}
     */
    private function normalizeDateRange(string $fromDate, string $toDate): array
    {
        $from = CarbonImmutable::parse($fromDate)->startOfDay();
        $to = CarbonImmutable::parse($toDate)->startOfDay();

        if ($to->lt($from)) {
            return [$to, $from];
        }

        return [$from, $to];
    }

    private function normalizeDateString(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse((string) $value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
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
    private function decodePayload(mixed $payload): array
    {
        if (is_array($payload)) {
            return $payload;
        }

        if (! is_string($payload) || trim($payload) === '') {
            return [];
        }

        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string> $keys
     */
    private function firstPayloadValue(array $payload, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $this->stringOrNull($payload[$key] ?? null);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param list<mixed> $values
     */
    private function firstNonEmpty(array $values): ?string
    {
        foreach ($values as $value) {
            $string = $this->stringOrNull($value);

            if ($string !== null) {
                return $string;
            }
        }

        return null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function formatPeriodLabel(CarbonImmutable $from, CarbonImmutable $to): string
    {
        return $from->format('d.m.Y') . ' - ' . $to->format('d.m.Y');
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyData(string $reason, string $fromDate, string $toDate): array
    {
        [$from, $to] = $this->normalizeDateRange($fromDate, $toDate);

        return [
            'periodLabel' => $this->formatPeriodLabel($from, $to),
            'rows' => [],
            'summary' => [
                'accrued' => 0.0,
                'paid' => 0.0,
                'total' => 0.0,
                'accrual_count' => 0,
                'payment_count' => 0,
                'rows_count' => 0,
            ],
            'emptyReason' => $reason,
        ];
    }
}
