<x-filament::section>
    @include('filament.partials.admin-workspace-styles')

    <div class="aw-shell">
        <section class="aw-hero">
            <div class="aw-hero-grid">
                <div class="aw-hero-copy">
                    <div class="aw-hero-title">
                        <div class="aw-hero-icon">
                            <x-filament::icon icon="heroicon-o-calendar-days" class="h-6 w-6" />
                        </div>

                        <div>
                            <h1 class="aw-hero-heading">Календарь</h1>
                            <p class="aw-hero-subheading">
                                Календарь праздников, промо-активностей и внутренних событий рынка с быстрым переходом между списком и месячной сеткой.
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
                        <div class="aw-stat-label">Текущий месяц</div>
                        <div class="aw-stat-value" style="font-size:1.15rem; text-transform: capitalize;">
                            {{ $monthLabel }}
                        </div>
                    </div>

                    <div class="aw-stat-card">
                        <div class="aw-stat-label">Событий в месяце</div>
                        <div class="aw-stat-value">{{ number_format($thisMonth, 0, ',', ' ') }}</div>
                    </div>

                    <div class="aw-stat-card">
                        <div class="aw-stat-label">Ближайшее событие</div>
                        <div class="aw-stat-value" style="font-size:1.15rem;">{{ $nearestEventDate }}</div>
                    </div>
                </div>
            </div>
        </section>

        <div class="aw-grid">
            <div class="aw-column aw-column--sidebar">
                <section class="aw-panel">
                    <div class="aw-panel-head">
                        <div>
                            <h2 class="aw-panel-title">Режимы просмотра</h2>
                            <p class="aw-panel-copy">Открывайте нужный формат календаря без лишней навигации.</p>
                        </div>
                    </div>

                    <div class="aw-panel-body">
                        <div class="aw-action-grid">
                            <a href="{{ $listUrl }}" class="aw-link-card">
                                <div class="aw-link-icon">
                                    <x-filament::icon icon="heroicon-o-list-bullet" class="h-5 w-5" />
                                </div>
                                <div>
                                    <p class="aw-link-title">Список событий</p>
                                    <p class="aw-link-copy">Подходит для поиска, редактирования карточек и быстрой фильтрации событий.</p>
                                </div>
                            </a>

                            <a href="{{ $calendarUrl }}" class="aw-link-card">
                                <div class="aw-link-icon">
                                    <x-filament::icon icon="heroicon-o-calendar-days" class="h-5 w-5" />
                                </div>
                                <div>
                                    <p class="aw-link-title">Календарная сетка</p>
                                    <p class="aw-link-copy">Помесячный обзор праздников, акций и внутренних активностей рынка.</p>
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
                            <h2 class="aw-panel-title">Сводка календаря</h2>
                            <p class="aw-panel-copy">Текущий объём событий и основные типы, которые попадают в рабочий календарь рынка.</p>
                        </div>
                    </div>

                    <div class="aw-panel-body">
                        <div class="aw-stat-grid">
                            <div class="aw-stat-card">
                                <div class="aw-stat-label">Всего будущих</div>
                                <div class="aw-stat-value">{{ number_format($totalUpcoming, 0, ',', ' ') }}</div>
                            </div>

                            <div class="aw-stat-card">
                                <div class="aw-stat-label">В этом месяце</div>
                                <div class="aw-stat-value">{{ number_format($thisMonth, 0, ',', ' ') }}</div>
                            </div>

                            <div class="aw-stat-card">
                                <div class="aw-stat-label">Праздники</div>
                                <div class="aw-stat-value">{{ number_format($holidays, 0, ',', ' ') }}</div>
                            </div>

                            <div class="aw-stat-card">
                                <div class="aw-stat-label">Промо</div>
                                <div class="aw-stat-value">{{ number_format($promotions, 0, ',', ' ') }}</div>
                            </div>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </div>
</x-filament::section>
