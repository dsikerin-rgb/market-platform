<x-filament-panels::page>
    @include('filament.partials.admin-workspace-styles')

    @php
        $section = $this->section;
        $accrualsSummary = $section === 'accruals' ? $this->getAccrualsSummary() : [];
        $documentsSummary = $section === 'documents' ? $this->getDocumentsSummary() : [];
        $settlementsSummary = $section === 'settlements' ? $this->getSettlementsSummary() : [];
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
            max-width: 64rem;
        }

        .reports-hub-section-action {
            margin-top: 1rem;
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
