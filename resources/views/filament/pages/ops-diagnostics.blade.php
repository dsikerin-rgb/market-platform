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
        // Backward compatibility:
        // - ранее существовал только $telescopeEnabled (по config).
        // - теперь (в новой версии OpsDiagnostics) есть $telescopeRecordingEnabled и $telescopeEnabledUntil/*.
        $telescopeConfigEnabledLocal = $telescopeConfigEnabled ?? ($telescopeInstalled ? (bool) config('telescope.enabled', true) : false);
        $telescopeRecordingEnabledLocal = $telescopeRecordingEnabled ?? ($telescopeEnabled ?? false);

        $telescopeEnabledUntilLocal = $telescopeEnabledUntil ?? null;
        $telescopeEnabledUntilHumanLocal = $telescopeEnabledUntilHuman ?? null;

        $sqliteBackupDefaultsLocal = $sqliteBackupDefaults ?? [
            'compressAfterDays' => 2,
            'deleteArchiveAfterDays' => 60,
        ];
        $sqliteBackupStatusLocal = $sqliteBackupStatus ?? [];
        $sqliteBackupFilesLocal = $sqliteBackupFiles ?? [];
        $sqliteBackupPreviewLocal = $sqliteBackupPreview ?? [
            'compress' => [],
            'deleteDuplicates' => [],
            'deleteArchives' => [],
        ];
    @endphp

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

            {{-- Бэкапы SQLite --}}
            <x-filament::section
                heading="Бэкапы SQLite"
                description="Файловые бэкапы для текущего окружения и ротация без обращения к БД."
            >
                <div style="display:grid; gap: 1.5rem;">
                    <div class="ops-kv-wrap">
                        <div class="ops-kv">
                            <div class="ops-kv-row ops-kv-head">
                                <div>Параметр</div>
                                <div>Значение</div>
                            </div>

                            <div class="ops-kv-row">
                                <div class="ops-kv-key">SQLite файл</div>
                                <div class="ops-kv-val">
                                    <div class="ops-inline-code">{{ $sqliteBackupStatusLocal['dbPath'] ?? '—' }}</div>
                                </div>
                            </div>

                            <div class="ops-kv-row">
                                <div class="ops-kv-key">Размер</div>
                                <div class="ops-kv-val">
                                    {{ $sqliteBackupStatusLocal['dbSizeHuman'] ?? '—' }}
                                </div>
                            </div>

                            <div class="ops-kv-row">
                                <div class="ops-kv-key">Изменён</div>
                                <div class="ops-kv-val">
                                    <span class="ops-inline-code">{{ $sqliteBackupStatusLocal['dbMtimeHuman'] ?? '—' }}</span>
                                </div>
                            </div>

                            <div class="ops-kv-row">
                                <div class="ops-kv-key">Каталог бэкапов</div>
                                <div class="ops-kv-val">
                                    <div class="ops-inline-code">{{ $sqliteBackupStatusLocal['backupDir'] ?? '—' }}</div>
                                </div>
                            </div>

                            <div class="ops-kv-row">
                                <div class="ops-kv-key">Свободно / Всего</div>
                                <div class="ops-kv-val">
                                    {{ $sqliteBackupStatusLocal['diskFreeHuman'] ?? '—' }}
                                    /
                                    {{ $sqliteBackupStatusLocal['diskTotalHuman'] ?? '—' }}
                                </div>
                            </div>
                        </div>
                    </div>

                    <div>
                        <x-filament::actions
                            :actions="$this->getSqliteBackupActions()"
                            :alignment="'start'"
                        />
                    </div>

                    <div style="display:grid; gap:.75rem;">
                        <div class="ops-muted" style="font-size:.875rem;">
                            Предпросмотр ротации (сжатие старше {{ $sqliteBackupDefaultsLocal['compressAfterDays'] }} дн.,
                            удаление архивов старше {{ $sqliteBackupDefaultsLocal['deleteArchiveAfterDays'] }} дн.).
                        </div>

                        <div style="display:grid; gap:.75rem;">
                            <div>
                                <div class="ops-muted" style="font-weight:600;">Сжать (*.sqlite → *.sqlite.gz)</div>
                                @if (! empty($sqliteBackupPreviewLocal['compress']))
                                    <ul class="list-disc" style="padding-left: 1.25rem;">
                                        @foreach ($sqliteBackupPreviewLocal['compress'] as $file)
                                            <li class="ops-inline-code">{{ $file }}</li>
                                        @endforeach
                                    </ul>
                                @else
                                    <div class="ops-muted">Нет файлов для сжатия.</div>
                                @endif
                            </div>

                            <div>
                                <div class="ops-muted" style="font-weight:600;">Удалить дубли (*.sqlite при наличии *.gz)</div>
                                @if (! empty($sqliteBackupPreviewLocal['deleteDuplicates']))
                                    <ul class="list-disc" style="padding-left: 1.25rem;">
                                        @foreach ($sqliteBackupPreviewLocal['deleteDuplicates'] as $file)
                                            <li class="ops-inline-code">{{ $file }}</li>
                                        @endforeach
                                    </ul>
                                @else
                                    <div class="ops-muted">Нет дублей для удаления.</div>
                                @endif
                            </div>

                            <div>
                                <div class="ops-muted" style="font-weight:600;">Удалить архивы (*.sqlite.gz)</div>
                                @if (! empty($sqliteBackupPreviewLocal['deleteArchives']))
                                    <ul class="list-disc" style="padding-left: 1.25rem;">
                                        @foreach ($sqliteBackupPreviewLocal['deleteArchives'] as $file)
                                            <li class="ops-inline-code">{{ $file }}</li>
                                        @endforeach
                                    </ul>
                                @else
                                    <div class="ops-muted">Нет архивов для удаления.</div>
                                @endif
                            </div>
                        </div>
                    </div>

                    <div>
                        <div class="ops-muted" style="font-size:.875rem; font-weight:600; margin-bottom:.5rem;">
                            Файлы в database/backups
                        </div>
                        <div class="ops-kv-wrap">
                            <div class="ops-kv" style="min-width: 640px;">
                                <div class="ops-kv-row ops-kv-head">
                                    <div>Файл</div>
                                    <div>Размер / Дата / Тип</div>
                                </div>

                                @forelse ($sqliteBackupFilesLocal as $file)
                                    <div class="ops-kv-row">
                                        <div class="ops-kv-key">
                                            <span class="ops-inline-code">{{ $file['name'] }}</span>
                                        </div>
                                        <div class="ops-kv-val">
                                            {{ $file['sizeHuman'] }}
                                            ·
                                            <span class="ops-inline-code">{{ $file['mtimeHuman'] }}</span>
                                            ·
                                            <x-filament::badge color="gray">
                                                {{ $file['type'] === 'gz' ? 'gz' : 'sqlite' }}
                                            </x-filament::badge>
                                        </div>
                                    </div>
                                @empty
                                    <div class="ops-kv-row">
                                        <div class="ops-kv-key">—</div>
                                        <div class="ops-kv-val">Бэкапы ещё не создавались.</div>
                                    </div>
                                @endforelse
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
</x-filament-panels::page>
