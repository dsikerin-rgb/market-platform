{{-- resources/views/filament/pages/market-settings.blade.php --}}

<x-filament-panels::page>
    <div class="space-y-6">

        {{-- Header --}}
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div class="space-y-1">
                <div class="text-xl font-semibold text-gray-900 dark:text-gray-100">
                    Настройки рынка
                </div>
                <div class="text-sm text-gray-600 dark:text-gray-400">
                    Базовые параметры, структура, доступ и интеграции. Часовой пояс влияет на дедлайны, уведомления и отображение дат.
                </div>
            </div>

            @if (! empty($market))
                <div class="rounded-xl border border-gray-200 bg-white px-4 py-3 dark:border-gray-800 dark:bg-gray-900">
                    <div class="text-sm font-medium text-gray-900 dark:text-gray-100">
                        {{ $market->name }}
                    </div>
                    @if (! empty($market->address))
                        <div class="mt-0.5 text-xs text-gray-600 dark:text-gray-400">
                            {{ $market->address }}
                        </div>
                    @endif
                    @if (! empty($market->timezone))
                        <div class="mt-1 text-xs text-gray-500 dark:text-gray-500">
                            Часовой пояс: {{ $market->timezone }}
                        </div>
                    @endif
                </div>
            @endif
        </div>

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
            <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">

                {{-- Левая колонка: форма --}}
                <div class="lg:col-span-2">
                    <form wire:submit.prevent="save" class="space-y-6">
                        <div class="mx-auto max-w-4xl">
                            {{ $this->form }}
                        </div>

                        {{-- Панель сохранения (не прилипает к тексту, визуально отделена) --}}
                        <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
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
                    </form>
                </div>

                {{-- Правая колонка: навигация --}}
                <div class="lg:col-span-1 lg:sticky lg:top-6 self-start">
                    <x-filament::section>
                        <x-slot name="heading">Навигация</x-slot>
                        <x-slot name="description">Быстрый доступ к связанным разделам.</x-slot>

                        <div class="space-y-6">

                            @if (! empty($marketsUrl))
                                <div class="space-y-2">
                                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-500">
                                        Рынки
                                    </div>
                                    <div class="grid gap-3">
                                        <x-filament::button
                                            tag="a"
                                            :href="$marketsUrl"
                                            icon="heroicon-o-building-storefront"
                                            color="gray"
                                            class="w-full justify-start"
                                        >
                                            Список рынков
                                        </x-filament::button>
                                    </div>
                                </div>
                            @endif

                            <div class="space-y-2">
                                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-500">
                                    Структура рынка
                                </div>

                                <div class="grid gap-3">
                                    @if (! empty($locationTypesUrl))
                                        <x-filament::button
                                            tag="a"
                                            :href="$locationTypesUrl"
                                            icon="heroicon-o-rectangle-group"
                                            color="gray"
                                            class="w-full justify-start"
                                        >
                                            Типы локаций
                                        </x-filament::button>
                                    @endif

                                    @if (! empty($spaceTypesUrl))
                                        <x-filament::button
                                            tag="a"
                                            :href="$spaceTypesUrl"
                                            icon="heroicon-o-banknotes"
                                            color="gray"
                                            class="w-full justify-start"
                                        >
                                            Типы мест
                                        </x-filament::button>
                                    @endif
                                </div>
                            </div>

                            <div class="space-y-2">
                                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-500">
                                    Люди
                                </div>

                                <div class="grid gap-3">
                                    @if (! empty($staffUrl))
                                        <x-filament::button
                                            tag="a"
                                            :href="$staffUrl"
                                            icon="heroicon-o-users"
                                            color="gray"
                                            class="w-full justify-start"
                                        >
                                            Сотрудники
                                        </x-filament::button>
                                    @endif

                                    @if (! empty($tenantUrl))
                                        <x-filament::button
                                            tag="a"
                                            :href="$tenantUrl"
                                            icon="heroicon-o-user-group"
                                            color="gray"
                                            class="w-full justify-start"
                                        >
                                            Арендаторы
                                        </x-filament::button>
                                    @endif
                                </div>
                            </div>

                            @if (! empty($permissionsUrl) || ! empty($rolesUrl))
                                <div class="space-y-2">
                                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-500">
                                        Доступ и роли
                                    </div>

                                    <div class="grid gap-3">
                                        @if (! empty($permissionsUrl))
                                            <x-filament::button
                                                tag="a"
                                                :href="$permissionsUrl"
                                                icon="heroicon-o-key"
                                                color="gray"
                                                class="w-full justify-start"
                                            >
                                                Права
                                            </x-filament::button>
                                        @endif

                                        @if (! empty($rolesUrl))
                                            <x-filament::button
                                                tag="a"
                                                :href="$rolesUrl"
                                                icon="heroicon-o-shield-check"
                                                color="gray"
                                                class="w-full justify-start"
                                            >
                                                Роли
                                            </x-filament::button>
                                        @endif
                                    </div>
                                </div>
                            @endif

                            @if (! empty($integrationExchangesUrl))
                                <div class="space-y-2">
                                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-500">
                                        Интеграции
                                    </div>

                                    <div class="grid gap-3">
                                        <x-filament::button
                                            tag="a"
                                            :href="$integrationExchangesUrl"
                                            icon="heroicon-o-arrows-right-left"
                                            color="gray"
                                            class="w-full justify-start"
                                        >
                                            Обмены интеграций
                                        </x-filament::button>
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
