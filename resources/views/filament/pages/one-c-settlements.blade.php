<x-filament-panels::page>
    @php
        $report = $this->getReport();
        $summary = $report['summary'];
        $filteredSummary = $report['filteredSummary'];
        $pagination = $report['pagination'];
        $rows = $report['rows'];
        $formatMoney = static fn (float $value): string => number_format($value, abs($value - round($value)) < 0.01 ? 0 : 2, ',', ' ') . ' ₽';
        $formatNumber = static fn (int $value): string => number_format($value, 0, ',', ' ');
        $formatDateTime = static function (mixed $value): string {
            if (blank($value)) {
                return '—';
            }

            try {
                return \Carbon\CarbonImmutable::parse((string) $value)->format('d.m.Y H:i');
            } catch (\Throwable) {
                return (string) $value;
            }
        };
        $netClosing = (float) $summary['closing_debit'] - (float) $summary['closing_credit'];
        $filteredNetClosing = (float) $filteredSummary['closing_debit'] - (float) $filteredSummary['closing_credit'];
        $statusLabels = [
            'debt' => 'Долг',
            'credit' => 'Переплата',
            'zero' => 'Закрыто',
        ];
        $statusStyles = [
            'debt' => 'background:#fee2e2;color:#b91c1c;border-color:#fecaca;',
            'credit' => 'background:#fef3c7;color:#b45309;border-color:#fde68a;',
            'zero' => 'background:#dcfce7;color:#166534;border-color:#bbf7d0;',
        ];
    @endphp

    <style>
        .onec-settlements {
            display: grid;
            gap: 20px;
        }

        .onec-settlements-summary {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 16px;
        }

        .onec-settlements-card,
        .onec-settlements-panel {
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            background: #fff;
            box-shadow: 0 1px 3px rgba(15, 23, 42, .08);
        }

        .onec-settlements-card {
            min-width: 0;
            padding: 16px;
        }

        .onec-settlements-label {
            color: #6b7280;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .04em;
            line-height: 1.2;
            text-transform: uppercase;
        }

        .onec-settlements-value {
            margin-top: 6px;
            color: #111827;
            font-size: 20px;
            font-weight: 700;
            line-height: 1.2;
            overflow-wrap: anywhere;
        }

        .onec-settlements-note {
            margin-top: 6px;
            color: #6b7280;
            font-size: 12px;
            line-height: 1.35;
        }

        .onec-settlements-panel-header {
            border-bottom: 1px solid #e5e7eb;
            padding: 16px;
        }

        .onec-settlements-panel-title {
            color: #111827;
            font-size: 18px;
            font-weight: 700;
            line-height: 1.25;
        }

        .onec-settlements-panel-description {
            color: #6b7280;
            font-size: 13px;
            line-height: 1.4;
            margin-top: 4px;
        }

        .onec-settlements-panel-body {
            padding: 16px;
        }

        .onec-settlements-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 16px;
        }

        .onec-settlements-filters {
            display: inline-flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
        }

        .onec-settlements-control,
        .onec-settlements-search-input {
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

        .onec-settlements-control:focus,
        .onec-settlements-search-input:focus {
            border-color: #0ea5e9;
            box-shadow: 0 0 0 3px rgba(14, 165, 233, .16);
            outline: none;
        }

        .onec-settlements-chipset {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            min-height: 44px;
            border: 1px solid #d1d5db;
            border-radius: 14px;
            background: #fff;
            padding: 6px;
            box-shadow: 0 1px 3px rgba(15, 23, 42, .08);
        }

        .onec-settlements-chip {
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

        .onec-settlements-chip:hover {
            background: #f9fafb;
            color: #0369a1;
        }

        .onec-settlements-chip.is-active {
            background: #f3f4f6;
            color: #0369a1;
        }

        .onec-settlements-search {
            position: relative;
            width: min(360px, 100%);
        }

        .onec-settlements-search-input {
            width: 100%;
            padding-left: 38px;
        }

        .onec-settlements-search-icon {
            color: #9ca3af;
            font-size: 18px;
            left: 13px;
            line-height: 1;
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
        }

        .onec-settlements-table-wrap {
            overflow-x: auto;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
        }

        .onec-settlements-table {
            width: 100%;
            min-width: 1180px;
            border-collapse: collapse;
            background: #fff;
            font-size: 13px;
            line-height: 1.35;
        }

        .onec-settlements-table--compact {
            min-width: 860px;
        }

        .onec-settlements-table th {
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

        .onec-settlements-table td {
            border-bottom: 1px solid #f3f4f6;
            color: #111827;
            padding: 10px 12px;
            vertical-align: top;
        }

        .onec-settlements-table tr:last-child td {
            border-bottom: 0;
        }

        .onec-settlements-table a {
            color: #0369a1;
            font-weight: 600;
            text-decoration: none;
        }

        .onec-settlements-table a:hover {
            color: #0284c7;
            text-decoration: underline;
        }

        .onec-settlements-money {
            font-variant-numeric: tabular-nums;
            text-align: right;
            white-space: nowrap;
        }

        .onec-settlements-col-tenant {
            min-width: 220px;
            max-width: 320px;
        }

        .onec-settlements-col-contract {
            min-width: 260px;
            max-width: 420px;
        }

        .onec-settlements-muted {
            color: #6b7280;
            font-size: 12px;
            line-height: 1.35;
            margin-top: 4px;
        }

        .onec-settlements-badge {
            display: inline-flex;
            border: 1px solid;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            line-height: 1;
            padding: 5px 8px;
            white-space: nowrap;
        }

        .onec-settlements-empty {
            border: 1px dashed #d1d5db;
            border-radius: 8px;
            color: #4b5563;
            font-size: 14px;
            padding: 24px;
        }

        .onec-settlements-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-top: 12px;
        }

        .onec-settlements-pagination-text {
            color: #374151;
            font-size: 15px;
            font-weight: 600;
            line-height: 1.4;
        }

        .onec-settlements-pagination-right {
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }

        .onec-settlements-pagination-actions {
            display: inline-flex;
            gap: 8px;
        }

        .onec-settlements-pagination-button {
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

        .onec-settlements-pagination-button:disabled {
            background: #f9fafb;
            color: #9ca3af;
            cursor: default;
        }

        .onec-settlements-per-page {
            display: inline-flex;
            align-items: center;
            border: 1px solid #d1d5db;
            border-radius: 12px;
            background: #fff;
            box-shadow: 0 1px 2px rgba(15, 23, 42, .04);
            min-height: 44px;
            overflow: hidden;
        }

        .onec-settlements-per-page-label {
            border-right: 1px solid #e5e7eb;
            color: #6b7280;
            font-size: 14px;
            line-height: 20px;
            padding: 11px 14px;
            white-space: nowrap;
        }

        .onec-settlements-per-page-select {
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

        @media (max-width: 1100px) {
            .onec-settlements-summary {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 760px) {
            .onec-settlements-summary {
                grid-template-columns: 1fr;
            }

            .onec-settlements-toolbar {
                align-items: stretch;
                flex-direction: column;
            }

            .onec-settlements-filters,
            .onec-settlements-control,
            .onec-settlements-chipset,
            .onec-settlements-search {
                width: 100%;
            }

            .onec-settlements-chipset {
                overflow-x: auto;
            }

            .onec-settlements-footer {
                align-items: stretch;
                flex-direction: column;
            }

            .onec-settlements-pagination-right {
                align-items: stretch;
                flex-direction: column-reverse;
            }

            .onec-settlements-pagination-actions {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }
    </style>

    <div class="onec-settlements">
        <x-filament::section>
            <x-slot name="heading">
                Расчеты с арендаторами 1С
            </x-slot>

            <x-slot name="description">
                {{ $report['periodLabel'] }} · счет {{ $this->account }} · контрольные суммы ОСВ по данным 1С
            </x-slot>
        </x-filament::section>

        <div class="onec-settlements-summary">
            <div class="onec-settlements-card">
                <div class="onec-settlements-label">Сальдо начальное</div>
                <div class="onec-settlements-value">{{ $formatMoney((float) $summary['opening_debit'] - (float) $summary['opening_credit']) }}</div>
                <div class="onec-settlements-note">Дт {{ $formatMoney((float) $summary['opening_debit']) }} · Кт {{ $formatMoney((float) $summary['opening_credit']) }}</div>
            </div>

            <div class="onec-settlements-card">
                <div class="onec-settlements-label">Обороты за период</div>
                <div class="onec-settlements-value">{{ $formatMoney((float) $summary['turnover_debit'] - (float) $summary['turnover_credit']) }}</div>
                <div class="onec-settlements-note">Дт {{ $formatMoney((float) $summary['turnover_debit']) }} · Кт {{ $formatMoney((float) $summary['turnover_credit']) }}</div>
            </div>

            <div class="onec-settlements-card">
                <div class="onec-settlements-label">Сальдо конечное</div>
                <div class="onec-settlements-value">{{ $formatMoney($netClosing) }}</div>
                <div class="onec-settlements-note">Дт {{ $formatMoney((float) $summary['closing_debit']) }} · Кт {{ $formatMoney((float) $summary['closing_credit']) }}</div>
            </div>

            <div class="onec-settlements-card">
                <div class="onec-settlements-label">Состав</div>
                <div class="onec-settlements-value">{{ $formatNumber((int) $summary['tenants']) }} / {{ $formatNumber((int) $summary['contracts']) }}</div>
                <div class="onec-settlements-note">
                    арендаторы / договоры · строк {{ $formatNumber((int) $summary['rows']) }} · импорт {{ $formatDateTime($summary['imported_at']) }}
                </div>
            </div>
        </div>

        <div class="onec-settlements-panel">
            <div class="onec-settlements-panel-header">
                <div class="onec-settlements-panel-title">Контроль по организациям</div>
                <div class="onec-settlements-panel-description">Эти строки должны сходиться с ОСВ 1С по выбранному периоду и счету</div>
            </div>
            <div class="onec-settlements-panel-body">
                @if (count($report['organizationRows']) === 0)
                    <div class="onec-settlements-empty">Нет данных по организациям.</div>
                @else
                    <div class="onec-settlements-table-wrap">
                        <table class="onec-settlements-table onec-settlements-table--compact">
                            <thead>
                                <tr>
                                    <th scope="col">Организация</th>
                                    <th scope="col" class="onec-settlements-money">Нач. Дт</th>
                                    <th scope="col" class="onec-settlements-money">Нач. Кт</th>
                                    <th scope="col" class="onec-settlements-money">Оборот Дт</th>
                                    <th scope="col" class="onec-settlements-money">Оборот Кт</th>
                                    <th scope="col" class="onec-settlements-money">Кон. Дт</th>
                                    <th scope="col" class="onec-settlements-money">Кон. Кт</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($report['organizationRows'] as $organization)
                                    <tr>
                                        <td>
                                            {{ $organization['organization_name'] ?: '—' }}
                                            <div class="onec-settlements-muted">
                                                {{ $formatNumber((int) $organization['tenants']) }} арендаторов · {{ $formatNumber((int) $organization['rows']) }} строк
                                            </div>
                                        </td>
                                        <td class="onec-settlements-money">{{ $formatMoney((float) $organization['opening_debit']) }}</td>
                                        <td class="onec-settlements-money">{{ $formatMoney((float) $organization['opening_credit']) }}</td>
                                        <td class="onec-settlements-money">{{ $formatMoney((float) $organization['turnover_debit']) }}</td>
                                        <td class="onec-settlements-money">{{ $formatMoney((float) $organization['turnover_credit']) }}</td>
                                        <td class="onec-settlements-money">{{ $formatMoney((float) $organization['closing_debit']) }}</td>
                                        <td class="onec-settlements-money">{{ $formatMoney((float) $organization['closing_credit']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>

        <div class="onec-settlements-panel">
            <div class="onec-settlements-panel-header">
                <div class="onec-settlements-panel-title">Договоры и сальдо</div>
                <div class="onec-settlements-panel-description">Агрегация по арендатору, договору, организации и счету</div>
            </div>

            <div class="onec-settlements-panel-body">
                <div class="onec-settlements-toolbar">
                    <div class="onec-settlements-filters">
                        <input
                            type="date"
                            wire:model.live="fromDate"
                            class="onec-settlements-control"
                            aria-label="Дата с"
                        >

                        <input
                            type="date"
                            wire:model.live="toDate"
                            class="onec-settlements-control"
                            aria-label="Дата по"
                        >

                        <select wire:model.live="account" class="onec-settlements-control" aria-label="Счет">
                            @forelse ($report['accounts'] as $account)
                                <option value="{{ $account }}">{{ $account }}</option>
                            @empty
                                <option value="{{ $this->account }}">{{ $this->account }}</option>
                            @endforelse
                        </select>

                        <div class="onec-settlements-chipset" aria-label="Фильтр статуса">
                            <button type="button" wire:click="$set('status', 'all')" class="onec-settlements-chip {{ $this->status === 'all' ? 'is-active' : '' }}">Все</button>
                            <button type="button" wire:click="$set('status', 'debt')" class="onec-settlements-chip {{ $this->status === 'debt' ? 'is-active' : '' }}">Долг</button>
                            <button type="button" wire:click="$set('status', 'credit')" class="onec-settlements-chip {{ $this->status === 'credit' ? 'is-active' : '' }}">Переплата</button>
                            <button type="button" wire:click="$set('status', 'zero')" class="onec-settlements-chip {{ $this->status === 'zero' ? 'is-active' : '' }}">Закрыто</button>
                            <button type="button" wire:click="$set('status', 'unlinked')" class="onec-settlements-chip {{ $this->status === 'unlinked' ? 'is-active' : '' }}">Без договора</button>
                        </div>
                    </div>

                    <label class="onec-settlements-search">
                        <span class="onec-settlements-search-icon">⌕</span>
                        <input
                            type="search"
                            wire:model.live.debounce.400ms="search"
                            placeholder="Поиск"
                            class="onec-settlements-search-input"
                            aria-label="Поиск по арендатору, договору, организации или документу"
                        >
                    </label>
                </div>

                @if (filled($report['emptyReason']))
                    <div class="onec-settlements-empty">{{ $report['emptyReason'] }}</div>
                @elseif (count($rows) === 0)
                    <div class="onec-settlements-empty">По выбранным фильтрам строк нет.</div>
                @else
                    <div class="onec-settlements-note" style="margin-bottom: 12px;">
                        По фильтру: {{ $formatNumber((int) $filteredSummary['rows']) }} строк · конечное сальдо {{ $formatMoney($filteredNetClosing) }}
                    </div>

                    <div class="onec-settlements-table-wrap">
                        <table class="onec-settlements-table">
                            <thead>
                                <tr>
                                    <th scope="col">Арендатор</th>
                                    <th scope="col">Договор</th>
                                    <th scope="col" class="onec-settlements-money">Нач. Дт</th>
                                    <th scope="col" class="onec-settlements-money">Нач. Кт</th>
                                    <th scope="col" class="onec-settlements-money">Оборот Дт</th>
                                    <th scope="col" class="onec-settlements-money">Оборот Кт</th>
                                    <th scope="col" class="onec-settlements-money">Кон. Дт</th>
                                    <th scope="col" class="onec-settlements-money">Кон. Кт</th>
                                    <th scope="col" class="onec-settlements-money">Итого</th>
                                    <th scope="col">Статус</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($rows as $row)
                                    <tr>
                                        <td class="onec-settlements-col-tenant">
                                            @if ($row['tenant_url'])
                                                <a href="{{ $row['tenant_url'] }}">{{ $row['tenant_name'] }}</a>
                                            @else
                                                {{ $row['tenant_name'] }}
                                            @endif
                                            <div class="onec-settlements-muted">{{ $row['organization_name'] }}</div>
                                        </td>
                                        <td class="onec-settlements-col-contract">
                                            @if ($row['contract_url'])
                                                <a href="{{ $row['contract_url'] }}">{{ $row['contract_name'] }}</a>
                                            @else
                                                {{ $row['contract_name'] }}
                                            @endif
                                            <div class="onec-settlements-muted">
                                                сч. {{ $row['account'] }} · строк ОСВ {{ $formatNumber((int) $row['rows_count']) }}
                                                @if (! $row['linked'])
                                                    · договор не привязан
                                                @endif
                                            </div>
                                        </td>
                                        <td class="onec-settlements-money">{{ $formatMoney((float) $row['opening_debit']) }}</td>
                                        <td class="onec-settlements-money">{{ $formatMoney((float) $row['opening_credit']) }}</td>
                                        <td class="onec-settlements-money">{{ $formatMoney((float) $row['turnover_debit']) }}</td>
                                        <td class="onec-settlements-money">{{ $formatMoney((float) $row['turnover_credit']) }}</td>
                                        <td class="onec-settlements-money">{{ $formatMoney((float) $row['closing_debit']) }}</td>
                                        <td class="onec-settlements-money">{{ $formatMoney((float) $row['closing_credit']) }}</td>
                                        <td class="onec-settlements-money">{{ $formatMoney((float) $row['net']) }}</td>
                                        <td>
                                            <span class="onec-settlements-badge" style="{{ $statusStyles[$row['status']] ?? $statusStyles['zero'] }}">
                                                {{ $statusLabels[$row['status']] ?? 'Закрыто' }}
                                            </span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="onec-settlements-footer">
                        <div class="onec-settlements-pagination-text">
                            Показаны {{ $formatNumber((int) $pagination['from']) }}–{{ $formatNumber((int) $pagination['to']) }}
                            из {{ $formatNumber((int) $pagination['total']) }}
                        </div>

                        <div class="onec-settlements-pagination-right">
                            @if ($pagination['perPage'] !== 'all' && (int) $pagination['lastPage'] > 1)
                                <div class="onec-settlements-pagination-actions">
                                    <button type="button" wire:click="previousPage" @disabled(! $pagination['hasPrevious']) class="onec-settlements-pagination-button">Назад</button>
                                    <button type="button" wire:click="nextPage" @disabled(! $pagination['hasNext']) class="onec-settlements-pagination-button">Вперёд</button>
                                </div>
                            @endif

                            <div class="onec-settlements-per-page">
                                <span class="onec-settlements-per-page-label">на страницу</span>
                                <select wire:model.live="perPage" class="onec-settlements-per-page-select" aria-label="Количество строк на страницу">
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
            </div>
        </div>

        @if (count($report['unresolvedRows']) > 0)
            <div class="onec-settlements-panel">
                <div class="onec-settlements-panel-header">
                    <div class="onec-settlements-panel-title">Непривязанные строки</div>
                    <div class="onec-settlements-panel-description">Первые строки, где договор 1С не сопоставлен с договором в системе</div>
                </div>
                <div class="onec-settlements-panel-body">
                    <div class="onec-settlements-table-wrap">
                        <table class="onec-settlements-table onec-settlements-table--compact">
                            <thead>
                                <tr>
                                    <th scope="col">Арендатор</th>
                                    <th scope="col">Договор</th>
                                    <th scope="col">Документ расчетов</th>
                                    <th scope="col" class="onec-settlements-money">Кон. Дт</th>
                                    <th scope="col" class="onec-settlements-money">Кон. Кт</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($report['unresolvedRows'] as $row)
                                    <tr>
                                        <td>{{ $row['tenant_name'] }}<div class="onec-settlements-muted">{{ $row['organization_name'] }}</div></td>
                                        <td>{{ $row['contract_name'] }}</td>
                                        <td>{{ $row['settlement_document_name'] }}</td>
                                        <td class="onec-settlements-money">{{ $formatMoney((float) $row['closing_debit']) }}</td>
                                        <td class="onec-settlements-money">{{ $formatMoney((float) $row['closing_credit']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
