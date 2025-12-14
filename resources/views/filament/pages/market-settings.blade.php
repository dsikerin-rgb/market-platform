<x-filament-panels::page>
    <div class="grid gap-6">

        {{-- Параметры рынка --}}
        <x-filament::section>
            <x-slot name="heading">Параметры рынка</x-slot>

            @if (empty($market))
                <div class="text-sm text-gray-500 leading-relaxed break-words">
                    @if (!empty($isSuperAdmin) && $isSuperAdmin)
                        Рынок не выбран. Выбери рынок (фильтр/переключатель рынка), затем открой эту страницу снова.
                    @else
                        У пользователя не задан рынок.
                    @endif
                </div>
            @else
                <form wire:submit.prevent="save" class="space-y-6">
                    <div class="max-w-2xl">
                        {{ $this->form }}
                    </div>

                    @if (!empty($canEditMarket) && $canEditMarket)
                        <div class="pt-4">
                            <x-filament::button type="submit" color="primary">
                                Сохранить
                            </x-filament::button>
                        </div>
                    @endif
                </form>
            @endif
        </x-filament::section>

        {{-- Рынки (только super-admin) --}}
        @if (! empty($marketsUrl))
            <x-filament::section>
                <x-slot name="heading">Рынки</x-slot>

                <div class="grid gap-3 sm:grid-cols-2">
                    <x-filament::button
                        tag="a"
                        :href="$marketsUrl"
                        icon="heroicon-o-building-storefront"
                        color="gray"
                    >
                        Список рынков
                    </x-filament::button>
                </div>
            </x-filament::section>
        @endif

        {{-- Структура рынка --}}
        <x-filament::section>
            <x-slot name="heading">Структура рынка</x-slot>

            <div class="grid gap-3 sm:grid-cols-2">
                @if (! empty($locationTypesUrl))
                    <x-filament::button
                        tag="a"
                        :href="$locationTypesUrl"
                        icon="heroicon-o-rectangle-group"
                        color="primary"
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
                    >
                        Типы мест
                    </x-filament::button>
                @endif
            </div>
        </x-filament::section>

        {{-- Люди --}}
        <x-filament::section>
            <x-slot name="heading">Люди</x-slot>

            <div class="grid gap-3 sm:grid-cols-2">
                @if (! empty($staffUrl))
                    <x-filament::button
                        tag="a"
                        :href="$staffUrl"
                        icon="heroicon-o-users"
                        color="gray"
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
                    >
                        Арендаторы
                    </x-filament::button>
                @endif
            </div>
        </x-filament::section>

        {{-- Доступ и роли --}}
        @if (! empty($permissionsUrl) || ! empty($rolesUrl))
            <x-filament::section>
                <x-slot name="heading">Доступ и роли</x-slot>

                <div class="grid gap-3 sm:grid-cols-2">
                    @if (! empty($permissionsUrl))
                        <x-filament::button
                            tag="a"
                            :href="$permissionsUrl"
                            icon="heroicon-o-key"
                            color="gray"
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
                        >
                            Роли
                        </x-filament::button>
                    @endif
                </div>
            </x-filament::section>
        @endif

        {{-- Интеграции --}}
        @if (! empty($integrationExchangesUrl))
            <x-filament::section>
                <x-slot name="heading">Интеграции</x-slot>

                <div class="grid gap-3 sm:grid-cols-2">
                    <x-filament::button
                        tag="a"
                        :href="$integrationExchangesUrl"
                        icon="heroicon-o-arrows-right-left"
                        color="gray"
                    >
                        Обмены интеграций
                    </x-filament::button>
                </div>
            </x-filament::section>
        @endif

    </div>
</x-filament-panels::page>
