<x-filament::section>
    @include('filament.partials.admin-workspace-styles')

    <div class="aw-shell">
        <section class="aw-hero">
            <div class="aw-hero-grid">
                <div class="aw-hero-copy">
                    <div class="aw-hero-title">
                        <div class="aw-hero-icon">
                            <x-filament::icon icon="heroicon-o-users" class="h-6 w-6" />
                        </div>

                        <div>
                            <h1 class="aw-hero-heading">Арендаторы</h1>
                            <p class="aw-hero-subheading">
                                Рабочая база арендаторов рынка: текущие карточки, финансовый контур 1С,
                                связь с местами и смежные действия по договорам, начислениям и обращениям.
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
                        <div class="aw-stat-label">Всего активных</div>
                        <div class="aw-stat-value">{{ number_format($total, 0, ',', ' ') }}</div>
                    </div>

                    <div class="aw-stat-card">
                        <div class="aw-stat-label">С долгом 1С</div>
                        <div class="aw-stat-value">{{ number_format($withDebt, 0, ',', ' ') }}</div>
                    </div>

                    <div class="aw-stat-card">
                        <div class="aw-stat-label">Снимок 1С</div>
                        <div class="aw-stat-value" style="font-size:1.15rem;">{{ $latestSnapshotLabel }}</div>
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
                            <p class="aw-panel-copy">Быстрые переходы в соседние рабочие контуры без ручного поиска по меню.</p>
                        </div>
                    </div>

                    <div class="aw-panel-body">
                        <div class="aw-action-grid">
                            <a href="{{ $allUrl }}" class="aw-link-card">
                                <div class="aw-link-icon">
                                    <x-filament::icon icon="heroicon-o-users" class="h-5 w-5" />
                                </div>
                                <div>
                                    <p class="aw-link-title">Все арендаторы</p>
                                    <p class="aw-link-copy">Полный актуальный реестр карточек арендаторов рынка.</p>
                                </div>
                            </a>

                            <a href="{{ $contractsUrl }}" class="aw-link-card">
                                <div class="aw-link-icon">
                                    <x-filament::icon icon="heroicon-o-document-text" class="h-5 w-5" />
                                </div>
                                <div>
                                    <p class="aw-link-title">Договоры</p>
                                    <p class="aw-link-copy">Разбор договорного контура, привязки к местам и финансового слоя.</p>
                                </div>
                            </a>

                            <a href="{{ $accrualsUrl }}" class="aw-link-card">
                                <div class="aw-link-icon">
                                    <x-filament::icon icon="heroicon-o-banknotes" class="h-5 w-5" />
                                </div>
                                <div>
                                    <p class="aw-link-title">Начисления</p>
                                    <p class="aw-link-copy">1С-начисления, исторический импорт и строки без договора.</p>
                                </div>
                            </a>

                            <a href="{{ $requestsUrl }}" class="aw-link-card">
                                <div class="aw-link-icon">
                                    <x-filament::icon icon="heroicon-o-chat-bubble-left-right" class="h-5 w-5" />
                                </div>
                                <div>
                                    <p class="aw-link-title">Обращения</p>
                                    <p class="aw-link-copy">Диалоги арендаторов, служебная переписка и текущие обращения.</p>
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
                            <h2 class="aw-panel-title">Состояние базы арендаторов</h2>
                            <p class="aw-panel-copy">
                                Быстрый срез по текущим карточкам арендаторов, местам и кабинету без захода в отдельные разделы.
                            </p>
                        </div>
                    </div>

                    <div class="aw-panel-body">
                        <div class="aw-stat-grid">
                            <div class="aw-stat-card">
                                <div class="aw-stat-label">С местами</div>
                                <div class="aw-stat-value">{{ number_format($withPlaces, 0, ',', ' ') }}</div>
                            </div>

                            <div class="aw-stat-card">
                                <div class="aw-stat-label">Без мест</div>
                                <div class="aw-stat-value">{{ number_format($withoutPlaces, 0, ',', ' ') }}</div>
                            </div>

                            <div class="aw-stat-card">
                                <div class="aw-stat-label">С доступом в кабинет</div>
                                <div class="aw-stat-value">{{ number_format($withCabinet, 0, ',', ' ') }}</div>
                            </div>

                            <div class="aw-stat-card">
                                <div class="aw-stat-label">С долгом 1С</div>
                                <div class="aw-stat-value">{{ number_format($withDebt, 0, ',', ' ') }}</div>
                            </div>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </div>
</x-filament::section>
