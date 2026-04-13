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
                {{-- Статистика: 4 карточки в ряд --}}
                <div style="display:grid; grid-template-columns:repeat(4, minmax(0, 1fr)); gap:1rem; margin-bottom:1.5rem;">
                    <div style="border:1px solid rgba(0,0,0,.10); border-radius:.75rem; background:rgba(255,255,255,.95); padding:1rem;">
                        <p style="font-size:.7rem; font-weight:600; text-transform:uppercase; letter-spacing:.08em; color:#6b7280;">База данных</p>
                        <p style="margin-top:.25rem; font-size:1.125rem; font-weight:700; color:#0f172a;">{{ $pgBackupStatusLocal['dbName'] ?? '—' }}</p>
                        <p style="font-size:.75rem; color:#9ca3af;">{{ $pgBackupStatusLocal['dbHost'] ?? '—' }}:{{ $pgBackupStatusLocal['dbPort'] ?? '—' }}</p>
                    </div>

                    <div style="border:1px solid rgba(0,0,0,.10); border-radius:.75rem; background:rgba(255,255,255,.95); padding:1rem;">
                        <p style="font-size:.7rem; font-weight:600; text-transform:uppercase; letter-spacing:.08em; color:#6b7280;">Бэкапы</p>
                        <p style="margin-top:.25rem; font-size:1.125rem; font-weight:700; color:#0f172a;">{{ $pgBackupStatusLocal['totalBackups'] ?? 0 }}</p>
                        <p style="font-size:.75rem; color:#9ca3af;">Общий: {{ $pgBackupStatusLocal['totalSizeHuman'] ?? '0 Б' }}</p>
                    </div>

                    <div style="border:1px solid rgba(0,0,0,.10); border-radius:.75rem; background:rgba(255,255,255,.95); padding:1rem;">
                        <p style="font-size:.7rem; font-weight:600; text-transform:uppercase; letter-spacing:.08em; color:#6b7280;">Последний бэкап</p>
                        <p style="margin-top:.25rem; font-size:1.125rem; font-weight:700; color:#0f172a;">{{ $pgBackupStatusLocal['lastBackupTimeHuman'] ?? 'Нет' }}</p>
                        <p style="font-size:.75rem; color:#9ca3af;">{{ $pgBackupStatusLocal['lastBackupSizeHuman'] ?? '' }}</p>
                    </div>

                    <div style="border:1px solid rgba(0,0,0,.10); border-radius:.75rem; background:rgba(255,255,255,.95); padding:1rem;">
                        <p style="font-size:.7rem; font-weight:600; text-transform:uppercase; letter-spacing:.08em; color:#6b7280;">Диск</p>
                        <p style="margin-top:.25rem; font-size:1.125rem; font-weight:700; color:#0f172a;">{{ $pgBackupStatusLocal['diskFreeHuman'] ?? '—' }}</p>
                        <p style="font-size:.75rem; color:#9ca3af;">Всего: {{ $pgBackupStatusLocal['diskTotalHuman'] ?? '—' }}</p>
                    </div>
                </div>

                {{-- Действия --}}
                <div style="margin-bottom:1.5rem;">
                    <x-filament::actions :actions="$this->getPgBackupActions()" :alignment="'start'" />
                </div>

                {{-- Список файлов --}}
                <div style="display:grid; gap:.75rem;">
                    <p style="font-size:.875rem; font-weight:600; color:#374151;">Файлы бэкапов</p>

                    @if (! empty($pgBackupFilesLocal))
                        <div style="border:1px solid rgba(0,0,0,.10); border-radius:.75rem; overflow:hidden;">
                            @foreach ($pgBackupFilesLocal as $idx => $file)
                                <div style="display:flex; flex-wrap:wrap; align-items:center; gap:.75rem; padding:.75rem 1rem; border-bottom:1px solid rgba(0,0,0,.06);{{ $idx === count($pgBackupFilesLocal) - 1 ? ' border-bottom:0;' : '' }}">
                                    <div style="display:flex; align-items:center; gap:.75rem; flex:1; min-width:0;">
                                        <div style="display:flex; align-items:center; justify-content:center; width:2.25rem; height:2.25rem; border-radius:.5rem; background:rgba(0,0,0,.06); flex-shrink:0;">
                                            @if ($file['type'] === 'gz')
                                                <x-heroicon-o-archive-box style="width:1.125rem; height:1.125rem; color:#6b7280;" />
                                            @else
                                                <x-heroicon-o-document-text style="width:1.125rem; height:1.125rem; color:#6b7280;" />
                                            @endif
                                        </div>
                                        <div style="min-width:0;">
                                            <p style="font-size:.8125rem; font-weight:500; color:#0f172a; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">{{ $file['name'] }}</p>
                                            <p style="font-size:.6875rem; color:#6b7280;">{{ $file['mtimeHuman'] }} · {{ $file['sizeHuman'] }}</p>
                                        </div>
                                    </div>

                                    <div style="display:flex; align-items:center; gap:.5rem; flex-shrink:0;">
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
                        <div style="display:flex; flex-direction:column; align-items:center; justify-content:center; border:1px dashed rgba(0,0,0,.15); border-radius:.75rem; padding:2.5rem 1.5rem; text-align:center;">
                            <svg style="width:2.5rem; height:2.5rem; color:#9ca3af; margin-bottom:.5rem;" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z" />
                            </svg>
                            <p style="font-size:.8125rem; color:#6b7280;">Бэкапы ещё не создавались</p>
                        </div>
                    @endif
                </div>

                {{-- Предпросмотр ротации (скрыт по умолчанию) --}}
                <div x-data="{ open: false }" style="margin-top:1.5rem;">
                    <button
                        @click="open = ! open"
                        style="display:flex; align-items:center; justify-content:space-between; width:100%; border-radius:.5rem; border:1px solid rgba(0,0,0,.10); background:rgba(0,0,0,.03); padding:.75rem 1rem; text-align:left; font-size:.8125rem; font-weight:500; color:#374151; cursor:pointer;"
                    >
                        <span>Предпросмотр ротации</span>
                        <svg style="width:1.25rem; height:1.25rem; transition:transform .2s;" x-bind:style="open ? 'transform:rotate(180deg)' : ''" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                        </svg>
                    </button>

                    <div x-show="open" x-collapse style="margin-top:.75rem; border:1px solid rgba(0,0,0,.10); border-radius:.75rem; background:rgba(0,0,0,.03); padding:1rem;">
                        <p style="font-size:.6875rem; color:#6b7280; margin-bottom:.75rem;">
                            Сжатие старше {{ $pgBackupDefaultsLocal['compressAfterDays'] }} дн., удаление архивов старше {{ $pgBackupDefaultsLocal['deleteArchiveAfterDays'] }} дн.
                        </p>

                        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:1rem;">
                            <div>
                                <p style="font-size:.6875rem; font-weight:600; color:#374151;">Сжать (*.sql → *.gz)</p>
                                @if (! empty($pgBackupPreviewLocal['compress']))
                                    <ul style="margin-top:.25rem; padding-left:1rem;">
                                        @foreach ($pgBackupPreviewLocal['compress'] as $f)
                                            <li style="font-size:.6875rem; color:#6b7280;">• {{ $f }}</li>
                                        @endforeach
                                    </ul>
                                @else
                                    <p style="margin-top:.25rem; font-size:.6875rem; color:#9ca3af;">Нет</p>
                                @endif
                            </div>
                            <div>
                                <p style="font-size:.6875rem; font-weight:600; color:#374151;">Удалить дубли</p>
                                @if (! empty($pgBackupPreviewLocal['deleteDuplicates']))
                                    <ul style="margin-top:.25rem; padding-left:1rem;">
                                        @foreach ($pgBackupPreviewLocal['deleteDuplicates'] as $f)
                                            <li style="font-size:.6875rem; color:#6b7280;">• {{ $f }}</li>
                                        @endforeach
                                    </ul>
                                @else
                                    <p style="margin-top:.25rem; font-size:.6875rem; color:#9ca3af;">Нет</p>
                                @endif
                            </div>
                            <div>
                                <p style="font-size:.6875rem; font-weight:600; color:#374151;">Удалить архивы</p>
                                @if (! empty($pgBackupPreviewLocal['deleteArchives']))
                                    <ul style="margin-top:.25rem; padding-left:1rem;">
                                        @foreach ($pgBackupPreviewLocal['deleteArchives'] as $f)
                                            <li style="font-size:.6875rem; color:#6b7280;">• {{ $f }}</li>
                                        @endforeach
                                    </ul>
                                @else
                                    <p style="margin-top:.25rem; font-size:.6875rem; color:#9ca3af;">Нет</p>
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
