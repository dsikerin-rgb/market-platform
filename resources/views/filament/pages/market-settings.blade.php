<x-filament-panels::page>
    @include('filament.partials.admin-workspace-styles')

    @php
        $personalChannels = (array) data_get($data ?? [], 'personal_notification_channels', []);
        $marketplaceSlides = $marketplaceSlidesPreview ?? [];
    @endphp

    <div class="aw-shell">
        @if (empty($market))
            <div class="aw-panel">
                <div class="aw-panel-body">
                    <div class="aw-empty">
                        <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-8 w-8 text-amber-500" />
                        <div class="aw-empty-title">Рынок не выбран</div>
                        <div class="aw-empty-copy">
                            @if (! empty($isSuperAdmin) && $isSuperAdmin)
                                Выберите рынок в переключателе сверху и откройте страницу снова.
                            @else
                                У текущего пользователя не задан рынок.
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        @else
            <div class="aw-hero">
                <div class="aw-hero-grid">
                    <div class="aw-hero-copy">
                        <div class="aw-hero-title">
                            <div class="aw-hero-icon">
                                <x-filament::icon icon="heroicon-m-cog-6-tooth" class="h-6 w-6" />
                            </div>

                            <div>
                                <h2 class="aw-hero-heading">Настройки</h2>
                                <p class="aw-hero-subheading">Рынок: {{ $market->name }}</p>
                            </div>
                        </div>

                        <div class="aw-inline-actions">
                            @if (! empty($userNotificationSettingsUrl))
                                <a href="{{ $userNotificationSettingsUrl }}" class="aw-chip">
                                    <x-filament::icon icon="heroicon-m-bell-alert" class="h-4 w-4" />
                                    Кабинет уведомлений
                                </a>
                            @endif

                            @if (! empty($marketMapViewerUrl))
                                <a href="{{ $marketMapViewerUrl }}" class="aw-chip" target="_blank" rel="noopener">
                                    <x-filament::icon icon="heroicon-m-map" class="h-4 w-4" />
                                    Карта рынка
                                </a>
                            @endif

                            @if (! empty($marketplaceSettingsUrl))
                                <a href="{{ $marketplaceSettingsUrl }}" class="aw-chip">
                                    <x-filament::icon icon="heroicon-m-shopping-bag" class="h-4 w-4" />
                                    Маркетплейс
                                </a>
                            @endif
                        </div>
                    </div>

                    <div class="aw-stat-grid">
                        <div class="aw-stat-card">
                            <div class="aw-stat-label">Рынок</div>
                            <div class="aw-stat-value">{{ $market->id }}</div>
                        </div>

                        <div class="aw-stat-card">
                            <div class="aw-stat-label">Каналы уведомлений</div>
                            <div class="aw-stat-value">{{ count($personalChannels) }}</div>
                        </div>

                        <div class="aw-stat-card">
                            <div class="aw-stat-label">Слайды маркетплейса</div>
                            <div class="aw-stat-value">{{ $marketplaceSlidesCount ?? 0 }}</div>
                        </div>

                        <div class="aw-stat-card">
                            <div class="aw-stat-label">Активные слайды</div>
                            <div class="aw-stat-value">{{ $marketplaceActiveSlidesCount ?? 0 }}</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="aw-grid">
                <div class="aw-column aw-column--sidebar">
                    <div class="aw-panel">
                        <div class="aw-panel-head">
                            <div>
                                <h3 class="aw-panel-title">Кабинет уведомлений</h3>
                                <p class="aw-panel-copy">Личные каналы, темы и статус Telegram текущего пользователя.</p>
                            </div>
                        </div>

                        <div class="aw-panel-body">
                            <div class="aw-list">
                                <div class="aw-list-item">
                                    <div>
                                        <p class="aw-list-title">Текущий статус</p>
                                        <div class="aw-list-copy">{!! $this->renderPersonalNotificationStatus() !!}</div>
                                    </div>
                                </div>
                            </div>

                            <div class="aw-inline-actions">
                                @if (! empty($userNotificationSettingsUrl))
                                    <a href="{{ $userNotificationSettingsUrl }}" class="aw-link-card">
                                        <div class="aw-link-icon">
                                            <x-filament::icon icon="heroicon-m-bell" class="h-5 w-5" />
                                        </div>
                                        <div>
                                            <p class="aw-link-title">Полный кабинет уведомлений</p>
                                            <p class="aw-link-copy">Откройте Telegram, QR-код и расширенные личные настройки.</p>
                                            <div class="aw-link-meta">Открыть кабинет</div>
                                        </div>
                                    </a>
                                @endif
                            </div>
                        </div>
                    </div>

                    <div class="aw-panel">
                        <div class="aw-panel-head">
                            <div>
                                <h3 class="aw-panel-title">Быстрые переходы</h3>
                                <p class="aw-panel-copy">Частые разделы без разрастания боковой навигации.</p>
                            </div>
                        </div>

                        <div class="aw-panel-body">
                            <div class="aw-action-grid">
                                @if (! empty($staffUrl))
                                    <a href="{{ $staffUrl }}" class="aw-link-card">
                                        <div class="aw-link-icon"><x-filament::icon icon="heroicon-m-users" class="h-5 w-5" /></div>
                                        <div>
                                            <p class="aw-link-title">Сотрудники</p>
                                            <p class="aw-link-copy">Внутренние пользователи рынка и управляющей компании.</p>
                                        </div>
                                    </a>
                                @endif

                                @if (! empty($tenantUrl))
                                    <a href="{{ $tenantUrl }}" class="aw-link-card">
                                        <div class="aw-link-icon"><x-filament::icon icon="heroicon-m-user-group" class="h-5 w-5" /></div>
                                        <div>
                                            <p class="aw-link-title">Арендаторы</p>
                                            <p class="aw-link-copy">Карточки арендаторов, долги, договоры и связи с местами.</p>
                                        </div>
                                    </a>
                                @endif

                                @if (! empty($integrationExchangesUrl))
                                    <a href="{{ $integrationExchangesUrl }}" class="aw-link-card">
                                        <div class="aw-link-icon"><x-filament::icon icon="heroicon-m-arrows-right-left" class="h-5 w-5" /></div>
                                        <div>
                                            <p class="aw-link-title">Интеграции</p>
                                            <p class="aw-link-copy">Журнал обменов и результаты последних загрузок из 1С.</p>
                                        </div>
                                    </a>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>

                <div class="aw-column aw-column--content">
                    <form wire:submit.prevent="save" class="aw-panel">
                        <div class="aw-panel-head">
                            <div>
                                <h3 class="aw-panel-title">Параметры рынка</h3>
                                <p class="aw-panel-copy">Основные справочные и операционные настройки рынка, уведомлений и дашборда.</p>
                            </div>
                        </div>

                        <div class="aw-panel-body">
                            {{ $this->form }}
                        </div>

                        <div class="aw-panel-body">
                            <div class="aw-sticky-actions">
                                <div class="aw-actions-row">
                                    @if (!empty($canEditMarket) && $canEditMarket)
                                        <x-filament::button type="submit" color="primary" icon="heroicon-o-check" wire:loading.attr="disabled">
                                            Сохранить
                                        </x-filament::button>

                                        <div class="text-sm text-slate-500 dark:text-slate-400">
                                            Изменения применяются сразу после сохранения.
                                        </div>
                                    @else
                                        <x-filament::button type="button" color="gray" icon="heroicon-o-eye" disabled>
                                            Только просмотр
                                        </x-filament::button>

                                        <div class="text-sm text-slate-500 dark:text-slate-400">
                                            Для этой роли доступен только просмотр параметров рынка.
                                        </div>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
