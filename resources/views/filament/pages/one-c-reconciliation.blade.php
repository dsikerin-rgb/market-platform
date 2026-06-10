<x-filament-panels::page>
    @php
        $report = $this->getReport();
        $rows = $report['displayRows'];
        $pagination = $report['pagination'];
        $summary = $report['filteredSummary'];
        $totalSummary = $report['summary'];
        $formatMoney = static fn (float $value): string => number_format($value, abs($value - round($value)) < 0.01 ? 0 : 2, ',', ' ') . ' ₽';
        $statusStyles = [
            'debt' => 'background:#fee2e2;color:#991b1b;border-color:#fecaca;',
            'overpaid' => 'background:#fef3c7;color:#92400e;border-color:#fde68a;',
            'closed' => 'background:#dcfce7;color:#166534;border-color:#bbf7d0;',
        ];
    @endphp

    <style>
        .onec-reconciliation {
            display: grid;
            gap: 20px;
        }

        .onec-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 16px;
        }

        .onec-toolbar-filters {
            display: inline-flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
        }

        .onec-toolbar-control,
        .onec-search-input {
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

        .onec-toolbar-control:focus,
        .onec-search-input:focus {
            border-color: #0ea5e9;
            box-shadow: 0 0 0 3px rgba(14, 165, 233, .16);
            outline: none;
        }

        .onec-toolbar-control {
            width: auto;
        }

        .onec-search {
            position: relative;
            width: min(320px, 100%);
        }

        .onec-search-input {
            width: 100%;
            padding-left: 38px;
        }

        .onec-search-icon {
            color: #9ca3af;
            font-size: 18px;
            left: 13px;
            line-height: 1;
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
        }

        .onec-summary {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 16px;
        }

        .onec-card {
            min-width: 0;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            background: #fff;
            padding: 16px;
            box-shadow: 0 1px 3px rgba(15, 23, 42, .08);
        }

        .onec-card-label {
            color: #6b7280;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .04em;
            line-height: 1.2;
            text-transform: uppercase;
        }

        .onec-card-value {
            margin-top: 6px;
            color: #111827;
            font-size: 20px;
            font-weight: 700;
            line-height: 1.2;
            overflow-wrap: anywhere;
        }

        .onec-card-note {
            margin-top: 6px;
            color: #6b7280;
            font-size: 12px;
            line-height: 1.35;
        }

        .onec-table-wrap {
            overflow-x: auto;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
        }

        .onec-table-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-top: 12px;
        }

        .onec-pagination-text {
            color: #374151;
            font-size: 15px;
            font-weight: 600;
            line-height: 1.4;
        }

        .onec-pagination-right {
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }

        .onec-pagination-actions {
            display: inline-flex;
            gap: 8px;
        }

        .onec-pagination-button {
            border: 1px solid #d1d5db;
            border-radius: 8px;
            background: #fff;
            color: #374151;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            min-height: 34px;
            padding: 7px 12px;
        }

        .onec-pagination-button:disabled {
            background: #f9fafb;
            color: #9ca3af;
            cursor: default;
        }

        .onec-per-page {
            display: inline-flex;
            align-items: center;
            border: 1px solid #d1d5db;
            border-radius: 12px;
            background: #fff;
            box-shadow: 0 1px 2px rgba(15, 23, 42, .04);
            min-height: 44px;
            overflow: hidden;
        }

        .onec-per-page-label {
            border-right: 1px solid #e5e7eb;
            color: #6b7280;
            font-size: 14px;
            line-height: 20px;
            padding: 11px 14px;
            white-space: nowrap;
        }

        .onec-per-page-select {
            appearance: auto;
            border: 0;
            background: #fff;
            color: #111827;
            font-size: 14px;
            font-weight: 600;
            line-height: 20px;
            min-height: 42px;
            min-width: 78px;
            padding: 8px 12px;
        }

        .onec-per-page-select:focus {
            outline: none;
        }

        .onec-table {
            width: 100%;
            min-width: 980px;
            border-collapse: collapse;
            background: #fff;
            font-size: 13px;
            line-height: 1.35;
        }

        .onec-table th {
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

        .onec-table td {
            border-bottom: 1px solid #f3f4f6;
            color: #111827;
            padding: 10px 12px;
            vertical-align: top;
        }

        .onec-table tr:last-child td {
            border-bottom: 0;
        }

        .onec-table a {
            color: #0369a1;
            font-weight: 600;
            text-decoration: none;
        }

        .onec-table a:hover {
            color: #0284c7;
            text-decoration: underline;
        }

        .onec-col-tenant {
            min-width: 220px;
            max-width: 300px;
        }

        .onec-col-contract {
            min-width: 320px;
            max-width: 460px;
        }

        .onec-money,
        .onec-count {
            font-variant-numeric: tabular-nums;
            text-align: right;
            white-space: nowrap;
        }

        .onec-status {
            display: inline-flex;
            border: 1px solid;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            line-height: 1;
            padding: 5px 8px;
            white-space: nowrap;
        }

        .onec-empty {
            border: 1px dashed #d1d5db;
            border-radius: 8px;
            color: #4b5563;
            font-size: 14px;
            padding: 24px;
        }

        .onec-delta-positive {
            color: #dc2626;
        }

        .onec-delta-negative {
            color: #d97706;
        }

        .onec-delta-zero {
            color: #16a34a;
        }

        @media (max-width: 1100px) {
            .onec-summary {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 760px) {
            .onec-summary {
                grid-template-columns: 1fr;
            }

            .onec-toolbar {
                align-items: stretch;
                flex-direction: column;
            }

            .onec-toolbar-filters,
            .onec-toolbar-control,
            .onec-search {
                width: 100%;
            }

            .onec-table-footer {
                align-items: stretch;
                flex-direction: column;
            }

            .onec-pagination-right {
                align-items: stretch;
                flex-direction: column-reverse;
            }

            .onec-pagination-actions {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }
    </style>

    <div class="onec-reconciliation">
        <x-filament::section>
            <x-slot name="heading">
                Сверка начислений и оплат 1С
            </x-slot>

            <x-slot name="description">
                {{ $report['monthLabel'] }} · полный список по арендаторам и договорам
            </x-slot>
        </x-filament::section>

        <div class="onec-summary">
            <div class="onec-card">
                <div class="onec-card-label">Начислено</div>
                <div class="onec-card-value">{{ $formatMoney((float) $summary['accrued']) }}</div>
                <div class="onec-card-note">Всего: {{ $formatMoney((float) $totalSummary['accrued']) }}</div>
            </div>

            <div class="onec-card">
                <div class="onec-card-label">Оплачено</div>
                <div class="onec-card-value">{{ $formatMoney((float) $summary['paid']) }}</div>
                <div class="onec-card-note">Всего: {{ $formatMoney((float) $totalSummary['paid']) }}</div>
            </div>

            <div class="onec-card">
                <div class="onec-card-label">Разница</div>
                <div class="onec-card-value {{ ((float) $summary['delta']) > 0.009 ? 'onec-delta-positive' : (((float) $summary['delta']) < -0.009 ? 'onec-delta-negative' : 'onec-delta-zero') }}">
                    {{ $formatMoney((float) $summary['delta']) }}
                </div>
                <div class="onec-card-note">Всего: {{ $formatMoney((float) $totalSummary['delta']) }}</div>
            </div>

            <div class="onec-card">
                <div class="onec-card-label">Строки</div>
                <div class="onec-card-value">{{ number_format((int) $summary['rows_count'], 0, ',', ' ') }}</div>
                <div class="onec-card-note">
                    долг: {{ $summary['debt_count'] }} · переплата: {{ $summary['overpaid_count'] }} · закрыто: {{ $summary['closed_count'] }}
                </div>
            </div>
        </div>

        <x-filament::section>
            <x-slot name="heading">
                Детализация
            </x-slot>

            <x-slot name="description">
                В колонке «Строки»: начисления / оплаты
            </x-slot>

            <div class="onec-toolbar">
                <div class="onec-toolbar-filters">
                    <input
                        type="month"
                        wire:model.live="period"
                        class="onec-toolbar-control"
                        aria-label="Период"
                    >

                    <select
                        wire:model.live="status"
                        class="onec-toolbar-control"
                        aria-label="Статус"
                    >
                        <option value="open">Открытые расхождения</option>
                        <option value="debt">Долг</option>
                        <option value="overpaid">Переплата</option>
                        <option value="closed">Закрыто</option>
                        <option value="all">Все строки</option>
                    </select>
                </div>

                <label class="onec-search">
                    <span class="onec-search-icon">⌕</span>
                    <input
                        type="search"
                        wire:model.live.debounce.400ms="search"
                        placeholder="Поиск"
                        class="onec-search-input"
                        aria-label="Поиск по арендатору или договору"
                    >
                </label>
            </div>

            @if (filled($report['emptyReason']))
                <div class="onec-empty">
                    {{ $report['emptyReason'] }}
                </div>
            @elseif (count($rows) === 0)
                <div class="onec-empty">
                    По выбранным фильтрам строк нет.
                </div>
            @else
                <div class="onec-table-wrap">
                    <table class="onec-table">
                        <thead>
                            <tr>
                                <th scope="col">Арендатор</th>
                                <th scope="col">Договор</th>
                                <th scope="col" class="onec-money">Начислено</th>
                                <th scope="col" class="onec-money">Оплачено</th>
                                <th scope="col" class="onec-money">Разница</th>
                                <th scope="col">Статус</th>
                                <th scope="col" class="onec-count">Строки</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $row)
                                <tr>
                                    <td class="onec-col-tenant">
                                        @if ($row['tenant_url'])
                                            <a href="{{ $row['tenant_url'] }}">
                                                {{ $row['tenant_name'] }}
                                            </a>
                                        @else
                                            <span>{{ $row['tenant_name'] }}</span>
                                        @endif
                                    </td>

                                    <td class="onec-col-contract">
                                        @if ($row['contract_url'])
                                            <a href="{{ $row['contract_url'] }}">
                                                {{ $row['contract_label'] }}
                                            </a>
                                        @else
                                            <span>{{ $row['contract_label'] }}</span>
                                        @endif
                                    </td>

                                    <td class="onec-money">
                                        {{ $formatMoney((float) $row['accrued']) }}
                                    </td>
                                    <td class="onec-money">
                                        {{ $formatMoney((float) $row['paid']) }}
                                    </td>
                                    <td class="onec-money {{ ((float) $row['delta']) > 0.009 ? 'onec-delta-positive' : (((float) $row['delta']) < -0.009 ? 'onec-delta-negative' : 'onec-delta-zero') }}">
                                        {{ $formatMoney((float) $row['delta']) }}
                                    </td>
                                    <td>
                                        <span class="onec-status" style="{{ $statusStyles[$row['status']] ?? $statusStyles['closed'] }}">
                                            {{ $row['status_label'] }}
                                        </span>
                                    </td>
                                    <td class="onec-count">
                                        {{ $row['accrual_rows'] }} / {{ $row['payment_rows'] }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="onec-table-footer">
                    <div class="onec-pagination-text">
                        @if ((int) $pagination['total'] === 0)
                            Нет строк для отображения
                        @else
                            Показаны {{ number_format((int) $pagination['from'], 0, ',', ' ') }}–{{ number_format((int) $pagination['to'], 0, ',', ' ') }}
                            из {{ number_format((int) $pagination['total'], 0, ',', ' ') }}
                        @endif
                    </div>

                    <div class="onec-pagination-right">
                        @if ($pagination['perPage'] !== 'all' && (int) $pagination['lastPage'] > 1)
                            <div class="onec-pagination-actions">
                                <button
                                    type="button"
                                    wire:click="previousPage"
                                    @disabled(! $pagination['hasPrevious'])
                                    class="onec-pagination-button"
                                >
                                    Назад
                                </button>
                                <button
                                    type="button"
                                    wire:click="nextPage"
                                    @disabled(! $pagination['hasNext'])
                                    class="onec-pagination-button"
                                >
                                    Вперёд
                                </button>
                            </div>
                        @endif

                        <div class="onec-per-page">
                            <span class="onec-per-page-label">на страницу</span>
                            <select
                                wire:model.live="perPage"
                                class="onec-per-page-select"
                                aria-label="Количество строк на страницу"
                            >
                                <option value="10">10</option>
                                <option value="25">25</option>
                                <option value="50">50</option>
                                <option value="100">100</option>
                                <option value="all">Все</option>
                            </select>
                        </div>
                    </div>
                </div>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
