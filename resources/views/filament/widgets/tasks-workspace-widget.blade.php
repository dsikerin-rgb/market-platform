<x-filament::section>
    @include('filament.partials.admin-workspace-styles')

    <div class="aw-shell">
        <section class="aw-hero">
            <div class="aw-hero-grid">
                <div class="aw-hero-copy">
                    <div class="aw-hero-title">
                        <div class="aw-hero-icon">
                            <x-filament::icon icon="heroicon-o-clipboard-document-list" class="h-6 w-6" />
                        </div>

                        <div>
                            <h1 class="aw-hero-heading">Задачи</h1>
                            <p class="aw-hero-subheading">
                                Рабочий контур задач рынка: открытые, просроченные, критичные и требующие назначения.
                                Переключение между списком и календарём, а также создание новых задач доступно в быстрых переходах.
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
                        <div class="aw-stat-label">Открыто</div>
                        <div class="aw-stat-value">{{ number_format($open, 0, ',', ' ') }}</div>
                    </div>

                    <div class="aw-stat-card">
                        <div class="aw-stat-label">Просрочено</div>
                        <div class="aw-stat-value">{{ number_format($overdue, 0, ',', ' ') }}</div>
                    </div>

                    <div class="aw-stat-card">
                        <div class="aw-stat-label">Ближайший срок</div>
                        <div class="aw-stat-value" style="font-size:1.15rem;">{{ $nearestDeadline }}</div>
                    </div>
                </div>
            </div>
        </section>

        <div class="aw-grid">
            <div class="aw-column aw-column--sidebar">
                <section class="aw-panel">
                    <div class="aw-panel-head">
                        <div>
                            <h2 class="aw-panel-title">Быстрые переходы</h2>
                            <p class="aw-panel-copy">Открывайте нужный режим работы и создавайте задачи без лишней навигации по странице.</p>
                        </div>
                    </div>

                    <div class="aw-panel-body">
                        <div class="aw-action-grid">
                            <a href="{{ $createUrl }}" class="aw-link-card">
                                <div class="aw-link-icon">
                                    <x-filament::icon icon="heroicon-o-plus" class="h-5 w-5" />
                                </div>
                                <div>
                                    <p class="aw-link-title">Создать задачу</p>
                                    <p class="aw-link-copy">Быстрое создание новой задачи с постановкой срока и исполнителя.</p>
                                </div>
                            </a>

                            <a href="{{ $listUrl }}" class="aw-link-card">
                                <div class="aw-link-icon">
                                    <x-filament::icon icon="heroicon-o-list-bullet" class="h-5 w-5" />
                                </div>
                                <div>
                                    <p class="aw-link-title">Список задач</p>
                                    <p class="aw-link-copy">Все открытые и завершённые задачи с вкладками по ролям и состояниям.</p>
                                </div>
                            </a>

                            <a href="{{ $calendarUrl }}" class="aw-link-card">
                                <div class="aw-link-icon">
                                    <x-filament::icon icon="heroicon-o-calendar-days" class="h-5 w-5" />
                                </div>
                                <div>
                                    <p class="aw-link-title">Календарь задач</p>
                                    <p class="aw-link-copy">Планирование по срокам, праздникам и загрузке команды.</p>
                                </div>
                            </a>

                            <a href="{{ $requestsUrl }}" class="aw-link-card">
                                <div class="aw-link-icon">
                                    <x-filament::icon icon="heroicon-o-chat-bubble-left-right" class="h-5 w-5" />
                                </div>
                                <div>
                                    <p class="aw-link-title">Обращения</p>
                                    <p class="aw-link-copy">Быстрый переход в заявки и диалоги, из которых чаще всего рождаются задачи.</p>
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
                            <h2 class="aw-panel-title">Состояние задач</h2>
                            <p class="aw-panel-copy">Срез по текущей рабочей нагрузке и задачам, которые требуют внимания команды.</p>
                        </div>
                    </div>

                    <div class="aw-panel-body">
                        <div class="aw-stat-grid">
                            <div class="aw-stat-card">
                                <div class="aw-stat-label">В работе</div>
                                <div class="aw-stat-value">{{ number_format($inProgress, 0, ',', ' ') }}</div>
                            </div>

                            <div class="aw-stat-card">
                                <div class="aw-stat-label">Критичные</div>
                                <div class="aw-stat-value">{{ number_format($urgent, 0, ',', ' ') }}</div>
                            </div>

                            <div class="aw-stat-card">
                                <div class="aw-stat-label">Без исполнителя</div>
                                <div class="aw-stat-value">{{ number_format($unassigned, 0, ',', ' ') }}</div>
                            </div>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </div>
</x-filament::section>
