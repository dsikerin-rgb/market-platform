<x-filament::section>
    @include('filament.partials.admin-workspace-styles')

    <div class="aw-shell">
        <section class="aw-hero">
            <div class="aw-hero-grid">
                <div class="aw-hero-copy">
                    <div class="aw-hero-title">
                        <div class="aw-hero-icon">
                            <x-filament::icon icon="heroicon-o-user-group" class="h-6 w-6" />
                        </div>

                        <div>
                            <h1 class="aw-hero-heading">Сотрудники</h1>
                            <p class="aw-hero-subheading">
                                Внутренний контур сотрудников рынка и управляющей компании: роли, доступы,
                                приглашения и рабочий состав команды по выбранному рынку.
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
                        <div class="aw-stat-label">Всего сотрудников</div>
                        <div class="aw-stat-value">{{ number_format($total, 0, ',', ' ') }}</div>
                    </div>

                    <div class="aw-stat-card">
                        <div class="aw-stat-label">Администраторы рынка</div>
                        <div class="aw-stat-value">{{ number_format($admins, 0, ',', ' ') }}</div>
                    </div>

                    <div class="aw-stat-card">
                        <div class="aw-stat-label">Ожидают приглашения</div>
                        <div class="aw-stat-value">{{ number_format($pendingInvitations, 0, ',', ' ') }}</div>
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
                            <p class="aw-panel-copy">Быстрые действия для управления внутренней командой рынка.</p>
                        </div>
                    </div>

                    <div class="aw-panel-body">
                        <div class="aw-action-grid">
                            <a href="{{ $allUrl }}" class="aw-link-card">
                                <div class="aw-link-icon">
                                    <x-filament::icon icon="heroicon-o-users" class="h-5 w-5" />
                                </div>
                                <div>
                                    <p class="aw-link-title">Список сотрудников</p>
                                    <p class="aw-link-copy">Весь внутренний staff рынка без tenant и merchant пользователей.</p>
                                </div>
                            </a>

                            @if ($createUrl)
                                <a href="{{ $createUrl }}" class="aw-link-card">
                                    <div class="aw-link-icon">
                                        <x-filament::icon icon="heroicon-o-user-plus" class="h-5 w-5" />
                                    </div>
                                    <div>
                                        <p class="aw-link-title">Добавить сотрудника</p>
                                        <p class="aw-link-copy">Создание внутреннего сотрудника рынка с ролью и доступом в панель.</p>
                                    </div>
                                </a>
                            @endif

                            @if ($invitationsUrl)
                                <a href="{{ $invitationsUrl }}" class="aw-link-card">
                                    <div class="aw-link-icon">
                                        <x-filament::icon icon="heroicon-o-envelope-open" class="h-5 w-5" />
                                    </div>
                                    <div>
                                        <p class="aw-link-title">Приглашения</p>
                                        <p class="aw-link-copy">Управление активными приглашениями и проверка неподтверждённых приглашений.</p>
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
                            <h2 class="aw-panel-title">Состав команды</h2>
                            <p class="aw-panel-copy">
                                Быстрый срез по ролям внутри рынка, чтобы не открывать фильтры таблицы для базовой проверки.
                            </p>
                        </div>
                    </div>

                    <div class="aw-panel-body">
                        <div class="aw-stat-grid">
                            <div class="aw-stat-card">
                                <div class="aw-stat-label">Менеджеры</div>
                                <div class="aw-stat-value">{{ number_format($managers, 0, ',', ' ') }}</div>
                            </div>

                            <div class="aw-stat-card">
                                <div class="aw-stat-label">Операторы</div>
                                <div class="aw-stat-value">{{ number_format($operators, 0, ',', ' ') }}</div>
                            </div>

                        </div>
                    </div>
                </section>
            </div>
        </div>
    </div>
</x-filament::section>
