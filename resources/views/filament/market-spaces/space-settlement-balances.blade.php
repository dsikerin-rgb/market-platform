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
        '01' => 'Янв',
        '02' => 'Фев',
        '03' => 'Мар',
        '04' => 'Апр',
        '05' => 'Май',
        '06' => 'Июн',
        '07' => 'Июл',
        '08' => 'Авг',
        '09' => 'Сен',
        '10' => 'Окт',
        '11' => 'Ноя',
        '12' => 'Дек',
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
            gap: 8px;
            flex: 0 1 auto;
            width: fit-content;
            max-width: 100%;
            padding: 10px 12px;
            border: 1px solid #dbe4f0;
            border-radius: 8px;
            background: #f8fafc;
        }

        .dark .space-finance__period-form {
            border-color: rgba(148, 163, 184, 0.3);
            background: rgba(15, 23, 42, 0.3);
        }

        .space-finance__period-form > .space-finance__label {
            display: block;
            text-align: left;
            font-weight: 700;
        }

        .space-finance__period-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            justify-content: flex-end;
            max-width: 452px;
        }

        .space-finance__period-tile {
            display: grid;
            grid-template-rows: 19px 1fr;
            width: 58px;
            min-height: 64px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            background: #fff;
            color: #0f172a;
            overflow: hidden;
            text-align: center;
            text-decoration: none;
            transition: border-color .15s ease, box-shadow .15s ease, transform .15s ease;
        }

        .space-finance__period-tile:hover {
            border-color: #60a5fa;
            box-shadow: 0 8px 18px rgba(15, 23, 42, 0.08);
            transform: translateY(-1px);
        }

        .space-finance__period-tile--active {
            border-color: #2563eb;
            box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.18);
        }

        .space-finance__period-tile-top {
            display: grid;
            place-items: center;
            background: #dbeafe;
            color: #1e40af;
            font-size: 10px;
            font-weight: 800;
            line-height: 1;
            text-transform: uppercase;
        }

        .space-finance__period-tile--active .space-finance__period-tile-top {
            background: #2563eb;
            color: #fff;
        }

        .space-finance__period-tile-body {
            display: grid;
            gap: 1px;
            place-content: center;
            padding: 6px 4px;
        }

        .space-finance__period-tile-month {
            font-size: 15px;
            font-weight: 800;
            line-height: 1;
        }

        .space-finance__period-tile-year,
        .space-finance__period-tile-account {
            color: #64748b;
            font-size: 10px;
            font-weight: 700;
            line-height: 1.15;
        }

        .dark .space-finance__period-tile {
            border-color: rgba(148, 163, 184, 0.35);
            background: rgba(15, 23, 42, 0.35);
            color: #f8fafc;
        }

        .dark .space-finance__period-tile-year,
        .dark .space-finance__period-tile-account {
            color: #94a3b8;
        }

        .space-finance__note {
            color: #475569;
            font-size: 13px;
            line-height: 1.45;
        }

        .space-finance__period-form .space-finance__note {
            max-width: none;
            margin-top: 8px;
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

            .space-finance__period-grid {
                justify-content: flex-start;
                max-width: none;
            }
        }
    </style>
@endonce

<div class="space-finance">
    @if ($periodOptions !== [])
        <div class="space-finance__toolbar">
            <div class="space-finance__period-form" aria-label="Период ОСВ">
                <div class="space-finance__label">Период ОСВ</div>
                <div class="space-finance__period-grid">
                    @foreach ($periodOptions as $periodKey => $periodOptionLabel)
                        @php
                            $periodParts = explode('|', (string) $periodKey);
                            $periodFrom = (string) ($periodParts[0] ?? '');
                            $periodAccount = (string) ($periodParts[2] ?? '');
                            $periodDate = $periodFrom !== '' ? \Carbon\CarbonImmutable::parse($periodFrom) : null;
                            $periodMonth = $periodDate ? ($monthNames[$periodDate->format('m')] ?? $periodDate->format('m')) : $periodOptionLabel;
                            $periodYear = $periodDate ? $periodDate->format('Y') : '';
                            $isSelectedPeriod = $periodKey === $selectedPeriodKey;
                        @endphp
                        <a
                            class="space-finance__period-tile @if ($isSelectedPeriod) space-finance__period-tile--active @endif"
                            href="{{ request()->fullUrlWithQuery(['settlement_period' => $periodKey]) }}"
                            title="{{ $periodOptionLabel }}"
                            aria-label="Показать ОСВ за {{ $periodOptionLabel }}"
                            @if ($isSelectedPeriod) aria-current="true" @endif
                        >
                            <span class="space-finance__period-tile-top"></span>
                            <span class="space-finance__period-tile-body">
                                <span class="space-finance__period-tile-month">{{ $periodMonth }}</span>
                                <span class="space-finance__period-tile-year">{{ $periodYear }}</span>
                                @if ($periodAccount !== '' && $periodAccount !== '62')
                                    <span class="space-finance__period-tile-account">сч. {{ $periodAccount }}</span>
                                @endif
                            </span>
                        </a>
                    @endforeach
                </div>

                @if ($firstPeriodLabel)
                    <div class="space-finance__note">
                        Минимум: {{ $firstPeriodLabel }}. Это первый загруженный период ОСВ.
                    </div>
                @endif
            </div>
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
