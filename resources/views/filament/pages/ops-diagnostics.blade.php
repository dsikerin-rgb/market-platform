<x-filament-panels::page>
    <style>
        .ops-page-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 2rem;
            align-items: start;
        }

        @media (min-width: 1024px) {
            .ops-page-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
            .ops-main {
                grid-column: span 2 / span 2;
            }
            .ops-notes {
                grid-column: span 1 / span 1;
            }
        }

        .ops-kv-wrap {
            overflow-x: auto;
            border-radius: 0.75rem;
            border: 1px solid rgba(0, 0, 0, 0.10);
        }

        @media (prefers-color-scheme: dark) {
            .ops-kv-wrap {
                border-color: rgba(255, 255, 255, 0.14);
            }
        }

        .ops-kv {
            min-width: 520px; /* чтобы 2 колонки не схлопывались на узком контейнере */
            font-size: 0.875rem;
        }

        .ops-kv-row {
            display: grid;
            grid-template-columns: 14rem minmax(0, 1fr);
            column-gap: 1.5rem;
            row-gap: .5rem;
            padding: 0.75rem 1rem;
            border-top: 1px solid rgba(0, 0, 0, 0.08);
        }

        @media (prefers-color-scheme: dark) {
            .ops-kv-row {
                border-top-color: rgba(255, 255, 255, 0.12);
            }
        }

        .ops-kv-row:first-child {
            border-top: 0;
        }

        .ops-kv-head {
            font-weight: 600;
            background: rgba(0, 0, 0, 0.04);
        }

        @media (prefers-color-scheme: dark) {
            .ops-kv-head {
                background: rgba(255, 255, 255, 0.06);
            }
        }

        .ops-kv-key {
            white-space: nowrap;
            opacity: 0.85;
        }

        .ops-kv-val {
            min-width: 0;
        }
    </style>

    <div class="ops-page-grid">
        {{-- Левая колонка: Состояние + Действия --}}
        <div class="ops-main" style="display: grid; gap: 2rem;">
            {{-- Состояние системы --}}
            <x-filament::section
                heading="Диагностика системы"
                description="Окружение, статус Telescope и быстрые операции обслуживания."
            >
                <div class="ops-kv-wrap">
                    <div class="ops-kv">
                        <div class="ops-kv-row ops-kv-head">
                            <div>Параметр</div>
                            <div>Значение</div>
                        </div>

                        <div class="ops-kv-row">
                            <div class="ops-kv-key">Окружение</div>
                            <div class="ops-kv-val">
                                <div style="display:flex; align-items:center; gap: 1rem;">
                                    <x-filament::badge color="success">
                                        {{ $appEnv }}
                                    </x-filament::badge>
                                </div>
                            </div>
                        </div>

                        <div class="ops-kv-row" style="align-items: start;">
                            <div class="ops-kv-key">Telescope</div>
                            <div class="ops-kv-val">
                                <div style="display:flex; flex-wrap:wrap; gap: .75rem; align-items:center;">
                                    <x-filament::badge :color="$telescopeInstalled ? 'success' : 'gray'">
                                        {{ $telescopeInstalled ? 'Установлен' : 'Не установлен' }}
                                    </x-filament::badge>

                                    @if ($telescopeInstalled)
                                        <x-filament::badge :color="$telescopeEnabled ? 'success' : 'warning'">
                                            {{ $telescopeEnabled ? 'Включён' : 'Выключен' }}
                                        </x-filament::badge>
                                    @endif
                                </div>
                            </div>
                        </div>

                        <div class="ops-kv-row">
                            <div class="ops-kv-key">Доступ</div>
                            <div class="ops-kv-val">
                                <x-filament::badge color="warning">
                                    Только super-admin
                                </x-filament::badge>
                            </div>
                        </div>
                    </div>
                </div>
            </x-filament::section>

            {{-- Действия --}}
            <x-filament::section
                heading="Действия"
                description="Команды обслуживания выполняются на сервере. Доступны только роли super-admin."
            >
                <div style="display:flex; flex-wrap:wrap; align-items:center; gap:.75rem;">
                    <x-filament::button
                        icon="heroicon-m-arrow-path"
                        wire:click="clearCaches"
                    >
                        Очистить кэши
                    </x-filament::button>

                    <x-filament::button
                        color="warning"
                        icon="heroicon-m-trash"
                        wire:click="pruneTelescope"
                        :disabled="! $telescopeInstalled"
                    >
                        Очистить Telescope (48ч)
                    </x-filament::button>
                </div>
            </x-filament::section>
        </div>

        {{-- Правая колонка: Примечания --}}
        <div class="ops-notes">
            <x-filament::section heading="Примечания">
                <div style="display:grid; gap:.75rem; font-size:.875rem; line-height:1.5;">
                    <p>
                        <span style="font-weight:600;">Очистить кэши</span> выполняет
                        <code style="padding:.125rem .25rem; border-radius:.25rem; background: rgba(0,0,0,.06); font-size:.75rem;">
                            php artisan optimize:clear
                        </code>.
                    </p>

                    <p>
                        <span style="font-weight:600;">Очистить Telescope</span> удаляет записи старше 48 часов
                        (если Telescope установлен).
                    </p>
                </div>
            </x-filament::section>
        </div>
    </div>
</x-filament-panels::page>
