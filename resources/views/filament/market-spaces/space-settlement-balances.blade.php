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
    'contractExternalIds' => [],
    'settlementsUrl' => null,
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
    $contractExternalIds = is_array($contractExternalIds) ? $contractExternalIds : [];
@endphp

@once
    <style>
        .space-osv {
            display: grid;
            gap: 14px;
            font-size: 14px;
        }

        .space-osv__meta {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
            color: #475569;
            font-size: 13px;
            line-height: 1.4;
        }

        .dark .space-osv__meta {
            color: #cbd5e1;
        }

        .space-osv__badge {
            display: inline-flex;
            align-items: center;
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

        .space-osv__badge--success {
            border-color: #86efac;
            background: #f0fdf4;
            color: #166534;
        }

        .space-osv__badge--warning {
            border-color: #fde68a;
            background: #fffbeb;
            color: #92400e;
        }

        .space-osv__badge--danger {
            border-color: #fca5a5;
            background: #fef2f2;
            color: #991b1b;
        }

        .dark .space-osv__badge {
            border-color: rgba(148, 163, 184, 0.35);
            background: rgba(15, 23, 42, 0.35);
            color: #e2e8f0;
        }

        .space-osv__summary {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 10px;
        }

        .space-osv__metric {
            border: 1px solid #dbe4f0;
            border-radius: 10px;
            padding: 10px 12px;
            background: #fff;
            min-width: 0;
        }

        .dark .space-osv__metric {
            border-color: rgba(148, 163, 184, 0.3);
            background: rgba(15, 23, 42, 0.35);
        }

        .space-osv__metric-label {
            color: #64748b;
            font-size: 12px;
            line-height: 1.3;
        }

        .dark .space-osv__metric-label {
            color: #94a3b8;
        }

        .space-osv__metric-value {
            color: #0f172a;
            font-size: 16px;
            font-weight: 800;
            line-height: 1.25;
            margin-top: 4px;
            overflow-wrap: anywhere;
        }

        .dark .space-osv__metric-value {
            color: #f8fafc;
        }

        .space-osv__metric-value--danger {
            color: #b91c1c;
        }

        .space-osv__metric-value--success {
            color: #15803d;
        }

        .space-osv__note {
            color: #475569;
            font-size: 13px;
            line-height: 1.45;
        }

        .dark .space-osv__note {
            color: #cbd5e1;
        }

        .space-osv__table-wrap {
            overflow-x: auto;
            border: 1px solid #dbe4f0;
            border-radius: 10px;
        }

        .dark .space-osv__table-wrap {
            border-color: rgba(148, 163, 184, 0.3);
        }

        .space-osv__table {
            width: 100%;
            min-width: 760px;
            border-collapse: collapse;
        }

        .space-osv__table th,
        .space-osv__table td {
            border-bottom: 1px solid #e2e8f0;
            padding: 9px 10px;
            text-align: left;
            vertical-align: top;
        }

        .dark .space-osv__table th,
        .dark .space-osv__table td {
            border-bottom-color: rgba(148, 163, 184, 0.24);
        }

        .space-osv__table th {
            color: #64748b;
            font-size: 12px;
            font-weight: 800;
            background: #f8fafc;
            white-space: nowrap;
        }

        .dark .space-osv__table th {
            color: #cbd5e1;
            background: rgba(15, 23, 42, 0.45);
        }

        .space-osv__table td {
            color: #0f172a;
            font-size: 13px;
            line-height: 1.35;
        }

        .dark .space-osv__table td {
            color: #f8fafc;
        }

        .space-osv__muted {
            color: #64748b;
            font-size: 12px;
            line-height: 1.35;
            margin-top: 2px;
        }

        .dark .space-osv__muted {
            color: #94a3b8;
        }

        .space-osv__footer {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
            justify-content: space-between;
        }

        .space-osv__link {
            color: #2563eb;
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
        }

        .space-osv__link:hover {
            text-decoration: underline;
        }

        @media (max-width: 860px) {
            .space-osv__summary {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 560px) {
            .space-osv__summary {
                grid-template-columns: minmax(0, 1fr);
            }
        }
    </style>
@endonce

<div class="space-osv">
    @if ($periodLabel || $account)
        <div class="space-osv__meta">
            @if ($periodLabel)
                <span>Период: <strong>{{ $periodLabel }}</strong></span>
            @endif
            @if ($account)
                <span>Счет: <strong>{{ $account }}</strong></span>
            @endif
            @if ($importedAt)
                <span>Импорт: <strong>{{ $importedAt }}</strong></span>
            @endif
        </div>
    @endif

    @if ($state === 'ready')
        <div class="space-osv__meta">
            <span class="space-osv__badge space-osv__badge--{{ $scopeTone }}">{{ $scopeLabel }}</span>
            @if (! empty($contractExternalIds))
                <span>Договоры: {{ implode(', ', $contractExternalIds) }}</span>
            @endif
        </div>

        <div class="space-osv__summary">
            <div class="space-osv__metric">
                <div class="space-osv__metric-label">{{ $summary['closing_label'] ?? 'Сальдо' }}</div>
                <div class="space-osv__metric-value space-osv__metric-value--{{ $summary['closing_tone'] ?? 'neutral' }}">
                    {{ $money($summary['closing_net'] ?? null) }}
                </div>
            </div>
            <div class="space-osv__metric">
                <div class="space-osv__metric-label">Оборот Дт</div>
                <div class="space-osv__metric-value">{{ $money($summary['turnover_debit'] ?? null) }}</div>
            </div>
            <div class="space-osv__metric">
                <div class="space-osv__metric-label">Оборот Кт</div>
                <div class="space-osv__metric-value">{{ $money($summary['turnover_credit'] ?? null) }}</div>
            </div>
            <div class="space-osv__metric">
                <div class="space-osv__metric-label">Строки / договоры</div>
                <div class="space-osv__metric-value">{{ $summary['rows_count'] ?? 0 }} / {{ $summary['contracts_count'] ?? 0 }}</div>
            </div>
        </div>

        @if ($scope === 'tenant_fallback')
            <div class="space-osv__note">
                Нет точных строк ОСВ по договорам места. Показана ОСВ текущего арендатора; это помогает проверить арендатора, но не подтверждает долг именно по этому месту.
            </div>
        @else
            <div class="space-osv__note">
                Суммы подобраны по активным договорам, привязанным к этому месту.
            </div>
        @endif

        <div class="space-osv__table-wrap">
            <table class="space-osv__table">
                <thead>
                    <tr>
                        <th>Договор</th>
                        <th>Организация</th>
                        <th>Входящее</th>
                        <th>Оборот Дт</th>
                        <th>Оборот Кт</th>
                        <th>Сальдо</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr>
                            <td>
                                {{ $row['contract_name'] ?: 'Без названия договора' }}
                                @if (! empty($row['contract_external_id']))
                                    <div class="space-osv__muted">{{ $row['contract_external_id'] }}</div>
                                @endif
                                @if (! empty($row['tenant_name']))
                                    <div class="space-osv__muted">{{ $row['tenant_name'] }}</div>
                                @endif
                            </td>
                            <td>{{ $row['organization_name'] ?: '—' }}</td>
                            <td>{{ $money($row['opening_net'] ?? null) }}</td>
                            <td>{{ $money($row['turnover_debit'] ?? null) }}</td>
                            <td>{{ $money($row['turnover_credit'] ?? null) }}</td>
                            <td><strong>{{ $money($row['closing_net'] ?? null) }}</strong></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @else
        <div class="space-osv__note">{{ $emptyReason ?: 'Нет данных ОСВ 1С для отображения.' }}</div>
    @endif

    <div class="space-osv__footer">
        <div class="space-osv__muted">
            ОСВ показывает сальдо и обороты за период. Оплаты и начисления остаются детализацией движения, а не заменяются этим блоком.
        </div>
        @if ($settlementsUrl)
            <a class="space-osv__link" href="{{ $settlementsUrl }}">Открыть расчеты 1С</a>
        @endif
    </div>
</div>
