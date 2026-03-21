<x-filament::section>
    @include('filament.partials.admin-workspace-styles')

    <div class="aw-shell aw-shell--tasks">
        <section class="aw-hero aw-hero--tasks">
            <div class="aw-hero-stack aw-hero-stack--tasks">
                <div class="aw-hero-copy aw-hero-copy--tasks">
                    <div class="aw-hero-title">
                        <div class="aw-hero-icon">
                            <x-filament::icon icon="heroicon-o-clipboard-document-list" class="h-6 w-6" />
                        </div>

                        <div>
                            <h1 class="aw-hero-heading">Задачи</h1>
                            <p class="aw-hero-subheading">
                                Операционный список задач рынка: назначение, контроль сроков и переключение между списком и календарём.
                            </p>
                        </div>
                    </div>
                </div>

                <div class="aw-tasks-toolbar">
                    <div class="aw-tasks-toolbar__main">
                        <div class="aw-view-switch" role="tablist" aria-label="Режим просмотра задач">
                            <a
                                href="{{ $listUrl }}"
                                class="aw-view-switch__item {{ $viewMode === 'list' ? 'is-active' : '' }}"
                                aria-current="{{ $viewMode === 'list' ? 'page' : 'false' }}"
                            >
                                Список
                            </a>

                            <a
                                href="{{ $calendarUrl }}"
                                class="aw-view-switch__item {{ $viewMode === 'calendar' ? 'is-active' : '' }}"
                                aria-current="{{ $viewMode === 'calendar' ? 'page' : 'false' }}"
                            >
                                Календарь
                            </a>
                        </div>
                    </div>

                    <div class="aw-inline-actions aw-inline-actions--tasks">
                        @if ($viewMode !== 'calendar' && filled($eventsUrl))
                            <a href="{{ $eventsUrl }}" class="aw-link-card aw-link-card--task-action">
                                <div class="aw-link-icon aw-link-icon--task-action">
                                    <x-filament::icon icon="heroicon-o-calendar-days" class="h-5 w-5" />
                                </div>
                                <div>
                                    <p class="aw-link-title">События</p>
                                    <p class="aw-link-copy aw-link-copy--task-action">Праздники, акции и внутренние события рынка.</p>
                                </div>
                            </a>
                        @endif

                        @if ($viewMode !== 'calendar' && filled($requestsUrl))
                            <a href="{{ $requestsUrl }}" class="aw-link-card aw-link-card--task-action">
                                <div class="aw-link-icon aw-link-icon--task-action">
                                    <x-filament::icon icon="heroicon-o-chat-bubble-left-right" class="h-5 w-5" />
                                </div>
                                <div>
                                    <p class="aw-link-title">Обращения</p>
                                    <p class="aw-link-copy aw-link-copy--task-action">Перейти к заявкам и диалогам.</p>
                                </div>
                            </a>
                        @endif

                        @if (filled($createUrl))
                            <a href="{{ $createUrl }}" class="aw-link-card aw-link-card--task-action aw-link-card--task-primary">
                                <div class="aw-link-icon aw-link-icon--task-action">
                                    <x-filament::icon icon="heroicon-o-plus" class="h-5 w-5" />
                                </div>
                                <div>
                                    <p class="aw-link-title">Создать задачу</p>
                                    <p class="aw-link-copy aw-link-copy--task-action">
                                        {{ $viewMode === 'calendar' ? 'Добавить задачу из календарного режима.' : 'Новое поручение без лишней навигации.' }}
                                    </p>
                                </div>
                            </a>
                        @endif
                    </div>
                </div>
            </div>
        </section>
    </div>
</x-filament::section>
