<x-filament-panels::page>
    @include('filament.partials.admin-workspace-styles')

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
                                Единая рабочая точка для шаблонов отчётов, расписаний и истории запусков.
                                Отсюда удобно переходить к настройке регулярных отчётов и контролю последних запусков.
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

        <div class="aw-grid">
            <div class="aw-column aw-column--sidebar">
                <section class="aw-panel">
                    <div class="aw-panel-head">
                        <div>
                            <h2 class="aw-panel-title">Рабочие сценарии</h2>
                            <p class="aw-panel-copy">Быстрые переходы к настройке шаблонов и просмотру истории запусков.</p>
                        </div>
                    </div>

                    <div class="aw-panel-body">
                        <div class="aw-action-grid">
                            <a href="{{ $this->getTemplateUrl() }}" class="aw-link-card">
                                <div class="aw-link-icon">
                                    <x-filament::icon icon="heroicon-o-document-text" class="h-5 w-5" />
                                </div>
                                <div>
                                    <p class="aw-link-title">Шаблоны отчётов</p>
                                    <p class="aw-link-copy">Типы отчётов, параметры, расписания и получатели для регулярных запусков.</p>
                                    <p class="aw-link-meta">Открыть список шаблонов</p>
                                </div>
                            </a>

                            <a href="{{ $this->getRunsUrl() }}" class="aw-link-card">
                                <div class="aw-link-icon">
                                    <x-filament::icon icon="heroicon-o-play-circle" class="h-5 w-5" />
                                </div>
                                <div>
                                    <p class="aw-link-title">История запусков</p>
                                    <p class="aw-link-copy">Статусы, файлы, ошибки и время выполнения по уже сформированным отчётам.</p>
                                    <p class="aw-link-meta">Открыть историю запусков</p>
                                </div>
                            </a>
                        </div>
                    </div>
                </section>
            </div>

            <div class="aw-column aw-column--content">
                <section class="aw-panel">
                    <div class="aw-panel-head">
                        <div>
                            <h2 class="aw-panel-title">Состояние отчётного контура</h2>
                            <p class="aw-panel-copy">Ключевые сигналы по активности шаблонов и последним запускам.</p>
                        </div>
                    </div>

                    <div class="aw-panel-body">
                        <div class="aw-stat-grid">
                            <div class="aw-stat-card">
                                <div class="aw-stat-label">Ошибочных запусков</div>
                                <div class="aw-stat-value">{{ number_format($this->getFailedRunCount(), 0, ',', ' ') }}</div>
                            </div>

                            <div class="aw-stat-card">
                                <div class="aw-stat-label">Последний запуск</div>
                                <div class="aw-stat-value" style="font-size:1.1rem;">
                                    {{ $this->getLastRunLabel() ?? 'Ещё не запускались' }}
                                </div>
                            </div>

                            <div class="aw-stat-card">
                                <div class="aw-stat-label">Статус последнего</div>
                                <div class="aw-stat-value" style="font-size:1.1rem;">
                                    {{ $this->getLatestRunStatusLabel() ?? 'Нет данных' }}
                                </div>
                            </div>
                        </div>

                        <div class="aw-inline-actions" style="margin-top: 1.25rem;">
                            <a href="{{ $this->getTemplateUrl() }}" class="aw-chip">
                                <x-filament::icon icon="heroicon-m-cog-6-tooth" class="h-4 w-4" />
                                Настроить шаблоны
                            </a>

                            <a href="{{ $this->getRunsUrl() }}" class="aw-chip">
                                <x-filament::icon icon="heroicon-m-clock" class="h-4 w-4" />
                                Посмотреть историю
                            </a>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </div>
</x-filament-panels::page>
