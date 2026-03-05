{{-- resources/views/filament/pages/market-settings.blade.php --}}

<x-filament-panels::page>
    <style>
        .ms-page {
            padding-bottom: 28px;
        }

        .ms-form {
            display: flex;
            flex-direction: column;
            gap: 16px;
            max-width: 960px;
            margin: 0 auto;
        }

        .ms-tools {
            border: 1px solid rgba(156, 163, 175, .25);
            border-radius: 12px;
            background: rgba(255, 255, 255, .8);
            overflow: hidden;
        }

        .dark .ms-tools {
            border-color: rgba(55, 65, 81, .85);
            background: rgba(17, 24, 39, .5);
        }

        .ms-tools > summary {
            cursor: pointer;
            list-style: none;
            padding: 12px 14px;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .ms-tools > summary::-webkit-details-marker {
            display: none;
        }

        .ms-tools-body {
            border-top: 1px solid rgba(156, 163, 175, .2);
            padding: 12px;
            display: grid;
            grid-template-columns: repeat(1, minmax(0, 1fr));
            gap: 10px;
        }

        @media (min-width: 768px) {
            .ms-tools-body {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (min-width: 1280px) {
            .ms-tools-body {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
        }

        .ms-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            width: 100%;
            min-height: 38px;
            padding: 8px 10px;
            border: 1px solid rgba(156, 163, 175, .24);
            border-radius: 10px;
            text-decoration: none;
            color: inherit;
            background: rgba(255, 255, 255, .5);
            font-size: 13px;
            line-height: 1.2;
        }

        .dark .ms-link {
            border-color: rgba(55, 65, 81, .75);
            background: rgba(17, 24, 39, .35);
        }

        .ms-link:hover {
            background: rgba(107, 114, 128, .1);
        }

        .dark .ms-link:hover {
            background: rgba(255, 255, 255, .06);
        }

        .ms-actions {
            border-radius: 12px;
            border: 1px solid rgba(156, 163, 175, .25);
            background: rgba(255, 255, 255, .72);
            backdrop-filter: blur(6px);
            padding: 14px 16px;
            box-shadow: 0 1px 2px rgba(0,0,0,.06);
            margin-top: 4px;
        }

        .dark .ms-actions {
            border-color: rgba(55, 65, 81, .8);
            background: rgba(17, 24, 39, .52);
            box-shadow: 0 1px 2px rgba(0,0,0,.3);
        }

        @media (min-width: 1024px) {
            .ms-actions {
                position: sticky;
                bottom: 20px;
                z-index: 20;
            }
        }

        .ms-actions-row {
            display: flex;
            gap: 12px;
            align-items: center;
            justify-content: flex-start;
            flex-wrap: wrap;
        }
    </style>

    <div class="mx-auto max-w-6xl ms-page">
        @if (empty($market))
            <div class="rounded-xl border border-gray-200 bg-white p-4 text-sm text-gray-700 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
                <div class="flex items-start gap-3">
                    <x-filament::icon icon="heroicon-o-exclamation-triangle" class="mt-0.5 h-5 w-5 text-gray-500" />

                    <div class="space-y-1">
                        <div class="font-medium text-gray-900 dark:text-gray-100">Рынок не выбран</div>
                        <div class="text-sm text-gray-600 dark:text-gray-400">
                            @if (!empty($isSuperAdmin) && $isSuperAdmin)
                                Выберите рынок в переключателе и откройте страницу снова.
                            @else
                                У текущего пользователя не задан рынок.
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        @else
            <form wire:submit.prevent="save" class="ms-form">
                {{ $this->form }}

                <details class="ms-tools">
                    <summary>
                        <span>Быстрые переходы</span>
                        <x-filament::icon icon="heroicon-o-chevron-down" class="h-4 w-4 text-gray-500" />
                    </summary>

                    <div class="ms-tools-body">
                        @if (! empty($marketsUrl))
                            <a class="ms-link" href="{{ $marketsUrl }}">
                                <x-filament::icon icon="heroicon-o-building-storefront" class="h-4 w-4 text-gray-500" />
                                <span>Рынки</span>
                            </a>
                        @endif

                        @if (! empty($locationTypesUrl))
                            <a class="ms-link" href="{{ $locationTypesUrl }}">
                                <x-filament::icon icon="heroicon-o-rectangle-group" class="h-4 w-4 text-gray-500" />
                                <span>Типы локаций</span>
                            </a>
                        @endif

                        @if (! empty($spaceTypesUrl))
                            <a class="ms-link" href="{{ $spaceTypesUrl }}">
                                <x-filament::icon icon="heroicon-o-banknotes" class="h-4 w-4 text-gray-500" />
                                <span>Типы мест</span>
                            </a>
                        @endif

                        @if (! empty($staffUrl))
                            <a class="ms-link" href="{{ $staffUrl }}">
                                <x-filament::icon icon="heroicon-o-users" class="h-4 w-4 text-gray-500" />
                                <span>Сотрудники</span>
                            </a>
                        @endif

                        @if (! empty($tenantUrl))
                            <a class="ms-link" href="{{ $tenantUrl }}">
                                <x-filament::icon icon="heroicon-o-user-group" class="h-4 w-4 text-gray-500" />
                                <span>Арендаторы</span>
                            </a>
                        @endif

                        @if (! empty($permissionsUrl))
                            <a class="ms-link" href="{{ $permissionsUrl }}">
                                <x-filament::icon icon="heroicon-o-key" class="h-4 w-4 text-gray-500" />
                                <span>Права</span>
                            </a>
                        @endif

                        @if (! empty($rolesUrl))
                            <a class="ms-link" href="{{ $rolesUrl }}">
                                <x-filament::icon icon="heroicon-o-shield-check" class="h-4 w-4 text-gray-500" />
                                <span>Роли</span>
                            </a>
                        @endif

                        @if (! empty($integrationExchangesUrl))
                            <a class="ms-link" href="{{ $integrationExchangesUrl }}">
                                <x-filament::icon icon="heroicon-o-arrows-right-left" class="h-4 w-4 text-gray-500" />
                                <span>Интеграции</span>
                            </a>
                        @endif
                    </div>
                </details>

                <div class="ms-actions">
                    <div class="ms-actions-row">
                        @if (!empty($canEditMarket) && $canEditMarket)
                            <x-filament::button
                                type="submit"
                                color="primary"
                                icon="heroicon-o-check"
                                wire:loading.attr="disabled"
                            >
                                Сохранить
                            </x-filament::button>

                            <div class="text-sm text-gray-600 dark:text-gray-400">
                                Изменения применяются после сохранения.
                            </div>
                        @else
                            <x-filament::button
                                type="button"
                                color="gray"
                                icon="heroicon-o-eye"
                                disabled
                            >
                                Только просмотр
                            </x-filament::button>

                            <div class="text-sm text-gray-600 dark:text-gray-400">
                                Доступно только для просмотра.
                            </div>
                        @endif
                    </div>
                </div>
            </form>
        @endif
    </div>
</x-filament-panels::page>
