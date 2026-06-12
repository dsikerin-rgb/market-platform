{{-- resources/views/filament/market-spaces/space-settlement-balances.blade.php --}}

@props([
    'state' => 'empty',
    'emptyReason' => '',
    'periodLabel' => null,
    'account' => null,
    'importedAt' => null,
    'scope' => null,
    'scopeLabel' => null,
    'scopeTone' => 'neutral',
    'summary' => [],
    'rows' => [],
    'currentTenantName' => '',
    'contractExternalIds' => [],
    'settlementsUrl' => null,
    'periodOptions' => [],
    'selectedPeriodKey' => null,
    'firstPeriodLabel' => null,
])

@php
    $money = static function (mixed $value): string {
        if (! is_numeric($value)) {
            return '—';
        }

        return number_format((float) $value, 2, ',', ' ') . ' ₽';
    };

    $rows = is_array($rows) ? $rows : [];
    $summary = is_array($summary) ? $summary : [];
    $periodOptions = is_array($periodOptions) ? $periodOptions : [];
    $currentTenantName = trim((string) $currentTenantName);
    $monthNames = [
        '01' => 'Январь',
        '02' => 'Февраль',
        '03' => 'Март',
        '04' => 'Апрель',
        '05' => 'Май',
        '06' => 'Июнь',
        '07' => 'Июль',
        '08' => 'Август',
        '09' => 'Сентябрь',
        '10' => 'Октябрь',
        '11' => 'Ноябрь',
        '12' => 'Декабрь',
    ];
    $closingNet = (float) ($summary['closing_net'] ?? 0);
    $statusTone = (string) ($summary['closing_tone'] ?? 'neutral');
    $statusAmount = $statusTone === 'success' ? abs($closingNet) : $closingNet;
    $statusLabel = match ($statusTone) {
        'danger' => 'Есть задолженность',
        'success' => 'Переплата',
        default => 'Нет задолженности',
    };
@endphp

@once
    <style>
        .space-finance {
            display: grid;
            gap: 16px;
            font-size: 14px;
        }

        .space-finance__hero {
            display: grid;
            grid-template-columns: minmax(220px, 1.1fr) repeat(2, minmax(160px, .7fr));
            gap: 12px;
            align-items: stretch;
        }

        .space-finance__card {
            border: 1px solid #dbe4f0;
            border-radius: 10px;
            background: #fff;
            padding: 12px 14px;
            min-width: 0;
        }

        .dark .space-finance__card {
            border-color: rgba(148, 163, 184, 0.3);
            background: rgba(15, 23, 42, 0.35);
        }

        .space-finance__label {
            color: #64748b;
            font-size: 12px;
            line-height: 1.3;
        }

        .dark .space-finance__label {
            color: #94a3b8;
        }

        .space-finance__value {
            color: #0f172a;
            font-size: 15px;
            font-weight: 800;
            line-height: 1.25;
            margin-top: 5px;
            overflow-wrap: anywhere;
        }

        .space-finance__value--large {
            font-size: 22px;
        }

        .space-finance__value--danger {
            color: #b91c1c;
        }

        .space-finance__value--success {
            color: #15803d;
        }

        .dark .space-finance__value {
            color: #f8fafc;
        }

        .dark .space-finance__value--danger {
            color: #fca5a5;
        }

        .dark .space-finance__value--success {
            color: #86efac;
        }

        .space-finance__badge {
            display: inline-flex;
            align-items: center;
            width: fit-content;
            border: 1px solid #cbd5e1;
            border-radius: 999px;
            background: #f8fafc;
            color: #334155;
            font-size: 12px;
            font-weight: 700;
            line-height: 1;
            padding: 6px 9px;
            white-space: nowrap;
        }

        .space-finance__badge--success {
            border-color: #86efac;
            background: #f0fdf4;
            color: #166534;
        }

        .space-finance__badge--warning {
            border-color: #fde68a;
            background: #fffbeb;
            color: #92400e;
        }

        .space-finance__badge--danger {
            border-color: #fca5a5;
            background: #fef2f2;
            color: #991b1b;
        }

        .dark .space-finance__badge {
            border-color: rgba(148, 163, 184, 0.35);
            background: rgba(15, 23, 42, 0.35);
            color: #e2e8f0;
        }

        .space-finance__summary {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 10px;
        }

        .space-finance__toolbar {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: flex-start;
            justify-content: flex-end;
        }

        .space-finance__period-form {
            display: grid;
            gap: 6px;
            flex: 0 1 auto;
            width: fit-content;
            max-width: 100%;
            min-width: 220px;
        }

        .space-finance__period-form > .space-finance__label {
            display: block;
            text-align: left;
            font-weight: 600;
        }

        .space-finance__month-select {
            width: 100%;
            min-height: 40px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            background: #fff;
            color: #0f172a;
            font-size: 14px;
            line-height: 20px;
            padding: 8px 10px;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.06);
        }

        .space-finance__month-select:focus {
            border-color: #0ea5e9;
            box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.16);
            outline: none;
        }

        .dark .space-finance__month-select {
            border-color: rgba(148, 163, 184, 0.35);
            background: rgba(15, 23, 42, 0.45);
            color: #f8fafc;
        }

        .dark .space-finance__month-select:focus {
            border-color: #38bdf8;
            box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.18);
        }

        .space-finance__note {
            color: #475569;
            font-size: 13px;
            line-height: 1.45;
        }

        .space-finance__period-form .space-finance__note {
            max-width: 360px;
            font-size: 12px;
        }

        .dark .space-finance__note {
            color: #cbd5e1;
        }

        .space-finance__table-wrap {
            overflow-x: auto;
            border: 1px solid #dbe4f0;
            border-radius: 10px;
        }

        .dark .space-finance__table-wrap {
            border-color: rgba(148, 163, 184, 0.3);
        }

        .space-finance__table {
            width: 100%;
            min-width: 680px;
            border-collapse: collapse;
        }

        .space-finance__table th,
        .space-finance__table td {
            border-bottom: 1px solid #e2e8f0;
            padding: 9px 10px;
            text-align: left;
            vertical-align: top;
        }

        .dark .space-finance__table th,
        .dark .space-finance__table td {
            border-bottom-color: rgba(148, 163, 184, 0.24);
        }

        .space-finance__table th {
            color: #64748b;
            font-size: 12px;
            font-weight: 800;
            background: #f8fafc;
            white-space: nowrap;
        }

        .dark .space-finance__table th {
            color: #cbd5e1;
            background: rgba(15, 23, 42, 0.45);
        }

        .space-finance__table td {
            color: #0f172a;
            font-size: 13px;
            line-height: 1.35;
        }

        .dark .space-finance__table td {
            color: #f8fafc;
        }

        .space-finance__muted {
            color: #64748b;
            font-size: 12px;
            line-height: 1.35;
            margin-top: 2px;
        }

        .dark .space-finance__muted {
            color: #94a3b8;
        }

        .space-finance__footer {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
            justify-content: space-between;
        }

        .space-finance__link {
            color: #2563eb;
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
            white-space: nowrap;
        }

        .space-finance__link:hover {
            text-decoration: underline;
        }

        @media (max-width: 960px) {
            .space-finance__hero,
            .space-finance__summary {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 620px) {
            .space-finance__hero,
            .space-finance__summary {
                grid-template-columns: minmax(0, 1fr);
            }

            .space-finance__toolbar {
                justify-content: stretch;
            }

            .space-finance__period-form {
                flex-basis: 100%;
                width: 100%;
                max-width: none;
            }
        }
    </style>
@endonce

<div class="space-finance">
    @if ($periodOptions !== [])
        <div class="space-finance__toolbar">
            <form class="space-finance__period-form" method="GET" aria-label="Период ОСВ">
                @if (request()->query('tab'))
                    <input type="hidden" name="tab" value="{{ request()->query('tab') }}">
                @endif

                <label class="space-finance__label" for="space-finance-period">Период ОСВ</label>
                <select
                    id="space-finance-period"
                    class="space-finance__month-select"
                    name="settlement_period"
                    onchange="this.form.submit()"
                >
                    @foreach ($periodOptions as $periodKey => $periodOptionLabel)
                        @php
                            $periodParts = explode('|', (string) $periodKey);
                            $periodFrom = (string) ($periodParts[0] ?? '');
                            $periodAccount = (string) ($periodParts[2] ?? '');
                            $periodDate = $periodFrom !== '' ? \Carbon\CarbonImmutable::parse($periodFrom) : null;
                            $periodMonth = $periodDate ? ($monthNames[$periodDate->format('m')] ?? $periodDate->format('m')) : $periodOptionLabel;
                            $periodYear = $periodDate ? $periodDate->format('Y') : '';
                            $isSelectedPeriod = $periodKey === $selectedPeriodKey;
                            $periodSelectLabel = trim($periodMonth . ' ' . $periodYear);

                            if ($periodAccount !== '' && $periodAccount !== '62') {
                                $periodSelectLabel .= ' · сч. ' . $periodAccount;
                            }
                        @endphp
                        <option value="{{ $periodKey }}" title="{{ $periodOptionLabel }}" @selected($isSelectedPeriod)>
                            {{ $periodSelectLabel }}
                        </option>
                    @endforeach
                </select>

                @if ($firstPeriodLabel)
                    <div class="space-finance__note">
                        Минимум: {{ $firstPeriodLabel }}. Это первый загруженный период ОСВ.
                    </div>
                @endif
            </form>
        </div>
    @endif

    @if ($state === 'ready')
        <div class="space-finance__hero">
            <div class="space-finance__card">
                <div class="space-finance__label">Статус</div>
                <div class="space-finance__value space-finance__value--large space-finance__value--{{ $statusTone }}">
                    {{ $statusLabel }}
                </div>
                <div class="space-finance__muted">Сумма: {{ $money($statusAmount) }}</div>
            </div>
            <div class="space-finance__card">
                <div class="space-finance__label">Период</div>
                <div class="space-finance__value">{{ $periodLabel ?: '—' }}</div>
                @if ($account)
                    <div class="space-finance__muted">Счет {{ $account }}</div>
                @endif
            </div>
            <div class="space-finance__card">
                <div class="space-finance__label">Обновлено</div>
                <div class="space-finance__value">{{ $importedAt ?: '—' }}</div>
            </div>
        </div>

        @if ($scopeLabel)
            <span class="space-finance__badge space-finance__badge--{{ $scopeTone }}">{{ $scopeLabel }}</span>
        @endif

        <div class="space-finance__summary">
            <div class="space-finance__card">
                <div class="space-finance__label">Начислено за период</div>
                <div class="space-finance__value">{{ $money($summary['turnover_debit'] ?? null) }}</div>
            </div>
            <div class="space-finance__card">
                <div class="space-finance__label">Оплачено за период</div>
                <div class="space-finance__value">{{ $money($summary['turnover_credit'] ?? null) }}</div>
            </div>
            <div class="space-finance__card">
                <div class="space-finance__label">Итог</div>
                <div class="space-finance__value space-finance__value--{{ $statusTone }}">{{ $money($closingNet) }}</div>
            </div>
        </div>

        <div class="space-finance__table-wrap">
            <table class="space-finance__table">
                <thead>
                    <tr>
                        <th>Договор</th>
                        <th>Организация</th>
                        <th>Начислено</th>
                        <th>Оплачено</th>
                        <th>Итог</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        @php
                            $tenantName = trim((string) ($row['tenant_name'] ?? ''));
                            $showTenant = $tenantName !== '' && ($scope === 'tenant_fallback' || $tenantName !== $currentTenantName);
                        @endphp
                        <tr>
                            <td title="{{ $row['contract_external_id'] ?? '' }}">
                                {{ $row['contract_name'] ?: 'Без названия договора' }}
                                @if ($showTenant)
                                    <div class="space-finance__muted">{{ $tenantName }}</div>
                                @endif
                            </td>
                            <td>{{ $row['organization_name'] ?: '—' }}</td>
                            <td>{{ $money($row['turnover_debit'] ?? null) }}</td>
                            <td>{{ $money($row['turnover_credit'] ?? null) }}</td>
                            <td><strong>{{ $money($row['closing_net'] ?? null) }}</strong></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @else
        @if ($periodLabel || $account)
            <div class="space-finance__hero">
                <div class="space-finance__card">
                    <div class="space-finance__label">Период</div>
                    <div class="space-finance__value">{{ $periodLabel ?: '—' }}</div>
                </div>
                <div class="space-finance__card">
                    <div class="space-finance__label">Счет</div>
                    <div class="space-finance__value">{{ $account ?: '—' }}</div>
                </div>
            </div>
        @endif
        <div class="space-finance__note">{{ $emptyReason ?: 'Нет данных 1С для отображения.' }}</div>
    @endif

    <div class="space-finance__footer">
        <div class="space-finance__muted">
            Это сводка из 1С за выбранный период. Подробные начисления и оплаты доступны в расчетах 1С.
        </div>
        @if ($settlementsUrl)
            <a class="space-finance__link" href="{{ $settlementsUrl }}">Подробности в 1С</a>
        @endif
    </div>
</div>
