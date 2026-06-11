<x-filament-panels::page>
    @php
        $report = $this->getReport();
        $summary = $report['summary'];
        $pagination = $report['pagination'];
        $rows = $report['rows'];
        $formatMoney = static fn (?float $value): string => $value === null
            ? '—'
            : number_format($value, abs($value - round($value)) < 0.01 ? 0 : 2, ',', ' ') . ' ₽';
        $formatCount = static fn (int $value): string => number_format($value, 0, ',', ' ');
        $statusLabels = [
            'green' => 'Нет долга',
            'pending' => 'К оплате',
            'orange' => 'Просрочка',
            'red' => '30+ дней',
            'gray' => 'Нет данных',
            'none' => 'Нет ОСВ',
        ];
        $statusClasses = [
            'green' => 'is-green',
            'pending' => 'is-pending',
            'orange' => 'is-orange',
            'red' => 'is-red',
            'gray' => 'is-gray',
            'none' => 'is-gray',
        ];
        $statusOptions = [
            'all' => 'Все',
            'mismatches' => 'Расхождения',
            'more_severe' => 'ОСВ строже',
            'less_severe' => 'ОСВ мягче',
            'closed_by_osv' => 'Закрыто в ОСВ',
            'missing_in_map' => 'Нет на карте',
        ];
        $reasonCounts = $summary['mismatch_reasons'] ?? [];
        $severityCounts = $summary['severity_changes'] ?? [];
    @endphp

    <style>
        .onec-preview {
            display: grid;
            gap: 20px;
            max-width: 100%;
            min-width: 0;
            overflow-x: clip;
        }

        .onec-preview-card,
        .onec-preview-panel {
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            background: #fff;
            box-shadow: 0 1px 3px rgba(15, 23, 42, .08);
            min-width: 0;
        }

        .onec-preview-hero {
            padding: 18px 20px;
        }

        .onec-preview-title {
            color: #111827;
            font-size: 18px;
            font-weight: 700;
            line-height: 1.25;
        }

        .onec-preview-description,
        .onec-preview-note {
            color: #6b7280;
            font-size: 13px;
            line-height: 1.4;
            margin-top: 4px;
        }

        .onec-preview-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 14px;
        }

        .onec-preview-card {
            padding: 16px;
        }

        .onec-preview-label {
            color: #6b7280;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .04em;
            line-height: 1.2;
            text-transform: uppercase;
        }

        .onec-preview-value {
            margin-top: 6px;
            color: #111827;
            font-size: 22px;
            font-weight: 700;
            line-height: 1.2;
        }

        .onec-preview-panel-header {
            border-bottom: 1px solid #e5e7eb;
            padding: 18px 20px;
        }

        .onec-preview-panel-body {
            padding: 18px 20px;
        }

        .onec-preview-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 16px;
            min-width: 0;
        }

        .onec-preview-filters {
            display: inline-flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
            min-width: 0;
        }

        .onec-preview-control,
        .onec-preview-search-input {
            min-height: 40px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            background: #fff;
            color: #111827;
            font-size: 14px;
            line-height: 20px;
            padding: 8px 10px;
            box-shadow: 0 1px 2px rgba(15, 23, 42, .04);
        }

        .onec-preview-control:focus,
        .onec-preview-search-input:focus {
            border-color: #0ea5e9;
            box-shadow: 0 0 0 3px rgba(14, 165, 233, .16);
            outline: none;
        }

        .onec-preview-chipset {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            min-height: 44px;
            border: 1px solid #d1d5db;
            border-radius: 14px;
            background: #fff;
            padding: 6px;
            box-shadow: 0 1px 3px rgba(15, 23, 42, .08);
            max-width: 100%;
        }

        .onec-preview-chip {
            border: 0;
            border-radius: 8px;
            background: transparent;
            color: #4b5563;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            line-height: 20px;
            min-height: 32px;
            padding: 6px 12px;
            white-space: nowrap;
        }

        .onec-preview-chip:hover,
        .onec-preview-chip.is-active {
            background: #f3f4f6;
            color: #0369a1;
        }

        .onec-preview-search {
            flex: 0 1 360px;
            min-width: 220px;
            position: relative;
            width: min(360px, 100%);
        }

        .onec-preview-search-input {
            width: 100%;
            padding-left: 38px;
        }

        .onec-preview-search-icon {
            color: #9ca3af;
            font-size: 18px;
            left: 13px;
            line-height: 1;
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
        }

        .onec-preview-table-wrap {
            max-width: 100%;
            min-width: 0;
            overflow-x: auto;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
        }

        .onec-preview-table {
            width: 100%;
            min-width: 980px;
            border-collapse: collapse;
            background: #fff;
            font-size: 13px;
            line-height: 1.35;
        }

        .onec-preview-table th {
            border-bottom: 1px solid #e5e7eb;
            background: #f9fafb;
            color: #4b5563;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .04em;
            padding: 10px 12px;
            text-align: left;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .onec-preview-table td {
            border-bottom: 1px solid #f3f4f6;
            color: #111827;
            padding: 12px;
            vertical-align: top;
        }

        .onec-preview-table tr:last-child td {
            border-bottom: 0;
        }

        .onec-preview-table a {
            color: #0369a1;
            font-weight: 700;
            text-decoration: none;
        }

        .onec-preview-table a:hover {
            text-decoration: underline;
        }

        .onec-preview-meta {
            color: #6b7280;
            font-size: 12px;
            line-height: 1.35;
            margin-top: 4px;
        }

        .onec-preview-money {
            font-variant-numeric: tabular-nums;
            white-space: nowrap;
        }

        .onec-preview-badge {
            display: inline-flex;
            align-items: center;
            border: 1px solid transparent;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            line-height: 18px;
            padding: 2px 9px;
            white-space: nowrap;
        }

        .onec-preview-badge.is-green {
            background: #dcfce7;
            border-color: #bbf7d0;
            color: #166534;
        }

        .onec-preview-badge.is-pending {
            background: #dcfce7;
            border-color: #86efac;
            color: #15803d;
        }

        .onec-preview-badge.is-orange {
            background: #fef3c7;
            border-color: #fde68a;
            color: #b45309;
        }

        .onec-preview-badge.is-red {
            background: #fee2e2;
            border-color: #fecaca;
            color: #b91c1c;
        }

        .onec-preview-badge.is-gray {
            background: #f3f4f6;
            border-color: #e5e7eb;
            color: #4b5563;
        }

        .onec-preview-pagination {
            align-items: center;
            display: flex;
            gap: 10px;
            justify-content: space-between;
            margin-top: 16px;
        }

        .onec-preview-page-actions {
            align-items: center;
            display: inline-flex;
            gap: 8px;
        }

        .onec-preview-button {
            min-height: 36px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            background: #fff;
            color: #374151;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            padding: 7px 12px;
        }

        .onec-preview-button:disabled {
            color: #9ca3af;
            cursor: not-allowed;
        }

        @media (max-width: 900px) {
            .onec-preview-toolbar,
            .onec-preview-pagination {
                align-items: stretch;
                flex-direction: column;
            }

            .onec-preview-search {
                width: 100%;
            }
        }
    </style>

    <div class="onec-preview">
        <section class="onec-preview-panel onec-preview-hero">
            <div class="onec-preview-title">Предпросмотр решений по цветам карты</div>
            <div class="onec-preview-description">
                Read-only: показывает, как ОСВ 1С изменила бы цвет места. Карта и долги не изменяются.
            </div>
        </section>

        <section class="onec-preview-summary">
            <div class="onec-preview-card">
                <div class="onec-preview-label">Места с арендатором</div>
                <div class="onec-preview-value">{{ $formatCount((int) ($summary['active_spaces_with_tenant'] ?? 0)) }}</div>
                <div class="onec-preview-note">Активные места текущего рынка</div>
            </div>
            <div class="onec-preview-card">
                <div class="onec-preview-label">Расхождения</div>
                <div class="onec-preview-value">{{ $formatCount((int) ($summary['mismatches'] ?? 0)) }}</div>
                <div class="onec-preview-note">Текущий цвет карты отличается от кандидата ОСВ</div>
            </div>
            <div class="onec-preview-card">
                <div class="onec-preview-label">ОСВ строже</div>
                <div class="onec-preview-value">{{ $formatCount((int) ($severityCounts['more_severe'] ?? 0)) }}</div>
                <div class="onec-preview-note">Кандидат ОСВ усиливает статус долга</div>
            </div>
            <div class="onec-preview-card">
                <div class="onec-preview-label">ОСВ мягче</div>
                <div class="onec-preview-value">{{ $formatCount((int) ($severityCounts['less_severe'] ?? 0)) }}</div>
                <div class="onec-preview-note">Кандидат ОСВ снижает статус долга</div>
            </div>
        </section>

        <section class="onec-preview-panel">
            @php
                $agingPolicyLabels = [
                    \App\Services\Debt\DebtDecisionPolicy::AGING_INVOICE_DAY => 'до 10 числа месяца ОСВ',
                    \App\Services\Debt\DebtDecisionPolicy::AGING_PERIOD_START => 'от начала периода ОСВ',
                    \App\Services\Debt\DebtDecisionPolicy::AGING_SETTLEMENT_DOCUMENT => 'от даты документа расчетов',
                ];
            @endphp

            <div class="onec-preview-panel-header">
                <div class="onec-preview-title">Сравнение карты и ОСВ</div>
                <div class="onec-preview-description">
                    Счет {{ $this->account }} · политика срока: {{ $agingPolicyLabels[$this->agingPolicy] ?? $this->agingPolicy }}
                </div>
            </div>

            <div class="onec-preview-panel-body">
                <div class="onec-preview-toolbar">
                    <div class="onec-preview-filters">
                        <select class="onec-preview-control" wire:model.live="account">
                            @foreach ($report['accounts'] as $accountOption)
                                <option value="{{ $accountOption }}">{{ $accountOption }}</option>
                            @endforeach
                            @if (! in_array($this->account, $report['accounts'], true))
                                <option value="{{ $this->account }}">{{ $this->account }}</option>
                            @endif
                        </select>

                        <select class="onec-preview-control" wire:model.live="agingPolicy">
                            <option value="{{ \App\Services\Debt\DebtDecisionPolicy::AGING_INVOICE_DAY }}">Срок до 10 числа</option>
                            <option value="{{ \App\Services\Debt\DebtDecisionPolicy::AGING_PERIOD_START }}">Срок от периода</option>
                            <option value="{{ \App\Services\Debt\DebtDecisionPolicy::AGING_SETTLEMENT_DOCUMENT }}">Срок от документа</option>
                        </select>

                        <div class="onec-preview-chipset" role="group" aria-label="Фильтр строк">
                            @foreach ($statusOptions as $statusValue => $statusLabel)
                                <button
                                    type="button"
                                    class="onec-preview-chip @if ($this->status === $statusValue) is-active @endif"
                                    wire:click="$set('status', '{{ $statusValue }}')"
                                >
                                    {{ $statusLabel }}
                                </button>
                            @endforeach
                        </div>
                    </div>

                    <label class="onec-preview-search">
                        <span class="onec-preview-search-icon">⌕</span>
                        <input
                            class="onec-preview-search-input"
                            type="search"
                            placeholder="Поиск"
                            wire:model.live.debounce.400ms="search"
                        >
                    </label>
                </div>

                @if ($report['emptyReason'])
                    <div class="onec-preview-note">{{ $report['emptyReason'] }}</div>
                @endif

                <div class="onec-preview-table-wrap">
                    <table class="onec-preview-table">
                        <thead>
                        <tr>
                            <th>Место</th>
                            <th>Арендатор</th>
                            <th>Карта сейчас</th>
                            <th>Кандидат ОСВ</th>
                            <th>Сумма ОСВ</th>
                            <th>Изменение</th>
                            <th>Причина</th>
                        </tr>
                        </thead>
                        <tbody>
                        @forelse ($rows as $row)
                            <tr>
                                <td>
                                    @if ($row['space_url'])
                                        <a href="{{ $row['space_url'] }}">{{ $row['space_number'] }}</a>
                                    @else
                                        {{ $row['space_number'] }}
                                    @endif
                                    <div class="onec-preview-meta">{{ $row['current_scope'] }} → {{ $row['candidate_scope'] }}</div>
                                </td>
                                <td>
                                    @if ($row['tenant_url'])
                                        <a href="{{ $row['tenant_url'] }}">{{ $row['tenant_name'] }}</a>
                                    @else
                                        {{ $row['tenant_name'] }}
                                    @endif
                                    @if ($row['contract_names'] !== [])
                                        <div class="onec-preview-meta">{{ implode(', ', array_slice($row['contract_names'], 0, 2)) }}</div>
                                    @endif
                                </td>
                                <td>
                                    <span class="onec-preview-badge {{ $statusClasses[$row['current_status']] ?? 'is-gray' }}">
                                        {{ $statusLabels[$row['current_status']] ?? $row['current_status'] }}
                                    </span>
                                    <div class="onec-preview-meta">{{ $formatMoney($row['current_debt_amount']) }}</div>
                                </td>
                                <td>
                                    <span class="onec-preview-badge {{ $statusClasses[$row['candidate_status']] ?? 'is-gray' }}">
                                        {{ $statusLabels[$row['candidate_status']] ?? $row['candidate_status'] }}
                                    </span>
                                    @if ($row['candidate_due_date'])
                                        <div class="onec-preview-meta">срок {{ $row['candidate_due_date'] }}</div>
                                    @endif
                                </td>
                                <td class="onec-preview-money">{{ $formatMoney($row['candidate_debt_amount']) }}</td>
                                <td>{{ $row['severity_label'] }}</td>
                                <td>
                                    {{ $row['mismatch_label'] }}
                                    <div class="onec-preview-meta">{{ $row['reason'] }}</div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7">Нет строк для отображения</td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="onec-preview-pagination">
                    <div class="onec-preview-title" style="font-size:15px;">
                        Показаны {{ $pagination['from'] }}–{{ $pagination['to'] }} из {{ $formatCount((int) $pagination['total']) }}
                    </div>
                    <div class="onec-preview-page-actions">
                        <button class="onec-preview-button" type="button" wire:click="previousPage" @disabled(! $pagination['hasPrevious'])>
                            Назад
                        </button>
                        <button class="onec-preview-button" type="button" wire:click="nextPage" @disabled(! $pagination['hasNext'])>
                            Вперёд
                        </button>
                        <select class="onec-preview-control" wire:model.live="perPage">
                            <option value="10">на страницу 10</option>
                            <option value="25">на страницу 25</option>
                            <option value="50">на страницу 50</option>
                            <option value="100">на страницу 100</option>
                            <option value="all">все</option>
                        </select>
                    </div>
                </div>
            </div>
        </section>

        @if ($reasonCounts !== [])
            <section class="onec-preview-panel">
                <div class="onec-preview-panel-header">
                    <div class="onec-preview-title">Причины расхождений</div>
                </div>
                <div class="onec-preview-panel-body">
                    <div class="onec-preview-summary">
                        @foreach ($reasonCounts as $reason => $count)
                            <div class="onec-preview-card">
                                <div class="onec-preview-label">{{ $reason }}</div>
                                <div class="onec-preview-value">{{ $formatCount((int) $count) }}</div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </section>
        @endif
    </div>
</x-filament-panels::page>
