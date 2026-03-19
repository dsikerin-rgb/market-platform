<x-filament::section>
    @include('filament.partials.admin-workspace-styles')

    <div class="aw-shell">
        <section class="aw-hero">
            <div class="aw-hero-grid">
                <div class="aw-hero-copy">
                    <div class="aw-hero-title">
                        <div class="aw-hero-icon">
                            <x-filament::icon icon="heroicon-o-home-modern" class="h-6 w-6" />
                        </div>

                        <div>
                            <h1 class="aw-hero-heading">Торговые места</h1>
                            <p class="aw-hero-subheading">
                                Каталог мест рынка с текущими статусами, группами и быстрыми переходами в связанные рабочие разделы.
                            </p>
                        </div>
                    </div>
                </div>

                <div class="aw-stat-grid">
                    <div class="aw-stat-card">
                        <div class="aw-stat-label">Рынок</div>
                        <div class="aw-stat-value" style="font-size: 1.15rem;">
                            {{ $marketName ?: 'Выберите рынок' }}
                        </div>
                    </div>

                    <div class="aw-stat-card">
                        <div class="aw-stat-label">Всего мест</div>
                        <div class="aw-stat-value">{{ number_format($total, 0, ',', ' ') }}</div>
                    </div>

                    <div class="aw-stat-card">
                        <div class="aw-stat-label">Занято</div>
                        <div class="aw-stat-value">{{ number_format($occupied, 0, ',', ' ') }}</div>
                    </div>

                    <div class="aw-stat-card">
                        <div class="aw-stat-label">Свободно</div>
                        <div class="aw-stat-value">{{ number_format($vacant, 0, ',', ' ') }}</div>
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
                            <p class="aw-panel-copy">Быстрые переходы для работы с каталогом мест и смежными разделами.</p>
                        </div>
                    </div>

                    <div class="aw-panel-body">
                        <div class="aw-action-grid">
                            <a href="{{ $allUrl }}" class="aw-link-card">
                                <div class="aw-link-icon">
                                    <x-filament::icon icon="heroicon-o-home-modern" class="h-5 w-5" />
                                </div>
                                <div>
                                    <p class="aw-link-title">Все места</p>
                                    <p class="aw-link-copy">Полный каталог мест с фильтрами по статусу, арендаторам и группам.</p>
                                </div>
                            </a>

                            @if ($createUrl)
                                <a href="{{ $createUrl }}" class="aw-link-card">
                                    <div class="aw-link-icon">
                                        <x-filament::icon icon="heroicon-o-plus" class="h-5 w-5" />
                                    </div>
                                    <div>
                                        <p class="aw-link-title">Создать место</p>
                                        <p class="aw-link-copy">Добавление нового торгового места в каталог рынка.</p>
                                    </div>
                                </a>
                            @endif

                            <a href="{{ $contractsUrl }}" class="aw-link-card">
                                <div class="aw-link-icon">
                                    <x-filament::icon icon="heroicon-o-document-text" class="h-5 w-5" />
                                </div>
                                <div>
                                    <p class="aw-link-title">Договоры</p>
                                    <p class="aw-link-copy">Проверка привязки договоров к местам и финансового контура.</p>
                                </div>
                            </a>

                            <a href="{{ $tenantsUrl }}" class="aw-link-card">
                                <div class="aw-link-icon">
                                    <x-filament::icon icon="heroicon-o-users" class="h-5 w-5" />
                                </div>
                                <div>
                                    <p class="aw-link-title">Арендаторы</p>
                                    <p class="aw-link-copy">Переход к арендаторам, занятости мест и связанным карточкам.</p>
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
                            <h2 class="aw-panel-title">Срез по каталогу мест</h2>
                            <p class="aw-panel-copy">
                                Быстрая сводка по текущей занятости, группам мест и техническому состоянию каталога.
                            </p>
                        </div>
                    </div>

                    <div class="aw-panel-body">
                        <div class="aw-stat-grid">
                            <div class="aw-stat-card">
                                <div class="aw-stat-label">Групповые места</div>
                                <div class="aw-stat-value">{{ number_format($grouped, 0, ',', ' ') }}</div>
                            </div>

                            <div class="aw-stat-card">
                                <div class="aw-stat-label">На обслуживании</div>
                                <div class="aw-stat-value">{{ number_format($maintenance, 0, ',', ' ') }}</div>
                            </div>

                        </div>
                    </div>
                </section>
            </div>
        </div>
    </div>
</x-filament::section>
