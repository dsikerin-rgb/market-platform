{{-- resources/views/filament/pages/market-settings.blade.php --}}

<x-filament-panels::page>
    <style>
        /* Скрыть стандартный заголовок/подзаголовок Filament на этой странице */
        .fi-page-header,
        header.fi-page-header,
        .fi-page-header-heading,
        .fi-page-header-subheading {
            display: none !important;
        }

        /* Только для этой страницы */
        .ms-page {
            padding-bottom: 28px; /* воздух внизу страницы */
        }

        /* Панель сохранения */
        .ms-actions {
            border-radius: 12px;
            border: 1px solid rgba(156, 163, 175, .25);
            background: rgba(255, 255, 255, .80);
            padding: 14px 16px;
            box-shadow: 0 1px 2px rgba(0,0,0,.06);
            margin-top: 14px;
            margin-bottom: 18px; /* не липнет к следующему блоку */
        }
        .dark .ms-actions {
            border-color: rgba(55, 65, 81, .8);
            background: rgba(17, 24, 39, .55);
            box-shadow: 0 1px 2px rgba(0,0,0,.30);
        }
        @media (min-width: 1024px) {
            .ms-actions {
                position: sticky;
                bottom: 24px;
                z-index: 20;
            }
        }
        .ms-actions-row {
            display: flex;
            gap: 12px;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
        }

        /* Навигация */
        .ms-nav {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .ms-nav-group {
            border-radius: 12px;
            border: 1px solid rgba(156,163,175,.22);
            background: rgba(255,255,255,.45);
            padding: 12px;
        }
        .dark .ms-nav-group {
            border-color: rgba(55,65,81,.85);
            background: rgba(17,24,39,.25);
        }

        .ms-nav-title {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .08em;
            color: rgba(107,114,128,1);
            margin: 0 0 10px 0;
        }
        .dark .ms-nav-title { color: rgba(156,163,175,1); }

        .ms-nav-items {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        /* “Пилюля” — компактная, не на всю ширину */
        .ms-nav-pill {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            max-width: 100%;
            padding: 9px 12px;
            border-radius: 12px;
            border: 1px solid rgba(156,163,175,.22);
            background: rgba(255,255,255,.55);
            text-decoration: none;
            color: inherit;
            line-height: 1.25rem;
        }
        .dark .ms-nav-pill {
            border-color: rgba(55,65,81,.75);
            background: rgba(17,24,39,.35);
        }

        .ms-nav-pill:hover { background: rgba(107,114,128,.10); }
        .dark .ms-nav-pill:hover { background: rgba(255,255,255,.06); }

        .ms-nav-pill:focus-visible {
            outline: none;
            box-shadow: 0 0 0 2px rgba(59,130,246,.55);
        }

        .ms-nav-label {
            min-width: 0;
            font-size: 13px;
            font-weight: 650;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .ms-icon {
            width: 16px;
            height: 16px;
            color: rgba(107,114,128,1);
            flex: 0 0 auto;
        }
        .dark .ms-icon { color: rgba(156,163,175,1); }
    </style>

    <div class="mx-auto max-w-6xl ms-page" style="display:flex; flex-direction:column; gap:24px;">

        @if (empty($market))
            <div class="rounded-xl border border-gray-200 bg-white p-4 text-sm text-gray-700 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
                <div class="flex items-start gap-3">
                    <div class="mt-0.5">
                        <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-5 w-5 text-gray-500" />
                    </div>

                    <div class="space-y-1">
                        <div class="font-medium text-gray-900 dark:text-gray-100">Рынок не выбран</div>
                        <div class="text-sm text-gray-600 dark:text-gray-400">
                            @if (!empty($isSuperAdmin) && $isSuperAdmin)
                                Выбери рынок (переключатель/фильтр рынка), затем открой страницу снова.
                            @else
                                У пользователя не задан рынок.
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        @else
            <div class="grid grid-cols-1 lg:grid-cols-3" style="gap:40px 32px;">

                {{-- Левая колонка: форма --}}
                <div class="min-w-0 lg:col-span-2">
                    <form wire:submit.prevent="save" style="display:flex; flex-direction:column; gap:16px;">
                        <div class="mx-auto w-full max-w-2xl" style="display:flex; flex-direction:column; gap:16px;">
                            {{ $this->form }}

                            {{-- Панель сохранения --}}
                            <div class="ms-actions">
                                <div class="ms-actions-row">
                                    @if (!empty($canEditMarket) && $canEditMarket)
                                        <div class="text-sm text-gray-600 dark:text-gray-400">
                                            Изменения применятся после сохранения.
                                        </div>

                                        <x-filament::button
                                            type="submit"
                                            color="primary"
                                            icon="heroicon-o-check"
                                            wire:loading.attr="disabled"
                                        >
                                            Сохранить
                                        </x-filament::button>
                                    @else
                                        <div class="text-sm text-gray-600 dark:text-gray-400">
                                            Доступно только для просмотра.
                                        </div>

                                        <x-filament::button
                                            type="button"
                                            color="gray"
                                            icon="heroicon-o-eye"
                                            disabled
                                        >
                                            Только просмотр
                                        </x-filament::button>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </form>
                </div>

                {{-- Правая колонка: навигация --}}
                <div class="min-w-0 self-start lg:col-span-1">
                    <x-filament::section>
                        <x-slot name="heading">Навигация</x-slot>

                        <div class="ms-nav">

                            @if (! empty($marketsUrl))
                                <div class="ms-nav-group">
                                    <div class="ms-nav-title">Рынки</div>
                                    <div class="ms-nav-items">
                                        <a class="ms-nav-pill" href="{{ $marketsUrl }}">
                                            <x-filament::icon icon="heroicon-o-building-storefront" class="ms-icon" />
                                            <span class="ms-nav-label">Список рынков</span>
                                        </a>
                                    </div>
                                </div>
                            @endif

                            <div class="ms-nav-group">
                                <div class="ms-nav-title">Структура рынка</div>
                                <div class="ms-nav-items">
                                    @if (! empty($locationTypesUrl))
                                        <a class="ms-nav-pill" href="{{ $locationTypesUrl }}">
                                            <x-filament::icon icon="heroicon-o-rectangle-group" class="ms-icon" />
                                            <span class="ms-nav-label">Типы локаций</span>
                                        </a>
                                    @endif

                                    @if (! empty($spaceTypesUrl))
                                        <a class="ms-nav-pill" href="{{ $spaceTypesUrl }}">
                                            <x-filament::icon icon="heroicon-o-banknotes" class="ms-icon" />
                                            <span class="ms-nav-label">Типы мест</span>
                                        </a>
                                    @endif
                                </div>
                            </div>

                            <div class="ms-nav-group">
                                <div class="ms-nav-title">Люди</div>
                                <div class="ms-nav-items">
                                    @if (! empty($staffUrl))
                                        <a class="ms-nav-pill" href="{{ $staffUrl }}">
                                            <x-filament::icon icon="heroicon-o-users" class="ms-icon" />
                                            <span class="ms-nav-label">Сотрудники</span>
                                        </a>
                                    @endif

                                    @if (! empty($tenantUrl))
                                        <a class="ms-nav-pill" href="{{ $tenantUrl }}">
                                            <x-filament::icon icon="heroicon-o-user-group" class="ms-icon" />
                                            <span class="ms-nav-label">Арендаторы</span>
                                        </a>
                                    @endif
                                </div>
                            </div>

                            @if (! empty($permissionsUrl) || ! empty($rolesUrl))
                                <div class="ms-nav-group">
                                    <div class="ms-nav-title">Доступ и роли</div>
                                    <div class="ms-nav-items">
                                        @if (! empty($permissionsUrl))
                                            <a class="ms-nav-pill" href="{{ $permissionsUrl }}">
                                                <x-filament::icon icon="heroicon-o-key" class="ms-icon" />
                                                <span class="ms-nav-label">Права</span>
                                            </a>
                                        @endif

                                        @if (! empty($rolesUrl))
                                            <a class="ms-nav-pill" href="{{ $rolesUrl }}">
                                                <x-filament::icon icon="heroicon-o-shield-check" class="ms-icon" />
                                                <span class="ms-nav-label">Роли</span>
                                            </a>
                                        @endif
                                    </div>
                                </div>
                            @endif

                            @if (! empty($integrationExchangesUrl))
                                <div class="ms-nav-group">
                                    <div class="ms-nav-title">Интеграции</div>
                                    <div class="ms-nav-items">
                                        <a class="ms-nav-pill" href="{{ $integrationExchangesUrl }}">
                                            <x-filament::icon icon="heroicon-o-arrows-right-left" class="ms-icon" />
                                            <span class="ms-nav-label">Обмены интеграций</span>
                                        </a>
                                    </div>
                                </div>
                            @endif

                        </div>
                    </x-filament::section>
                </div>

            </div>
        @endif

    </div>
</x-filament-panels::page>
