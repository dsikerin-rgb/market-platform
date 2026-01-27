<x-filament-panels::page>
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">

        {{-- Левая колонка: настройки --}}
        <div class="space-y-6 lg:col-span-2">

            <x-filament::section>
                <x-slot name="heading">Параметры рынка</x-slot>
                <x-slot name="description">
                    Базовые настройки рынка. Часовой пояс используется для дедлайнов, уведомлений и отображения дат.
                </x-slot>

                @if (empty($market))
                    <div class="rounded-xl border border-gray-200 bg-white p-4 text-sm text-gray-600 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
                        @if (!empty($isSuperAdmin) && $isSuperAdmin)
                            Рынок не выбран. Выбери рынок (переключатель/фильтр рынка), затем открой страницу снова.
                        @else
                            У пользователя не задан рынок.
                        @endif
                    </div>
                @else
                    <form wire:submit.prevent="save" class="space-y-6">
                        <div class="mx-auto max-w-4xl">
                            {{ $this->form }}
                        </div>

                        <div class="flex items-center justify-end gap-3 pt-2">
                            @if (!empty($canEditMarket) && $canEditMarket)
                                <x-filament::button
                                    type="submit"
                                    color="primary"
                                    icon="heroicon-o-check"
                                    wire:loading.attr="disabled"
                                >
                                    Сохранить
                                </x-filament::button>
                            @else
                                <div class="text-sm text-gray-500 dark:text-gray-400">
                                    Доступно только для просмотра.
                                </div>
                            @endif
                        </div>
                    </form>
                @endif
            </x-filament::section>

        </div>

        {{-- Правая колонка: навигация --}}
        <div class="space-y-6 lg:col-span-1 lg:sticky lg:top-6 self-start">

            @if (! empty($marketsUrl))
                <x-filament::section>
                    <x-slot name="heading">Рынки</x-slot>

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
                </x-filament::section>
            @endif

            <x-filament::section>
                <x-slot name="heading">Структура рынка</x-slot>

                <div class="grid gap-3">
                    @if (! empty($locationTypesUrl))
                        <x-filament::button
                            tag="a"
                            :href="$locationTypesUrl"
                            icon="heroicon-o-rectangle-group"
                            color="primary"
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
                            color="primary"
                            class="w-full justify-start"
                        >
                            Типы мест
                        </x-filament::button>
                    @endif
                </div>
            </x-filament::section>

            <x-filament::section>
                <x-slot name="heading">Люди</x-slot>

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
            </x-filament::section>

            @if (! empty($permissionsUrl) || ! empty($rolesUrl))
                <x-filament::section>
                    <x-slot name="heading">Доступ и роли</x-slot>

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
                </x-filament::section>
            @endif

            @if (! empty($integrationExchangesUrl))
                <x-filament::section>
                    <x-slot name="heading">Интеграции</x-slot>

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
                </x-filament::section>
            @endif

        </div>
    </div>
</x-filament-panels::page>
