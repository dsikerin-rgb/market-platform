<x-filament-panels::page>
    @include('filament.partials.admin-workspace-styles')

    @php
        $section = $this->section;
        $accrualsSummary = $section === 'accruals' ? $this->getAccrualsSummary() : [];
        $accrualsPreviewRows = $section === 'accruals' ? $this->getAccrualsPreviewRows() : [];
        $documentsSummary = $section === 'documents' ? $this->getDocumentsSummary() : [];
        $documentsPreviewRows = $section === 'documents' ? $this->getDocumentsPreviewRows() : [];
        $settlementsSummary = $section === 'settlements' ? $this->getSettlementsSummary() : [];
        $settlementsPreviewRows = $section === 'settlements' ? $this->getSettlementsPreviewRows() : [];
        $documentTypeStyles = [
            'accrual' => 'background:#eff6ff;color:#1d4ed8;border-color:#bfdbfe;',
            'payment' => 'background:#dcfce7;color:#166534;border-color:#bbf7d0;',
        ];
        $settlementStatusLabels = [
            'debt' => 'Долг',
            'credit' => 'Переплата',
            'zero' => 'Закрыто',
        ];
        $settlementStatusStyles = [
            'debt' => 'background:#fee2e2;color:#b91c1c;border-color:#fecaca;',
            'credit' => 'background:#fef3c7;color:#b45309;border-color:#fde68a;',
            'zero' => 'background:#dcfce7;color:#166534;border-color:#bbf7d0;',
        ];
    @endphp

    <style>
        .reports-hub-tabs .aw-view-switch__item {
            border: 0;
            background: transparent;
            font-family: inherit;
            cursor: pointer;
        }

        .reports-hub-tabs .aw-view-switch__item.is-active {
            background: #2563eb;
            color: #fff;
        }

        .reports-hub-section {
            max-width: 100%;
            min-width: 0;
        }

        .reports-hub-section-action {
            margin-top: 1rem;
        }

        .reports-hub-preview {
            margin-top: 1rem;
        }

        .reports-hub-preview-title {
            color: #111827;
            font-size: 15px;
            font-weight: 700;
            line-height: 1.35;
            margin: 0 0 .75rem;
        }

        .reports-hub-preview-wrap {
            overflow-x: auto;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            background: #fff;
        }

        .reports-hub-preview-table {
            width: 100%;
            min-width: 760px;
            border-collapse: collapse;
            background: #fff;
            font-size: 13px;
            line-height: 1.35;
        }

        .reports-hub-preview-table th {
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

        .reports-hub-preview-table td {
            border-bottom: 1px solid #f3f4f6;
            color: #111827;
            padding: 10px 12px;
            vertical-align: top;
        }

        .reports-hub-preview-table tr:last-child td {
            border-bottom: 0;
        }

        .reports-hub-preview-table a {
            color: #0369a1;
            font-weight: 600;
            text-decoration: none;
        }

        .reports-hub-preview-table a:hover {
            color: #0284c7;
            text-decoration: underline;
        }

        .reports-hub-money {
            font-variant-numeric: tabular-nums;
            text-align: right;
            white-space: nowrap;
        }

        .reports-hub-muted {
            color: #6b7280;
            font-size: 12px;
            line-height: 1.35;
            margin-top: 4px;
        }

        .reports-hub-badge {
            display: inline-flex;
            align-items: center;
            border: 1px solid;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            line-height: 1;
            padding: 5px 10px;
            white-space: nowrap;
        }
    </style>

    <div class="aw-shell">
        <section class="aw-hero">
            <div class="aw-hero-grid">
                <div class="aw-hero-copy">
                    <div class="aw-hero-title">
                        <div class="aw-hero-icon">
                            <x-filament::icon icon="heroicon-o-document-chart-bar" class="h-6 w-6" />
                        </div>

                        <div>
                            <h1 class="aw-hero-heading">Отчёты</h1>
                            <p class="aw-hero-subheading">
                                Единая рабочая точка для регулярных отчётов и контура 1С:
                                шаблоны, запуски, начисления, документы и расчёты с арендаторами.
                            </p>
                        </div>
                    </div>
                </div>

                <div class="aw-stat-grid">
                    <div class="aw-stat-card">
                        <div class="aw-stat-label">Рынок</div>
                        <div class="aw-stat-value" style="font-size:1.15rem;">
                            {{ $this->getMarketName() }}
                        </div>
                    </div>

                    <div class="aw-stat-card">
                        <div class="aw-stat-label">Шаблонов</div>
                        <div class="aw-stat-value">{{ number_format($this->getReportCount(), 0, ',', ' ') }}</div>
                    </div>

                    <div class="aw-stat-card">
                        <div class="aw-stat-label">Активных</div>
                        <div class="aw-stat-value">{{ number_format($this->getActiveReportCount(), 0, ',', ' ') }}</div>
                    </div>

                    <div class="aw-stat-card">
                        <div class="aw-stat-label">Запусков</div>
                        <div class="aw-stat-value">{{ number_format($this->getRunCount(), 0, ',', ' ') }}</div>
                    </div>
                </div>
            </div>
        </section>

        <section class="aw-panel">
            <div class="aw-panel-head">
                <div>
                    <h2 class="aw-panel-title">Разделы</h2>
                    <p class="aw-panel-copy">Переключение внутри страницы без перехода на отдельные пункты меню.</p>
                </div>
            </div>

            <div class="aw-panel-body">
                <nav class="aw-view-switch reports-hub-tabs" aria-label="Разделы отчётов">
                    <button type="button" wire:click="setSection('templates')" class="aw-view-switch__item {{ $section === 'templates' ? 'is-active' : '' }}">Шаблоны</button>
                    <button type="button" wire:click="setSection('runs')" class="aw-view-switch__item {{ $section === 'runs' ? 'is-active' : '' }}">Запуски</button>
                    <button type="button" wire:click="setSection('accruals')" class="aw-view-switch__item {{ $section === 'accruals' ? 'is-active' : '' }}">Начисления 1С</button>
                    <button type="button" wire:click="setSection('documents')" class="aw-view-switch__item {{ $section === 'documents' ? 'is-active' : '' }}">Документы 1С</button>
                    <button type="button" wire:click="setSection('settlements')" class="aw-view-switch__item {{ $section === 'settlements' ? 'is-active' : '' }}">Расчёты 1С</button>
                </nav>
            </div>
        </section>

        <section class="aw-panel reports-hub-section">
            <div class="aw-panel-head">
                <div>
                    @if ($section === 'templates')
                        <h2 class="aw-panel-title">Шаблоны отчётов</h2>
                        <p class="aw-panel-copy">Настройки регулярных отчётов: типы, параметры, расписания и получатели.</p>
                    @elseif ($section === 'runs')
                        <h2 class="aw-panel-title">Запуски отчётов</h2>
                        <p class="aw-panel-copy">История формирования файлов, статусы последних запусков и ошибки выполнения.</p>
                    @elseif ($section === 'accruals')
                        <h2 class="aw-panel-title">Начисления 1С</h2>
                        <p class="aw-panel-copy">Реестр импортированных начислений, связи с договорами и строки без договора.</p>
                    @elseif ($section === 'documents')
                        <h2 class="aw-panel-title">Документы 1С</h2>
                        <p class="aw-panel-copy">Журнал начислений и оплат из 1С с фильтрами по периоду, типу документа и поиску.</p>
                    @else
                        <h2 class="aw-panel-title">Расчёты 1С</h2>
                        <p class="aw-panel-copy">Сальдо и обороты ОСВ по арендаторам, договорам, организациям и счетам.</p>
                    @endif
                </div>
            </div>

            <div class="aw-panel-body">
                @if ($section === 'templates')
                    <div class="aw-list">
                        <div class="aw-list-item">
                            <div>
                                <p class="aw-list-title">Шаблоны отчётов</p>
                                <p class="aw-list-copy">{{ number_format($this->getActiveReportCount(), 0, ',', ' ') }} активных из {{ number_format($this->getReportCount(), 0, ',', ' ') }}</p>
                            </div>
                        </div>

                        <div class="aw-list-item">
                            <div>
                                <p class="aw-list-title">Регулярность</p>
                                <p class="aw-list-copy">Шаблоны задают расписание, параметры формирования и список получателей.</p>
                            </div>
                        </div>
                    </div>

                    <div class="reports-hub-section-action">
                        <a href="{{ $this->getTemplateUrl() }}" class="aw-chip">
                            <x-filament::icon icon="heroicon-m-cog-6-tooth" class="h-4 w-4" />
                            Открыть шаблоны
                        </a>
                    </div>
                @elseif ($section === 'runs')
                    <div class="aw-list">
                        <div class="aw-list-item">
                            <div>
                                <p class="aw-list-title">Всего запусков</p>
                                <p class="aw-list-copy">{{ number_format($this->getRunCount(), 0, ',', ' ') }}</p>
                            </div>
                        </div>

                        <div class="aw-list-item">
                            <div>
                                <p class="aw-list-title">Последний запуск</p>
                                <p class="aw-list-copy">{{ $this->getLastRunLabel() ?? 'нет данных' }}{{ $this->getLatestRunStatusLabel() ? ' · ' . $this->getLatestRunStatusLabel() : '' }}</p>
                            </div>
                        </div>

                        <div class="aw-list-item">
                            <div>
                                <p class="aw-list-title">Ошибки</p>
                                <p class="aw-list-copy">{{ number_format($this->getFailedRunCount(), 0, ',', ' ') }} ошибочных запусков</p>
                            </div>
                        </div>
                    </div>

                    <div class="reports-hub-section-action">
                        <a href="{{ $this->getRunsUrl() }}" class="aw-chip">
                            <x-filament::icon icon="heroicon-m-clock" class="h-4 w-4" />
                            Открыть запуски
                        </a>
                    </div>
                @elseif ($section === 'accruals')
                    @if ($accrualsSummary['emptyReason'] ?? null)
                        <div class="aw-empty">{{ $accrualsSummary['emptyReason'] }}</div>
                    @else
                        <div class="aw-list">
                            <div class="aw-list-item">
                                <div>
                                    <p class="aw-list-title">Последний период</p>
                                    <p class="aw-list-copy">{{ $accrualsSummary['period'] }} · импорт {{ $accrualsSummary['importedAt'] ?? 'не указан' }}</p>
                                </div>
                            </div>

                            <div class="aw-list-item">
                                <div>
                                    <p class="aw-list-title">Начислено</p>
                                    <p class="aw-list-copy">{{ $this->formatRub($accrualsSummary['total']) }} · строк {{ number_format($accrualsSummary['rows'], 0, ',', ' ') }}</p>
                                </div>
                            </div>

                            <div class="aw-list-item">
                                <div>
                                    <p class="aw-list-title">Связи</p>
                                    <p class="aw-list-copy">
                                        С договором {{ number_format($accrualsSummary['linked'], 0, ',', ' ') }} · без договора {{ number_format($accrualsSummary['unlinked'], 0, ',', ' ') }}
                                        @if ($accrualsSummary['spaces'] !== null)
                                            · мест {{ number_format($accrualsSummary['spaces'], 0, ',', ' ') }}
                                        @endif
                                    </p>
                                </div>
                            </div>
                        </div>

                        @if ($accrualsPreviewRows !== [])
                            <div class="reports-hub-preview">
                                <h3 class="reports-hub-preview-title">Последние строки периода</h3>
                                <div class="reports-hub-preview-wrap">
                                    <table class="reports-hub-preview-table">
                                        <thead>
                                            <tr>
                                                <th>Период</th>
                                                <th>Арендатор</th>
                                                <th>Договор</th>
                                                <th>Импорт</th>
                                                <th class="reports-hub-money">Сумма</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($accrualsPreviewRows as $row)
                                                <tr>
                                                    <td>{{ $row['period'] }}</td>
                                                    <td>
                                                        @if ($row['tenant_url'])
                                                            <a href="{{ $row['tenant_url'] }}">{{ $row['tenant_name'] }}</a>
                                                        @else
                                                            {{ $row['tenant_name'] }}
                                                        @endif
                                                    </td>
                                                    <td>
                                                        @if ($row['contract_url'])
                                                            <a href="{{ $row['contract_url'] }}">{{ $row['contract_name'] }}</a>
                                                        @else
                                                            {{ $row['contract_name'] }}
                                                        @endif
                                                    </td>
                                                    <td>{{ $row['imported_at'] ?? '—' }}</td>
                                                    <td class="reports-hub-money">{{ $this->formatRub($row['amount']) }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        @endif
                    @endif

                    <div class="reports-hub-section-action">
                        <a href="{{ $this->getOneCAccrualsUrl() }}" class="aw-chip">
                            <x-filament::icon icon="heroicon-m-banknotes" class="h-4 w-4" />
                            Открыть начисления
                        </a>
                    </div>
                @elseif ($section === 'documents')
                    @if ($documentsSummary['emptyReason'] ?? null)
                        <div class="aw-empty">{{ $documentsSummary['emptyReason'] }}</div>
                    @else
                        <div class="aw-list">
                            <div class="aw-list-item">
                                <div>
                                    <p class="aw-list-title">Период</p>
                                    <p class="aw-list-copy">{{ $documentsSummary['period'] }}</p>
                                </div>
                            </div>

                            <div class="aw-list-item">
                                <div>
                                    <p class="aw-list-title">Документы</p>
                                    <p class="aw-list-copy">
                                        Всего {{ number_format($documentsSummary['documents'], 0, ',', ' ') }} · начисления {{ number_format($documentsSummary['accruals'], 0, ',', ' ') }} · оплаты {{ number_format($documentsSummary['payments'], 0, ',', ' ') }}
                                    </p>
                                </div>
                            </div>

                            <div class="aw-list-item">
                                <div>
                                    <p class="aw-list-title">Суммы</p>
                                    <p class="aw-list-copy">Начислено {{ $this->formatRub($documentsSummary['accrued']) }} · оплачено {{ $this->formatRub($documentsSummary['paid']) }}</p>
                                </div>
                            </div>
                        </div>

                        @if ($documentsPreviewRows !== [])
                            <div class="reports-hub-preview">
                                <h3 class="reports-hub-preview-title">Первые документы текущего периода</h3>
                                <div class="reports-hub-preview-wrap">
                                    <table class="reports-hub-preview-table">
                                        <thead>
                                            <tr>
                                                <th>Дата</th>
                                                <th>Тип</th>
                                                <th>Документ</th>
                                                <th>Арендатор</th>
                                                <th>Договор</th>
                                                <th class="reports-hub-money">Сумма</th>
                                                <th>Основание</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($documentsPreviewRows as $row)
                                                <tr>
                                                    <td>{{ $row['document_date'] }}</td>
                                                    <td>
                                                        <span class="reports-hub-badge" style="{{ $documentTypeStyles[$row['type']] ?? $documentTypeStyles['accrual'] }}">
                                                            {{ $row['type_label'] }}
                                                        </span>
                                                    </td>
                                                    <td>{{ $row['document_number'] }}</td>
                                                    <td>
                                                        @if ($row['tenant_url'])
                                                            <a href="{{ $row['tenant_url'] }}">{{ $row['tenant_name'] }}</a>
                                                        @else
                                                            {{ $row['tenant_name'] }}
                                                        @endif
                                                    </td>
                                                    <td>
                                                        @if ($row['contract_url'])
                                                            <a href="{{ $row['contract_url'] }}">{{ $row['contract_label'] }}</a>
                                                        @else
                                                            {{ $row['contract_label'] }}
                                                        @endif
                                                    </td>
                                                    <td class="reports-hub-money">{{ $this->formatRub($row['amount']) }}</td>
                                                    <td>{{ \Illuminate\Support\Str::limit($row['basis'], 96) }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        @endif
                    @endif

                    <div class="reports-hub-section-action">
                        <a href="{{ $this->getOneCDocumentsUrl() }}" class="aw-chip">
                            <x-filament::icon icon="heroicon-m-document-text" class="h-4 w-4" />
                            Открыть документы
                        </a>
                    </div>
                @else
                    @if ($settlementsSummary['emptyReason'] ?? null)
                        <div class="aw-empty">{{ $settlementsSummary['emptyReason'] }}</div>
                    @else
                        <div class="aw-list">
                            <div class="aw-list-item">
                                <div>
                                    <p class="aw-list-title">ОСВ 1С</p>
                                    <p class="aw-list-copy">{{ $settlementsSummary['period'] }} · счёт {{ $settlementsSummary['account'] }} · импорт {{ $settlementsSummary['importedAt'] ?? 'не указан' }}</p>
                                </div>
                            </div>

                            <div class="aw-list-item">
                                <div>
                                    <p class="aw-list-title">Состав</p>
                                    <p class="aw-list-copy">
                                        Строк {{ number_format($settlementsSummary['rows'], 0, ',', ' ') }} · арендаторов {{ number_format($settlementsSummary['tenants'], 0, ',', ' ') }} · договоров {{ number_format($settlementsSummary['contracts'], 0, ',', ' ') }}
                                    </p>
                                </div>
                            </div>

                            <div class="aw-list-item">
                                <div>
                                    <p class="aw-list-title">Сальдо конечное</p>
                                    <p class="aw-list-copy">
                                        Итог {{ $this->formatRub($settlementsSummary['closingNet']) }} · Дт {{ $this->formatRub($settlementsSummary['closingDebit']) }} · Кт {{ $this->formatRub($settlementsSummary['closingCredit']) }}
                                    </p>
                                </div>
                            </div>

                            <div class="aw-list-item">
                                <div>
                                    <p class="aw-list-title">Обороты</p>
                                    <p class="aw-list-copy">Дт {{ $this->formatRub($settlementsSummary['turnoverDebit']) }} · Кт {{ $this->formatRub($settlementsSummary['turnoverCredit']) }}</p>
                                </div>
                            </div>
                        </div>

                        @if ($settlementsPreviewRows !== [])
                            <div class="reports-hub-preview">
                                <h3 class="reports-hub-preview-title">Крупнейшие позиции по конечному сальдо</h3>
                                <div class="reports-hub-preview-wrap">
                                    <table class="reports-hub-preview-table">
                                        <thead>
                                            <tr>
                                                <th>Арендатор</th>
                                                <th>Договор</th>
                                                <th>Организация</th>
                                                <th>Счёт</th>
                                                <th class="reports-hub-money">Оборот Дт</th>
                                                <th class="reports-hub-money">Оборот Кт</th>
                                                <th class="reports-hub-money">Итог</th>
                                                <th>Статус</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($settlementsPreviewRows as $row)
                                                <tr>
                                                    <td>
                                                        @if ($row['tenant_url'])
                                                            <a href="{{ $row['tenant_url'] }}">{{ $row['tenant_name'] }}</a>
                                                        @else
                                                            {{ $row['tenant_name'] }}
                                                        @endif
                                                    </td>
                                                    <td>
                                                        @if ($row['contract_url'])
                                                            <a href="{{ $row['contract_url'] }}">{{ $row['contract_name'] }}</a>
                                                        @else
                                                            {{ $row['contract_name'] }}
                                                        @endif
                                                        <div class="reports-hub-muted">строк ОСВ {{ number_format($row['rows_count'], 0, ',', ' ') }}</div>
                                                    </td>
                                                    <td>{{ $row['organization_name'] }}</td>
                                                    <td>{{ $row['account'] }}</td>
                                                    <td class="reports-hub-money">{{ $this->formatRub($row['turnover_debit']) }}</td>
                                                    <td class="reports-hub-money">{{ $this->formatRub($row['turnover_credit']) }}</td>
                                                    <td class="reports-hub-money">{{ $this->formatRub($row['net']) }}</td>
                                                    <td>
                                                        <span class="reports-hub-badge" style="{{ $settlementStatusStyles[$row['status']] ?? $settlementStatusStyles['zero'] }}">
                                                            {{ $settlementStatusLabels[$row['status']] ?? '—' }}
                                                        </span>
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        @endif
                    @endif

                    <div class="reports-hub-section-action">
                        <a href="{{ $this->getOneCSettlementsUrl() }}" class="aw-chip">
                            <x-filament::icon icon="heroicon-m-scale" class="h-4 w-4" />
                            Открыть расчёты
                        </a>
                    </div>
                @endif
            </div>
        </section>
    </div>
</x-filament-panels::page>
