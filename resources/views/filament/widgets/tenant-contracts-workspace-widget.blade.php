<x-filament::section>
    @include('filament.partials.admin-workspace-styles')

    <div class="aw-shell">
        <section class="aw-hero">
            <div class="aw-hero-grid">
                <div class="aw-hero-copy">
                    <div class="aw-hero-title">
                        <div class="aw-hero-icon">
                            <x-filament::icon icon="heroicon-o-document-text" class="h-6 w-6" />
                        </div>

                        <div>
                            <h1 class="aw-hero-heading">Договоры</h1>
                            <p class="aw-hero-subheading">
                                Рабочий договорный контур рынка: текущие договоры, привязка к местам и исключения,
                                которые ещё требуют ручного решения.
                            </p>
                        </div>
                    </div>
                </div>

                <div class="aw-stat-grid">
                    <div class="aw-stat-card">
                        <div class="aw-stat-label">Рынок</div>
                        <div class="aw-stat-value" style="font-size:1.15rem;">
                            {{ $marketName ?: 'Выберите рынок' }}
                        </div>
                    </div>

                    <div class="aw-stat-card">
                        <div class="aw-stat-label">Рабочий контур</div>
                        <div class="aw-stat-value">{{ number_format($operationalCount, 0, ',', ' ') }}</div>
                    </div>

                    <div class="aw-stat-card">
                        <div class="aw-stat-label">Без места</div>
                        <div class="aw-stat-value">{{ number_format($withoutSpaceCount, 0, ',', ' ') }}</div>
                    </div>

                    <div class="aw-stat-card">
                        <div class="aw-stat-label">Ручная фиксация</div>
                        <div class="aw-stat-value">{{ number_format($manualCount, 0, ',', ' ') }}</div>
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
                            <p class="aw-panel-copy">Переходите сразу в нужный слой без ручного перебора вкладок.</p>
                        </div>
                    </div>

                    <div class="aw-panel-body">
                        <div class="aw-action-grid">
                            <a href="{{ $operationalUrl }}" class="aw-link-card">
                                <div class="aw-link-icon">
                                    <x-filament::icon icon="heroicon-o-briefcase" class="h-5 w-5" />
                                </div>
                                <div>
                                    <p class="aw-link-title">Рабочий контур</p>
                                    <p class="aw-link-copy">Актуальные договоры, которые участвуют в текущей картине рынка.</p>
                                </div>
                            </a>

                            <a href="{{ $withoutSpaceUrl }}" class="aw-link-card">
                                <div class="aw-link-icon">
                                    <x-filament::icon icon="heroicon-o-map-pin" class="h-5 w-5" />
                                </div>
                                <div>
                                    <p class="aw-link-title">Без привязки к месту</p>
                                    <p class="aw-link-copy">Договоры, которые ещё не удалось уверенно связать с торговым местом.</p>
                                </div>
                            </a>

                            <a href="{{ $allUrl }}" class="aw-link-card">
                                <div class="aw-link-icon">
                                    <x-filament::icon icon="heroicon-o-rectangle-stack" class="h-5 w-5" />
                                </div>
                                <div>
                                    <p class="aw-link-title">Все договоры</p>
                                    <p class="aw-link-copy">Полный реестр для глубокой проверки и исторического хвоста.</p>
                                </div>
                            </a>

                            @if ($canSeeTechnicalTabs)
                                <a href="{{ $latestDebtUrl }}" class="aw-link-card">
                                    <div class="aw-link-icon">
                                        <x-filament::icon icon="heroicon-o-banknotes" class="h-5 w-5" />
                                    </div>
                                    <div>
                                        <p class="aw-link-title">Последняя задолженность</p>
                                        <p class="aw-link-copy">Только договоры из последнего снимка долгов 1С.</p>
                                    </div>
                                </a>

                                <a href="{{ $mappingUrl }}" class="aw-link-card">
                                    <div class="aw-link-icon">
                                        <x-filament::icon icon="heroicon-o-link" class="h-5 w-5" />
                                    </div>
                                    <div>
                                        <p class="aw-link-title">Кандидаты на привязку</p>
                                        <p class="aw-link-copy">Основные договоры, где уже можно искать безопасную связь с местом.</p>
                                    </div>
                                </a>
                            @endif
                        </div>
                    </div>
                </section>
            </div>

            <div class="aw-column aw-column--content">
                <section class="aw-panel">
                    <div class="aw-panel-head">
                        <div>
                            <h2 class="aw-panel-title">Состояние договорного контура</h2>
                            <p class="aw-panel-copy">
                                Ключевые сигналы по привязке договоров к местам и текущему финансовому охвату.
                            </p>
                        </div>
                    </div>

                    <div class="aw-panel-body">
                        <div class="aw-stat-grid">
                            <div class="aw-stat-card">
                                <div class="aw-stat-label">Последняя задолженность</div>
                                <div class="aw-stat-value">{{ number_format($latestDebtCount, 0, ',', ' ') }}</div>
                            </div>

                            <div class="aw-stat-card">
                                <div class="aw-stat-label">Договоры из начислений</div>
                                <div class="aw-stat-value">{{ number_format($latestAccrualCount, 0, ',', ' ') }}</div>
                            </div>

                            <div class="aw-stat-card">
                                <div class="aw-stat-label">Исключены из привязки</div>
                                <div class="aw-stat-value">{{ number_format($excludedCount, 0, ',', ' ') }}</div>
                            </div>

                            <div class="aw-stat-card">
                                <div class="aw-stat-label">Ручная фиксация</div>
                                <div class="aw-stat-value">{{ number_format($manualCount, 0, ',', ' ') }}</div>
                            </div>
                        </div>

                        @if ($canSeeTechnicalTabs)
                            <div class="aw-inline-actions" style="margin-top: 1.25rem;">
                                <a href="{{ $overlapsUrl }}" class="aw-chip">
                                    <x-filament::icon icon="heroicon-m-arrows-right-left" class="h-4 w-4" />
                                    С наложением
                                </a>

                                <a href="{{ $reviewUrl }}" class="aw-chip">
                                    <x-filament::icon icon="heroicon-m-exclamation-circle" class="h-4 w-4" />
                                    Требуют разбора
                                </a>

                                <a href="{{ $accrualsUrl }}" class="aw-chip">
                                    <x-filament::icon icon="heroicon-m-calculator" class="h-4 w-4" />
                                    Договоры из начислений
                                </a>
                            </div>
                        @endif
                    </div>
                </section>
            </div>
        </div>
    </div>
</x-filament::section>
