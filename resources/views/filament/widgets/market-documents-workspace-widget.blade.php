<x-filament::section>
    <style>
        .mdw-explorer {
            display: grid;
            grid-template-columns: minmax(240px, 300px) minmax(0, 1fr);
            min-height: 620px;
            overflow: hidden;
            border: 1px solid rgba(148, 163, 184, 0.28);
            border-radius: 18px;
            background: #ffffff;
            box-shadow: 0 18px 45px rgba(15, 23, 42, 0.06);
        }

        .mdw-sidebar {
            display: flex;
            flex-direction: column;
            gap: 16px;
            padding: 18px;
            border-right: 1px solid rgba(226, 232, 240, 0.9);
            background: #f8fafc;
        }

        .mdw-sidebar__title {
            margin: 0;
            color: #0f172a;
            font-size: 18px;
            font-weight: 850;
            line-height: 1.15;
        }

        .mdw-sidebar__hint {
            margin: 4px 0 0;
            color: #64748b;
            font-size: 13px;
            line-height: 1.35;
        }

        .mdw-tree {
            display: grid;
            gap: 6px;
        }

        .mdw-tree__section {
            margin: 8px 0 2px;
            color: #64748b;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }

        .mdw-node {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 0;
            min-height: 42px;
            padding: 9px 10px;
            border: 1px solid transparent;
            border-radius: 12px;
            color: #334155;
            text-decoration: none;
        }

        .mdw-node:hover {
            border-color: rgba(14, 165, 233, 0.24);
            background: #ffffff;
        }

        .mdw-node.is-active {
            border-color: rgba(14, 165, 233, 0.5);
            color: #075985;
            background: #e0f2fe;
        }

        .mdw-node__icon {
            display: grid;
            place-items: center;
            width: 30px;
            height: 30px;
            flex: 0 0 auto;
            border-radius: 9px;
            color: #0284c7;
            background: rgba(224, 242, 254, 0.8);
        }

        .mdw-node.is-active .mdw-node__icon {
            background: #ffffff;
        }

        .mdw-node__body {
            min-width: 0;
            flex: 1 1 auto;
        }

        .mdw-node__label {
            margin: 0;
            overflow: hidden;
            color: inherit;
            font-size: 14px;
            font-weight: 800;
            line-height: 1.15;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .mdw-node__meta {
            margin: 3px 0 0;
            color: #64748b;
            font-size: 12px;
            line-height: 1.1;
        }

        .mdw-folder-list {
            display: grid;
            gap: 4px;
            padding-left: 12px;
        }

        .mdw-content {
            display: flex;
            min-width: 0;
            flex-direction: column;
        }

        .mdw-content__head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            min-height: 84px;
            padding: 18px 22px;
            border-bottom: 1px solid rgba(226, 232, 240, 0.9);
            background: #ffffff;
        }

        .mdw-content__title {
            margin: 0;
            color: #0f172a;
            font-size: 24px;
            font-weight: 850;
            line-height: 1.15;
        }

        .mdw-content__subtitle {
            margin: 5px 0 0;
            color: #64748b;
            font-size: 14px;
            line-height: 1.3;
        }

        .mdw-content__meta {
            display: flex;
            flex-wrap: wrap;
            justify-content: flex-end;
            gap: 8px;
        }

        .mdw-content__actions {
            width: 100%;
            margin-top: 10px;
            display: flex;
            justify-content: flex-end;
            gap: 8px;
        }

        .mdw-pill {
            display: inline-flex;
            align-items: center;
            min-height: 28px;
            padding: 5px 10px;
            border-radius: 999px;
            color: #334155;
            background: #f1f5f9;
            font-size: 12px;
            font-weight: 800;
            line-height: 1;
        }

        .mdw-table {
            min-width: 0;
            padding: 0;
            background: #ffffff;
        }

        .mdw-empty-folders {
            margin: 8px 0 0;
            padding: 10px;
            border-radius: 12px;
            color: #64748b;
            background: #ffffff;
            font-size: 13px;
            line-height: 1.35;
        }

        @media (max-width: 900px) {
            .mdw-explorer {
                grid-template-columns: 1fr;
            }

            .mdw-sidebar {
                border-right: 0;
                border-bottom: 1px solid rgba(226, 232, 240, 0.9);
            }

            .mdw-content__head {
                align-items: flex-start;
                flex-direction: column;
            }

            .mdw-content__meta {
                justify-content: flex-start;
            }

            .mdw-content__actions {
                justify-content: flex-start;
                margin-top: 0;
            }
        }
    </style>

    @php
        $headerActions = $this->getDocumentWorkspaceHeaderActions();
        $headerActionsAlignment = $this->getHeaderActionsAlignment();
        $activeSection = collect($sections)->firstWhere('isActive', true) ?? $sections[0];
    @endphp

    <div class="mdw-explorer">
        <aside class="mdw-sidebar" aria-label="Папки документов">
            <div>
                <p class="mdw-sidebar__title">Документы</p>
                <p class="mdw-sidebar__hint">Выберите диск или папку слева. Содержимое откроется справа.</p>
            </div>

            <nav class="mdw-tree">
                <p class="mdw-tree__section">Личный</p>

                @php($personalSection = collect($sections)->firstWhere('key', 'personal'))
                @if ($personalSection)
                    <a href="{{ $personalSection['url'] }}" class="mdw-node {{ $personalSection['isActive'] ? 'is-active' : '' }}">
                        <span class="mdw-node__icon">
                            <x-filament::icon icon="heroicon-o-home" class="h-5 w-5" />
                        </span>
                        <span class="mdw-node__body">
                            <span class="mdw-node__label">Мой диск</span>
                            <span class="mdw-node__meta">{{ $personalSection['documents'] }} файлов · {{ $personalSection['folders'] }} папок</span>
                        </span>
                    </a>
                @endif

                @if (($folderGroups['personal'] ?? []) !== [])
                    <div class="mdw-folder-list">
                        @foreach ($folderGroups['personal'] as $folder)
                            <div class="mdw-node">
                                <span class="mdw-node__icon">
                                    <x-filament::icon icon="heroicon-o-folder" class="h-5 w-5" />
                                </span>
                                <span class="mdw-node__body">
                                    <span class="mdw-node__label">{{ $folder['name'] }}</span>
                                    <span class="mdw-node__meta">{{ $folder['documents'] }} файлов</span>
                                </span>
                            </div>
                        @endforeach
                    </div>
                @endif

                <p class="mdw-tree__section">Общий</p>

                @php($sharedSection = collect($sections)->firstWhere('key', 'shared'))
                @if ($sharedSection)
                    <a href="{{ $sharedSection['url'] }}" class="mdw-node {{ $sharedSection['isActive'] ? 'is-active' : '' }}">
                        <span class="mdw-node__icon">
                            <x-filament::icon icon="heroicon-o-users" class="h-5 w-5" />
                        </span>
                        <span class="mdw-node__body">
                            <span class="mdw-node__label">Общее</span>
                            <span class="mdw-node__meta">{{ $sharedSection['documents'] }} файлов · {{ $sharedSection['folders'] }} папок</span>
                        </span>
                    </a>
                @endif

                @if (($folderGroups['shared'] ?? []) !== [])
                    <div class="mdw-folder-list">
                        @foreach ($folderGroups['shared'] as $folder)
                            <div class="mdw-node">
                                <span class="mdw-node__icon">
                                    <x-filament::icon icon="heroicon-o-folder" class="h-5 w-5" />
                                </span>
                                <span class="mdw-node__body">
                                    <span class="mdw-node__label">{{ $folder['name'] }}</span>
                                    <span class="mdw-node__meta">{{ $folder['documents'] }} файлов</span>
                                </span>
                            </div>
                        @endforeach
                    </div>
                @endif

                @if (($folderGroups['personal'] ?? []) === [] && ($folderGroups['shared'] ?? []) === [])
                    <p class="mdw-empty-folders">Папок пока нет. Нажмите «Создать папку», чтобы собрать документы по темам.</p>
                @endif

                <p class="mdw-tree__section">Быстрый доступ</p>

                @php($allSection = collect($sections)->firstWhere('key', 'all'))
                @if ($allSection)
                    <a href="{{ $allSection['url'] }}" class="mdw-node {{ $allSection['isActive'] ? 'is-active' : '' }}">
                        <span class="mdw-node__icon">
                            <x-filament::icon icon="heroicon-o-folder-open" class="h-5 w-5" />
                        </span>
                        <span class="mdw-node__body">
                            <span class="mdw-node__label">Все документы</span>
                            <span class="mdw-node__meta">{{ $allSection['documents'] }} файлов · {{ $allSection['folders'] }} папок</span>
                        </span>
                    </a>
                @endif
            </nav>
        </aside>

        <section class="mdw-content">
            <header class="mdw-content__head">
                <div>
                    <h2 class="mdw-content__title">{{ $activeSection['label'] }}</h2>
                </div>

                @if (filled($headerActions))
                    <div class="mdw-content__actions">
                        <x-filament::actions
                            :actions="$headerActions"
                            :alignment="$headerActionsAlignment"
                        />
                    </div>
                @endif
            </header>

            <div class="mdw-table">
                {{ $slot }}
            </div>
        </section>
    </div>
</x-filament::section>
