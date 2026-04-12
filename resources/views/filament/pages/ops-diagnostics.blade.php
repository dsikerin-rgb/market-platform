<x-filament-panels::page>
    <style>
        .ops-page-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 2rem;
            align-items: start;

            /* чтобы контент не "лип" к краям окна */
            padding: 0 1rem;
            box-sizing: border-box;
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

        .ops-notes {
            overflow-wrap: anywhere;
            word-break: break-word;
            box-sizing: border-box;
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

        /* Inline “код” делаем НЕ <code>, чтобы Filament не навязывал nowrap */
        .ops-inline-code {
            display: inline;
            padding: .125rem .25rem;
            border-radius: .25rem;
            background: rgba(0,0,0,.06);
            font-size: .75rem;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;

            white-space: normal;
            overflow-wrap: anywhere;
            word-break: break-word;
            max-width: 100%;
        }

        @media (prefers-color-scheme: dark) {
            .ops-inline-code {
                background: rgba(255,255,255,.08);
            }
        }

        .ops-side-stack {
            display: grid;
            gap: 2rem;
        }

        /* КОД-БЛОКИ: теперь ПЕРЕНОСЯТСЯ, не вылезают за экран */
        .ops-codeblock {
            border-radius: .75rem;
            border: 1px solid rgba(0,0,0,.10);
            background: rgba(0,0,0,.03);
            padding: .75rem .875rem;

            max-width: 100%;
            box-sizing: border-box;
            overflow: hidden; /* без горизонтального "выползания" */
        }

        @media (prefers-color-scheme: dark) {
            .ops-codeblock {
                border-color: rgba(255,255,255,.14);
                background: rgba(255,255,255,.06);
            }
        }

        .ops-codeblock pre {
            margin: 0;
            font-size: .75rem;
            line-height: 1.45;

            /* ключевой фикс */
            white-space: pre-wrap;
            overflow-wrap: anywhere;
            word-break: break-word;
        }

        .ops-muted {
            opacity: .85;
        }
    </style>

    @php
        $telescopeInstalledLocal = isset($telescopeInstalled) ? (bool) $telescopeInstalled : false;
        $telescopeConfigEnabledLocal = $telescopeConfigEnabled ?? ($telescopeInstalledLocal ? (bool) config('telescope.enabled', true) : false);
        $telescopeRecordingEnabledLocal = $telescopeRecordingEnabled ?? ($telescopeEnabled ?? false);

        $telescopeEnabledUntilLocal = $telescopeEnabledUntil ?? null;
        $telescopeEnabledUntilHumanLocal = $telescopeEnabledUntilHuman ?? null;

        $pgBackupDefaultsLocal = $pgBackupDefaults ?? [
            'compressAfterDays' => 2,
            'deleteArchiveAfterDays' => 60,
        ];
        $pgBackupStatusLocal = $pgBackupStatus ?? [];
        $pgBackupFilesLocal = $pgBackupFiles ?? [];
        $pgBackupPreviewLocal = $pgBackupPreview ?? [
            'compress' => [],
            'deleteDuplicates' => [],
            'deleteArchives' => [],
        ];
    @endphp

    @if ($canViewIntegrationJournal && ! $canUseOpsTools)
        <x-filament::section
            heading="Журнал интеграций"
            description="Просмотр входящих и исходящих обменов с 1С и другими интеграциями."
        >
            <div style="display: grid; gap: 1rem;">
                <div class="ops-muted" style="font-size: .875rem; line-height: 1.5;">
                    Для <span class="ops-inline-code">market-operator</span> этот раздел является основным режимом страницы
                    диагностики. Открывается журнал обменов по вашему рынку без доступа к ops-инструментам.
                </div>

                <div style="display:flex; flex-wrap:wrap; gap:.75rem; align-items:center;">
                    <x-filament::badge color="success">
                        Доступ открыт
                    </x-filament::badge>

                    <x-filament::button
                        tag="a"
                        href="{{ $integrationExchangesUrl }}"
                        icon="heroicon-m-arrow-top-right-on-square"
                    >
                        Открыть журнал интеграций
                    </x-filament::button>
                </div>
            </div>
        </x-filament::section>
    @endif

    @if ($canUseOpsTools)
    <div class="ops-page-grid">
        {{-- Левая колонка: Состояние + Действия --}}
        <div class="ops-main" style="display: grid; gap: 2rem;">
            @if ($canViewIntegrationJournal)
                <x-filament::section
                    heading="Журнал интеграций"
                    description="Просмотр входящих и исходящих обменов с 1С и другими интеграциями."
                >
                    <div style="display: grid; gap: 1rem;">
                        <div class="ops-muted" style="font-size: .875rem; line-height: 1.5;">
                            Для <span class="ops-inline-code">market-operator</span> этот раздел является основным режимом страницы
                            диагностики. Открывается журнал обменов по вашему рынку без доступа к ops-инструментам.
                        </div>

                        <div style="display:flex; flex-wrap:wrap; gap:.75rem; align-items:center;">
                            <x-filament::badge color="success">
                                Доступ открыт
                            </x-filament::badge>

                            <x-filament::button
                                tag="a"
                                href="{{ $integrationExchangesUrl }}"
                                icon="heroicon-m-arrow-top-right-on-square"
                            >
                                Открыть журнал интеграций
                            </x-filament::button>
                        </div>
                    </div>
                </x-filament::section>
            @endif

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
                                        {{-- Маршруты/UI (config) --}}
                                        <x-filament::badge :color="$telescopeConfigEnabledLocal ? 'success' : 'warning'">
                                            {{ $telescopeConfigEnabledLocal ? 'UI доступен' : 'UI выключен (config)' }}
                                        </x-filament::badge>

                                        {{-- Запись (recording) --}}
                                        <x-filament::badge :color="$telescopeRecordingEnabledLocal ? 'success' : 'warning'">
                                            {{ $telescopeRecordingEnabledLocal ? 'Запись включена' : 'Запись выключена' }}
                                        </x-filament::badge>
                                    @endif
                                </div>

                                @if ($telescopeInstalled)
                                    <div class="ops-muted" style="font-size: .75rem; margin-top: .35rem;">
                                        @if ($telescopeRecordingEnabledLocal)
                                            Авто-выключение:
                                            <span class="ops-inline-code">{{ $telescopeEnabledUntilLocal ?? '—' }}</span>
                                            @if (! empty($telescopeEnabledUntilHumanLocal))
                                                ({{ $telescopeEnabledUntilHumanLocal }})
                                            @endif
                                        @else
                                            Запись по умолчанию выключена на non-local окружениях. Можно включить временно на 30 минут.
                                        @endif
                                    </div>
                                @endif
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

                        {{-- Версия / Деплой --}}
                        <div class="ops-kv-row" style="align-items: start;">
                            <div class="ops-kv-key">Путь (base_path)</div>
                            <div class="ops-kv-val">
                                <span class="ops-inline-code">{{ $appPath ?? '—' }}</span>
                            </div>
                        </div>

                        <div class="ops-kv-row">
                            <div class="ops-kv-key">Ветка</div>
                            <div class="ops-kv-val">
                                <x-filament::badge color="gray">
                                    {{ $gitBranch ?: '—' }}
                                </x-filament::badge>
                            </div>
                        </div>

                        <div class="ops-kv-row">
                            <div class="ops-kv-key">Коммит</div>
                            <div class="ops-kv-val">
                                <div style="display:flex; flex-wrap:wrap; gap:.5rem; align-items:center;">
                                    <x-filament::badge color="gray">
                                        {{ $gitCommitShort ?: '—' }}
                                    </x-filament::badge>

                                    @if (! empty($gitVersionLabel))
                                        <x-filament::badge color="gray">
                                            PR {{ $gitVersionLabel }}
                                        </x-filament::badge>
                                    @endif
                                </div>

                                <div class="ops-muted" style="font-size: .75rem; margin-top: .25rem;">
                                    “PR #…” берётся из сообщения последнего коммита (merge/squash), чтобы проще сравнивать, что новее.
                                </div>
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

                    {{-- Telescope controls (TTL) --}}
                    <x-filament::button
                        color="success"
                        icon="heroicon-m-play"
                        wire:click="enableTelescope30m"
                        :disabled="! $telescopeInstalled || $telescopeRecordingEnabledLocal"
                    >
                        Включить Telescope (30 мин)
                    </x-filament::button>

                    <x-filament::button
                        color="gray"
                        icon="heroicon-m-stop"
                        wire:click="disableTelescope"
                        :disabled="! $telescopeInstalled || ! $telescopeRecordingEnabledLocal"
                    >
                        Выключить Telescope
                    </x-filament::button>

                    @if ($telescopeInstalled && $telescopeConfigEnabledLocal)
                        <x-filament::button
                            color="gray"
                            icon="heroicon-m-arrow-top-right-on-square"
                            tag="a"
                            href="{{ url('/telescope') }}"
                            target="_blank"
                            rel="noopener"
                        >
                            Открыть Telescope
                        </x-filament::button>
                    @endif

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

            {{-- Бэкапы PostgreSQL --}}
            <x-filament::section
                heading="Бэкапы PostgreSQL"
                description="Управление дампами базы данных и ротация архивов."
            >
                {{-- Статистика --}}
                <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
                        <p class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">База данных</p>
                        <p class="mt-1 text-lg font-semibold text-gray-900 dark:text-white">{{ $pgBackupStatusLocal['dbName'] ?? '—' }}</p>
                        <p class="text-xs text-gray-400">{{ $pgBackupStatusLocal['dbHost'] ?? '—' }}:{{ $pgBackupStatusLocal['dbPort'] ?? '—' }}</p>
                    </div>

                    <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
                        <p class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Бэкапы</p>
                        <p class="mt-1 text-lg font-semibold text-gray-900 dark:text-white">{{ $pgBackupStatusLocal['totalBackups'] ?? 0 }}</p>
                        <p class="text-xs text-gray-400">Общий: {{ $pgBackupStatusLocal['totalSizeHuman'] ?? '0 Б' }}</p>
                    </div>

                    <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
                        <p class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Последний бэкап</p>
                        <p class="mt-1 text-lg font-semibold text-gray-900 dark:text-white">{{ $pgBackupStatusLocal['lastBackupTimeHuman'] ?? 'Нет' }}</p>
                        <p class="text-xs text-gray-400">{{ $pgBackupStatusLocal['lastBackupSizeHuman'] ?? '' }}</p>
                    </div>

                    <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
                        <p class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Диск</p>
                        <p class="mt-1 text-lg font-semibold text-gray-900 dark:text-white">{{ $pgBackupStatusLocal['diskFreeHuman'] ?? '—' }}</p>
                        <p class="text-xs text-gray-400">Всего: {{ $pgBackupStatusLocal['diskTotalHuman'] ?? '—' }}</p>
                    </div>
                </div>

                {{-- Действия --}}
                <div class="mb-6">
                    <x-filament::actions :actions="$this->getPgBackupActions()" :alignment="'start'" />
                </div>

                {{-- Список файлов --}}
                <div class="space-y-3">
                    <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300">Файлы бэкапов</h3>

                    @if (! empty($pgBackupFilesLocal))
                        <div class="divide-y divide-gray-200 overflow-hidden rounded-xl border border-gray-200 dark:divide-gray-700 dark:border-gray-700">
                            @foreach ($pgBackupFilesLocal as $file)
                                <div class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between hover:bg-gray-50 dark:hover:bg-gray-700/40">
                                    <div class="flex items-center gap-3">
                                        <div class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-lg bg-gray-100 dark:bg-gray-700">
                                            @if ($file['type'] === 'gz')
                                                <x-heroicon-o-archive-box class="h-5 w-5 text-gray-500 dark:text-gray-400" />
                                            @else
                                                <x-heroicon-o-document-text class="h-5 w-5 text-gray-500 dark:text-gray-400" />
                                            @endif
                                        </div>
                                        <div class="min-w-0">
                                            <p class="truncate text-sm font-medium text-gray-900 dark:text-white">{{ $file['name'] }}</p>
                                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $file['mtimeHuman'] }} • {{ $file['sizeHuman'] }}</p>
                                        </div>
                                    </div>

                                    <div class="flex items-center gap-2">
                                        <x-filament::badge :color="$file['type'] === 'gz' ? 'success' : 'gray'" size="sm">
                                            {{ $file['type'] === 'gz' ? 'GZIP' : 'SQL' }}
                                        </x-filament::badge>

                                        <x-filament::button
                                            tag="a"
                                            :href="route('filament.admin.ops-diagnostics.download', ['file' => $file['name']])"
                                            icon="heroicon-o-arrow-down-tray"
                                            size="sm"
                                            color="gray"
                                            labeled-from="sm"
                                        >
                                            Скачать
                                        </x-filament::button>

                                        <x-filament::button
                                            wire:click="deletePgBackup('{{ $file['name'] }}')"
                                            icon="heroicon-o-trash"
                                            size="sm"
                                            color="danger"
                                            outlined
                                            labeled-from="sm"
                                        >
                                            Удалить
                                        </x-filament::button>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div class="flex flex-col items-center justify-center rounded-xl border border-dashed border-gray-300 py-10 text-center dark:border-gray-700">
                            <x-heroicon-o-inbox class="mb-2 h-10 w-10 text-gray-400" />
                            <p class="text-sm text-gray-500 dark:text-gray-400">Бэкапы ещё не создавались</p>
                        </div>
                    @endif
                </div>

                {{-- Предпросмотр ротации (скрыт по умолчанию) --}}
                <div x-data="{ open: false }" class="mt-6">
                    <button
                        @click="open = ! open"
                        class="flex w-full items-center justify-between rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 text-left text-sm font-medium text-gray-700 hover:bg-gray-100 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700/50"
                    >
                        <span>Предпросмотр ротации</span>
                        <x-heroicon-o-chevron-down class="h-5 w-5 transition-transform" x-bind:class="open ? 'rotate-180' : ''" />
                    </button>

                    <div x-show="open" x-collapse class="mt-3 space-y-4 rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-800/50">
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            Сжатие старше {{ $pgBackupDefaultsLocal['compressAfterDays'] }} дн., удаление архивов старше {{ $pgBackupDefaultsLocal['deleteArchiveAfterDays'] }} дн.
                        </p>

                        <div class="grid gap-4 sm:grid-cols-3">
                            <div>
                                <p class="text-xs font-semibold text-gray-600 dark:text-gray-300">Сжать (*.sql → *.gz)</p>
                                @if (! empty($pgBackupPreviewLocal['compress']))
                                    <ul class="mt-1 space-y-1">
                                        @foreach ($pgBackupPreviewLocal['compress'] as $f)
                                            <li class="text-xs text-gray-500 dark:text-gray-400">• {{ $f }}</li>
                                        @endforeach
                                    </ul>
                                @else
                                    <p class="mt-1 text-xs text-gray-400">Нет</p>
                                @endif
                            </div>
                            <div>
                                <p class="text-xs font-semibold text-gray-600 dark:text-gray-300">Удалить дубли</p>
                                @if (! empty($pgBackupPreviewLocal['deleteDuplicates']))
                                    <ul class="mt-1 space-y-1">
                                        @foreach ($pgBackupPreviewLocal['deleteDuplicates'] as $f)
                                            <li class="text-xs text-gray-500 dark:text-gray-400">• {{ $f }}</li>
                                        @endforeach
                                    </ul>
                                @else
                                    <p class="mt-1 text-xs text-gray-400">Нет</p>
                                @endif
                            </div>
                            <div>
                                <p class="text-xs font-semibold text-gray-600 dark:text-gray-300">Удалить архивы</p>
                                @if (! empty($pgBackupPreviewLocal['deleteArchives']))
                                    <ul class="mt-1 space-y-1">
                                        @foreach ($pgBackupPreviewLocal['deleteArchives'] as $f)
                                            <li class="text-xs text-gray-500 dark:text-gray-400">• {{ $f }}</li>
                                        @endforeach
                                    </ul>
                                @else
                                    <p class="mt-1 text-xs text-gray-400">Нет</p>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            </x-filament::section>
        </div>

        {{-- Правая колонка: Примечания + Команды --}}
        <div class="ops-notes">
            <div class="ops-side-stack">
                <x-filament::section heading="Примечания">
                    <div style="display:grid; gap:.75rem; font-size:.875rem; line-height:1.5;">
                        <p>
                            <span style="font-weight:600;">Очистить кэши</span> выполняет
                            <span class="ops-inline-code">php artisan optimize:clear</span>.
                        </p>

                        <p>
                            <span style="font-weight:600;">Включить Telescope</span> включает <span style="font-weight:600;">запись</span>
                            на 30 минут и автоматически выключает её по TTL.
                            Доступ к UI ограничен ролью <span class="ops-inline-code">super-admin</span>.
                        </p>

                        <p>
                            <span style="font-weight:600;">Очистить Telescope</span> удаляет записи старше 48 часов
                            (если Telescope установлен и таблицы доступны).
                        </p>
                    </div>
                </x-filament::section>

                <x-filament::section
                    heading="Полезные команды"
                    description="Шпаргалка для сервера. Выполнять в терминале, не в браузере."
                >
                    <div style="display:grid; gap: 1rem;">
                        <div>
                            <div class="ops-muted" style="font-size:.875rem; font-weight:600; margin-bottom:.5rem;">
                                Локации проекта
                            </div>
                            <div class="ops-codeblock">
                                <pre><code># staging
cd /var/www/market-staging/current

# prod
cd /var/www/market/current</code></pre>
                            </div>
                        </div>

                        <div>
                            <div class="ops-muted" style="font-size:.875rem; font-weight:600; margin-bottom:.5rem;">
                                Проверить версию (коммит) на окружении
                            </div>
                            <div class="ops-codeblock">
                                <pre><code>sudo -u www-data git -C /var/www/market-staging/current log -1 --oneline
sudo -u www-data git -C /var/www/market/current log -1 --oneline</code></pre>
                            </div>
                        </div>

                        <div>
                            <div class="ops-muted" style="font-size:.875rem; font-weight:600; margin-bottom:.5rem;">
                                Обновить окружение до последнего main (без merge)
                            </div>
                            <div class="ops-codeblock">
                                <pre><code># staging
sudo -u www-data git -C /var/www/market-staging/current fetch origin
sudo -u www-data git -C /var/www/market-staging/current pull --ff-only origin main

# prod (делать только при контролируемом релизе)
sudo -u www-data git -C /var/www/market/current fetch origin
sudo -u www-data git -C /var/www/market/current pull --ff-only origin main</code></pre>
                            </div>
                        </div>

                        <div>
                            <div class="ops-muted" style="font-size:.875rem; font-weight:600; margin-bottom:.5rem;">
                                Логи/блокировки деплоя staging
                            </div>
                            <div class="ops-codeblock">
                                <pre><code># лог вебхука
tail -n 200 /var/www/market-staging/current/storage/logs/deploy-market-staging.log

# lock (если деплой "завис", сначала проверь лог)
ls -la /var/www/market-staging/current/storage/framework/deploy-market-staging.lock</code></pre>
                            </div>
                        </div>

                        <div>
                            <div class="ops-muted" style="font-size:.875rem; font-weight:600; margin-bottom:.5rem;">
                                Очистка кешей Laravel (ручной вариант)
                            </div>
                            <div class="ops-codeblock">
                                <pre><code>cd /var/www/market-staging/current
php artisan optimize:clear</code></pre>
                            </div>
                        </div>
                    </div>
                </x-filament::section>
            </div>
        </div>
    </div>
    @endif
</x-filament-panels::page>
