<x-filament::section>
    @include('filament.partials.admin-workspace-styles')

    <div class="aw-shell">
        <section class="aw-hero">
            <div class="aw-hero-grid">
                <div class="aw-hero-copy">
                    <div class="aw-hero-title">
                        <div class="aw-hero-icon">
                            <x-filament::icon icon="heroicon-o-banknotes" class="h-6 w-6" />
                        </div>

                        <div>
                            <h1 class="aw-hero-heading">Начисления</h1>
                            <p class="aw-hero-subheading">
                                Детализация начислений по 1С и историческому импортному слою. Основной рабочий сценарий —
                                смотреть 1С-начисления и отдельно контролировать строки без договора.
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
                        <div class="aw-stat-label">Всего строк</div>
                        <div class="aw-stat-value">{{ number_format($total, 0, ',', ' ') }}</div>
                    </div>

                    <div class="aw-stat-card">
                        <div class="aw-stat-label">1С</div>
                        <div class="aw-stat-value">{{ number_format($oneC, 0, ',', ' ') }}</div>
                    </div>

                    <div class="aw-stat-card">
                        <div class="aw-stat-label">Последний период</div>
                        <div class="aw-stat-value" style="font-size:1.15rem;">{{ $latestPeriodLabel }}</div>
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
                            <p class="aw-panel-copy">Переходите сразу в нужный слой без ручной переборки вкладок.</p>
                        </div>
                    </div>

                    <div class="aw-panel-body">
                        <div class="aw-action-grid">
                            <a href="{{ $oneCUrl }}" class="aw-link-card">
                                <div class="aw-link-icon">
                                    <x-filament::icon icon="heroicon-o-building-office-2" class="h-5 w-5" />
                                </div>
                                <div>
                                    <p class="aw-link-title">1С-начисления</p>
                                    <p class="aw-link-copy">Основной слой начислений, если 1С уже передала строки за период.</p>
                                </div>
                            </a>

                            <a href="{{ $withoutContractUrl }}" class="aw-link-card">
                                <div class="aw-link-icon">
                                    <x-filament::icon icon="heroicon-o-link-slash" class="h-5 w-5" />
                                </div>
                                <div>
                                    <p class="aw-link-title">Без договора</p>
                                    <p class="aw-link-copy">Строки, которые ещё не удалось безопасно связать с договором.</p>
                                </div>
                            </a>

                            <a href="{{ $historyUrl }}" class="aw-link-card">
                                <div class="aw-link-icon">
                                    <x-filament::icon icon="heroicon-o-clock" class="h-5 w-5" />
                                </div>
                                <div>
                                    <p class="aw-link-title">Исторический импорт</p>
                                    <p class="aw-link-copy">Старый CSV-слой, который больше не считается финансовой истиной.</p>
                                </div>
                            </a>

                            <a href="{{ $allUrl }}" class="aw-link-card">
                                <div class="aw-link-icon">
                                    <x-filament::icon icon="heroicon-o-rectangle-stack" class="h-5 w-5" />
                                </div>
                                <div>
                                    <p class="aw-link-title">Все начисления</p>
                                    <p class="aw-link-copy">Полный реестр строк с фильтрами и ручной проверкой исключений.</p>
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
                            <h2 class="aw-panel-title">Состояние контура</h2>
                            <p class="aw-panel-copy">
                                На этой странице одновременно живут 1С-начисления и старый импортный слой. Ключевой
                                сигнал контроля — строки без договора и объём исторического хвоста.
                            </p>
                        </div>
                    </div>

                    <div class="aw-panel-body">
                        <div class="aw-stat-grid">
                            <div class="aw-stat-card">
                                <div class="aw-stat-label">1С-строк</div>
                                <div class="aw-stat-value">{{ number_format($oneC, 0, ',', ' ') }}</div>
                            </div>

                            <div class="aw-stat-card">
                                <div class="aw-stat-label">Без договора</div>
                                <div class="aw-stat-value">{{ number_format($withoutContract, 0, ',', ' ') }}</div>
                            </div>

                            <div class="aw-stat-card">
                                <div class="aw-stat-label">Исторический слой</div>
                                <div class="aw-stat-value">{{ number_format($history, 0, ',', ' ') }}</div>
                            </div>

                            <div class="aw-stat-card">
                                <div class="aw-stat-label">Всего строк</div>
                                <div class="aw-stat-value">{{ number_format($total, 0, ',', ' ') }}</div>
                            </div>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </div>
</x-filament::section>
