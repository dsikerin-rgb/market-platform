<x-filament::section>
    <style>
        [x-cloak] {
            display: none !important;
        }

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

        .mdw-tree {
            display: grid;
            gap: 6px;
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

        .mdw-folder-list .mdw-node {
            align-items: flex-start;
        }

        .mdw-folder-list .mdw-node__icon {
            margin-top: 1px;
        }

        .mdw-folder-list .mdw-node__label {
            display: -webkit-box;
            overflow-wrap: anywhere;
            text-overflow: clip;
            white-space: normal;
            -webkit-box-orient: vertical;
            -webkit-line-clamp: 2;
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
            min-height: 64px;
            padding: 12px 18px;
            border-bottom: 1px solid rgba(226, 232, 240, 0.9);
            background: #ffffff;
        }

        .mdw-content__meta {
            display: flex;
            flex-wrap: wrap;
            justify-content: flex-end;
            gap: 8px;
        }

        .mdw-content__actions {
            width: 100%;
            display: flex;
            justify-content: flex-end;
            gap: 8px;
        }

        .mdw-action-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 38px;
            padding: 8px 13px;
            border: 1px solid rgba(148, 163, 184, 0.45);
            border-radius: 8px;
            color: #0f172a;
            background: #ffffff;
            font-size: 14px;
            font-weight: 800;
            line-height: 1;
            cursor: pointer;
        }

        .mdw-action-button:hover {
            border-color: rgba(14, 165, 233, 0.45);
            background: #f8fafc;
        }

        .mdw-action-button.is-primary {
            border-color: #0ea5e9;
            color: #ffffff;
            background: #0ea5e9;
        }

        .mdw-hidden-file-input {
            display: none;
        }

        .mdw-modal-backdrop {
            position: fixed;
            inset: 0;
            z-index: 60;
            display: grid;
            place-items: center;
            padding: 20px;
            background: rgba(15, 23, 42, 0.18);
            backdrop-filter: blur(3px);
        }

        .mdw-modal {
            width: min(420px, 100%);
            border: 1px solid rgba(148, 163, 184, 0.35);
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.9);
            box-shadow: 0 22px 60px rgba(15, 23, 42, 0.2);
            backdrop-filter: blur(14px);
        }

        .mdw-modal__body {
            display: grid;
            gap: 14px;
            padding: 18px;
        }

        .mdw-modal__title {
            margin: 0;
            color: #0f172a;
            font-size: 18px;
            font-weight: 850;
            line-height: 1.2;
        }

        .mdw-modal__label {
            display: grid;
            gap: 7px;
            color: #334155;
            font-size: 13px;
            font-weight: 750;
        }

        .mdw-modal__input {
            width: 100%;
            min-height: 42px;
            border: 1px solid rgba(148, 163, 184, 0.65);
            border-radius: 8px;
            padding: 8px 10px;
            color: #0f172a;
            background: #ffffff;
            font-size: 15px;
            outline: none;
        }

        .mdw-modal__input:focus {
            border-color: #0ea5e9;
            box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.15);
        }

        .mdw-modal__error {
            margin: 0;
            color: #dc2626;
            font-size: 13px;
            line-height: 1.3;
        }

        .mdw-modal__actions {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
        }

        .mdw-table {
            min-width: 0;
            padding: 0;
            background: #ffffff;
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
        $activeSection = collect($sections)->firstWhere('isActive', true) ?? $sections[0];
    @endphp

    <div>
    <div class="mdw-explorer">
        <aside class="mdw-sidebar" aria-label="Папки документов">
            <nav class="mdw-tree">
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
                            <a href="{{ $folder['url'] }}" class="mdw-node {{ $folder['isActive'] ? 'is-active' : '' }}">
                                <span class="mdw-node__icon">
                                    <x-filament::icon icon="heroicon-o-folder" class="h-5 w-5" />
                                </span>
                                <span class="mdw-node__body">
                                    <span class="mdw-node__label">{{ $folder['name'] }}</span>
                                    <span class="mdw-node__meta">{{ $folder['documents'] }} файлов</span>
                                </span>
                            </a>
                        @endforeach
                    </div>
                @endif

                @php($sharedSection = collect($sections)->firstWhere('key', 'shared'))
                @if ($sharedSection)
                    <a href="{{ $sharedSection['url'] }}" class="mdw-node {{ $sharedSection['isActive'] ? 'is-active' : '' }}">
                        <span class="mdw-node__icon">
                            <x-filament::icon icon="heroicon-o-users" class="h-5 w-5" />
                        </span>
                        <span class="mdw-node__body">
                            <span class="mdw-node__label">Общий диск</span>
                            <span class="mdw-node__meta">{{ $sharedSection['documents'] }} файлов · {{ $sharedSection['folders'] }} папок</span>
                        </span>
                    </a>
                @endif

                @if (($folderGroups['shared'] ?? []) !== [])
                    <div class="mdw-folder-list">
                        @foreach ($folderGroups['shared'] as $folder)
                            <a href="{{ $folder['url'] }}" class="mdw-node {{ $folder['isActive'] ? 'is-active' : '' }}">
                                <span class="mdw-node__icon">
                                    <x-filament::icon icon="heroicon-o-folder" class="h-5 w-5" />
                                </span>
                                <span class="mdw-node__body">
                                    <span class="mdw-node__label">{{ $folder['name'] }}</span>
                                    <span class="mdw-node__meta">{{ $folder['documents'] }} файлов</span>
                                </span>
                            </a>
                        @endforeach
                    </div>
                @endif

                @php($sharedWithMeSection = collect($sections)->firstWhere('key', 'shared-with-me'))
                @if ($sharedWithMeSection)
                    <a href="{{ $sharedWithMeSection['url'] }}" class="mdw-node {{ $sharedWithMeSection['isActive'] ? 'is-active' : '' }}">
                        <span class="mdw-node__icon">
                            <x-filament::icon icon="heroicon-o-share" class="h-5 w-5" />
                        </span>
                        <span class="mdw-node__body">
                            <span class="mdw-node__label">Со мной поделились</span>
                            <span class="mdw-node__meta">{{ $sharedWithMeSection['documents'] }} файлов</span>
                        </span>
                    </a>
                @endif

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
            @if (($activeSection['key'] ?? '') !== 'shared-with-me')
            <header class="mdw-content__head">
                <div class="mdw-content__actions">
                    <button type="button" class="mdw-action-button" x-on:click="$dispatch('mdw-open-create-folder')">
                        <x-filament::icon icon="heroicon-o-folder-plus" class="h-4 w-4" />
                        <span>Создать папку</span>
                    </button>

                    <form method="POST" action="{{ route('filament.admin.market-documents.upload') }}" enctype="multipart/form-data">
                        @csrf
                        <input type="hidden" name="active_tab" value="{{ $activeSection['key'] ?? 'personal' }}">
                        <input type="hidden" name="selected_folder_id" value="{{ $activeFolder['id'] ?? '' }}">

                        <button type="button" class="mdw-action-button is-primary" onclick="this.closest('form').querySelector('[data-mdw-file-input]').click()">
                            <x-filament::icon icon="heroicon-o-arrow-up-tray" class="h-4 w-4" />
                            <span>Добавить документ</span>
                        </button>

                        <input
                            type="file"
                            name="document"
                            class="mdw-hidden-file-input"
                            data-mdw-file-input
                            onchange="if (this.files && this.files.length > 0) this.form.submit()"
                        />
                    </form>
                </div>
            </header>
            @endif

            <div class="mdw-table">
                {{ $slot }}
            </div>
        </section>
    </div>

    <div
        class="mdw-modal-backdrop"
        x-data="{ isCreateFolderModalOpen: @js($errors->has('name')) }"
        x-cloak
        x-show="isCreateFolderModalOpen"
        x-on:mdw-open-create-folder.window="isCreateFolderModalOpen = true"
        x-on:keydown.escape.window="isCreateFolderModalOpen = false"
    >
        <form class="mdw-modal" method="POST" action="{{ route('filament.admin.market-documents.folders.store') }}">
            @csrf
            <input type="hidden" name="active_tab" value="{{ $activeSection['key'] ?? 'personal' }}">
            <input type="hidden" name="selected_folder_id" value="{{ $activeFolder['id'] ?? '' }}">

            <div class="mdw-modal__body">
                <h3 class="mdw-modal__title">Новая папка</h3>

                <label class="mdw-modal__label">
                    <span>Название папки</span>
                    <input
                        type="text"
                        name="name"
                        class="mdw-modal__input"
                        value="{{ old('name') }}"
                        autocomplete="off"
                        required
                        autofocus
                    />
                </label>

                @error('name')
                    <p class="mdw-modal__error">{{ $message }}</p>
                @enderror

                <div class="mdw-modal__actions">
                    <button type="button" class="mdw-action-button" x-on:click="isCreateFolderModalOpen = false">Отмена</button>
                    <button type="submit" class="mdw-action-button is-primary">Создать</button>
                </div>
            </div>
        </form>
    </div>
    </div>
</x-filament::section>
