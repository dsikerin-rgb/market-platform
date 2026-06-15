@php
    $selectedKey = $selectedChat ? $selectedChat['type'] . ':' . $selectedChat['id'] : null;
@endphp

<div
    class="quick-chat"
    @if ($isOpen) wire:poll.15s @endif
    x-data
    x-on:mp-open-quick-chat.window="$wire.openDrawer($event.detail?.type || null, Number($event.detail?.id || 0) || null)"
    x-on:keydown.escape.window="$wire.closeDrawer()"
>
    <style>
        .quick-chat {
            pointer-events: none;
            position: relative;
            z-index: 95;
        }

        .quick-chat__launcher {
            pointer-events: auto;
            position: fixed;
            right: 6.45rem;
            bottom: 1.25rem;
            z-index: 95;
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            min-height: 2.75rem;
            border: 1px solid rgba(14, 165, 233, 0.35);
            border-radius: 999px;
            background: #e0f2fe;
            padding: 0.65rem 0.95rem;
            color: #075985;
            font-size: 0.86rem;
            font-weight: 800;
            box-shadow: 0 18px 36px rgba(14, 116, 144, 0.18);
            cursor: pointer;
        }

        .quick-chat__launcher:hover,
        .quick-chat__launcher:focus-visible {
            background: #bae6fd;
            outline: none;
        }

        body:has(#database-notifications.fi-modal-open) .quick-chat__launcher,
        body:has(#database-notifications.fi-modal-open) .staff-presence {
            display: none !important;
        }

        .quick-chat__badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 1.2rem;
            height: 1.2rem;
            border-radius: 999px;
            background: #0284c7;
            color: #fff;
            font-size: 0.68rem;
            font-weight: 900;
            line-height: 1;
        }

        .quick-chat__backdrop {
            pointer-events: auto;
            position: fixed;
            inset: 0;
            z-index: 100;
            background: rgba(15, 23, 42, 0.32);
            backdrop-filter: blur(4px);
        }

        .quick-chat__drawer {
            pointer-events: auto;
            position: fixed;
            top: 0;
            right: 0;
            bottom: 0;
            z-index: 101;
            display: grid;
            grid-template-rows: auto 1fr;
            width: min(100vw, 58rem);
            background: #f8fafc;
            box-shadow: -24px 0 70px rgba(15, 23, 42, 0.24);
        }

        .quick-chat__header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            border-bottom: 1px solid rgba(148, 163, 184, 0.22);
            background: rgba(255, 255, 255, 0.92);
            padding: 0.9rem 1rem;
        }

        .quick-chat__title {
            margin: 0;
            color: #0f172a;
            font-size: 1rem;
            font-weight: 900;
            line-height: 1.2;
        }

        .quick-chat__subtitle {
            margin-top: 0.15rem;
            color: #64748b;
            font-size: 0.8rem;
            line-height: 1.35;
        }

        .quick-chat__close {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 2.25rem;
            height: 2.25rem;
            border: 1px solid rgba(148, 163, 184, 0.28);
            border-radius: 999px;
            background: #fff;
            color: #475569;
            cursor: pointer;
        }

        .quick-chat__layout {
            display: grid;
            min-height: 0;
            grid-template-columns: minmax(16rem, 20rem) minmax(0, 1fr);
        }

        .quick-chat__list {
            min-height: 0;
            overflow: hidden;
            border-right: 1px solid rgba(148, 163, 184, 0.22);
            background: #fff;
        }

        .quick-chat__search-wrap {
            padding: 0.8rem;
        }

        .quick-chat__search {
            width: 100%;
            min-height: 2.45rem;
            border: 1px solid rgba(148, 163, 184, 0.28);
            border-radius: 999px;
            background: #f8fafc;
            padding: 0 0.9rem;
            color: #0f172a;
            font-size: 0.88rem;
            outline: none;
        }

        .quick-chat__search:focus {
            border-color: rgba(14, 165, 233, 0.65);
            background: #fff;
            box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.12);
        }

        .quick-chat__items {
            display: grid;
            max-height: calc(100vh - 7rem);
            overflow: auto;
            padding: 0.25rem 0.55rem 1rem;
        }

        .quick-chat__item {
            display: grid;
            grid-template-columns: 2.5rem minmax(0, 1fr);
            gap: 0.65rem;
            width: 100%;
            border: 0;
            border-radius: 0.85rem;
            background: transparent;
            padding: 0.65rem;
            text-align: left;
            cursor: pointer;
        }

        .quick-chat__item:hover {
            background: #f1f5f9;
        }

        .quick-chat__item--selected {
            background: #e0f2fe;
        }

        .quick-chat__avatar {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 2.5rem;
            height: 2.5rem;
            border-radius: 999px;
            background: #e2e8f0;
            color: #334155;
        }

        .quick-chat__avatar--ticket {
            background: #dcfce7;
            color: #166534;
        }

        .quick-chat__avatar--staff {
            background: #dbeafe;
            color: #1d4ed8;
        }

        .quick-chat__item-title {
            color: #0f172a;
            font-size: 0.88rem;
            font-weight: 850;
            line-height: 1.25;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .quick-chat__item-meta {
            display: flex;
            gap: 0.35rem;
            align-items: center;
            margin-top: 0.15rem;
            color: #64748b;
            font-size: 0.75rem;
            line-height: 1.25;
            min-width: 0;
        }

        .quick-chat__item-meta > span {
            min-width: 0;
        }

        .quick-chat__item-meta > span:nth-child(3) {
            flex: 0 0 auto;
            white-space: nowrap;
        }

        .quick-chat__item-preview {
            margin-top: 0.25rem;
            color: #64748b;
            font-size: 0.78rem;
            line-height: 1.3;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .quick-chat__count {
            margin-left: auto;
            flex: 0 0 auto;
            border-radius: 999px;
            background: #f1f5f9;
            padding: 0.12rem 0.45rem;
            color: #0369a1;
            font-size: 0.68rem;
            font-weight: 850;
        }

        .quick-chat__thread {
            display: grid;
            min-height: 0;
            grid-template-rows: auto 1fr auto;
            background:
                linear-gradient(135deg, rgba(14, 165, 233, 0.10), rgba(34, 197, 94, 0.10)),
                radial-gradient(circle at 20% 20%, rgba(255, 255, 255, 0.82) 0 0.18rem, transparent 0.2rem);
            background-size: auto, 2rem 2rem;
        }

        .quick-chat__chat-head {
            border-bottom: 1px solid rgba(148, 163, 184, 0.20);
            background: rgba(255, 255, 255, 0.86);
            padding: 0.85rem 1rem;
        }

        .quick-chat__chat-title {
            color: #0f172a;
            font-size: 1rem;
            font-weight: 900;
            line-height: 1.2;
        }

        .quick-chat__chat-meta {
            margin-top: 0.25rem;
            color: #475569;
            font-size: 0.78rem;
            line-height: 1.35;
        }

        .quick-chat__chat-description {
            margin-top: 0.45rem;
            color: #334155;
            font-size: 0.84rem;
            line-height: 1.45;
            overflow-wrap: anywhere;
        }

        .quick-chat__messages {
            min-height: 0;
            overflow: auto;
            padding: 1rem;
        }

        .quick-chat__date {
            display: flex;
            justify-content: center;
            margin: 0.35rem 0 0.75rem;
        }

        .quick-chat__date span {
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.78);
            padding: 0.18rem 0.65rem;
            color: #64748b;
            font-size: 0.72rem;
            font-weight: 800;
            box-shadow: 0 6px 14px rgba(15, 23, 42, 0.08);
        }

        .quick-chat__bubble-row {
            display: flex;
            margin-bottom: 0.55rem;
        }

        .quick-chat__bubble-row--own {
            justify-content: flex-end;
        }

        .quick-chat__bubble {
            max-width: min(38rem, 78%);
            border: 1px solid rgba(148, 163, 184, 0.16);
            border-radius: 0.95rem 0.95rem 0.95rem 0.25rem;
            background: #fff;
            padding: 0.62rem 0.72rem;
            color: #0f172a;
            box-shadow: 0 8px 18px rgba(15, 23, 42, 0.08);
        }

        .quick-chat__bubble--own {
            border-color: rgba(34, 197, 94, 0.22);
            border-radius: 0.95rem 0.95rem 0.25rem 0.95rem;
            background: #dcfce7;
        }

        .quick-chat__bubble-meta {
            display: flex;
            gap: 0.45rem;
            align-items: center;
            color: #64748b;
            font-size: 0.72rem;
            font-weight: 750;
            line-height: 1.2;
        }

        .quick-chat__bubble-text {
            margin-top: 0.28rem;
            font-size: 0.92rem;
            line-height: 1.45;
            overflow-wrap: anywhere;
            white-space: pre-wrap;
        }

        .quick-chat__composer {
            border-top: 1px solid rgba(148, 163, 184, 0.22);
            background: rgba(255, 255, 255, 0.92);
            padding: 0.8rem 1rem 1rem;
        }

        .quick-chat__composer-row {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 0.65rem;
            align-items: end;
        }

        .quick-chat__textarea {
            width: 100%;
            min-height: 3rem;
            max-height: 8rem;
            resize: vertical;
            border: 1px solid rgba(148, 163, 184, 0.28);
            border-radius: 1rem;
            background: #fff;
            padding: 0.78rem 0.9rem;
            color: #0f172a;
            font-size: 0.92rem;
            line-height: 1.45;
            outline: none;
        }

        .quick-chat__textarea:focus {
            border-color: rgba(14, 165, 233, 0.68);
            box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.12);
        }

        .quick-chat__send {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.42rem;
            min-height: 2.75rem;
            border: 0;
            border-radius: 0.9rem;
            background: #0ea5e9;
            padding: 0.7rem 0.9rem;
            color: #fff;
            font-size: 0.86rem;
            font-weight: 900;
            cursor: pointer;
        }

        .quick-chat__send:hover,
        .quick-chat__send:focus-visible {
            background: #0284c7;
            outline: none;
        }

        .quick-chat__empty {
            display: grid;
            place-items: center;
            min-height: 100%;
            padding: 2rem;
            color: #64748b;
            font-size: 0.92rem;
            text-align: center;
        }

        .quick-chat__error {
            margin-top: 0.35rem;
            color: #dc2626;
            font-size: 0.78rem;
            font-weight: 800;
        }

        html.dark .quick-chat__drawer,
        html.dark .quick-chat__search,
        html.dark .quick-chat__close {
            background: #0f172a;
            color: #e2e8f0;
        }

        html.dark .quick-chat__header,
        html.dark .quick-chat__list,
        html.dark .quick-chat__chat-head,
        html.dark .quick-chat__composer {
            background: rgba(15, 23, 42, 0.92);
        }

        html.dark .quick-chat__title,
        html.dark .quick-chat__item-title,
        html.dark .quick-chat__chat-title,
        html.dark .quick-chat__bubble,
        html.dark .quick-chat__textarea {
            color: #f8fafc;
        }

        html.dark .quick-chat__item:hover {
            background: rgba(148, 163, 184, 0.12);
        }

        html.dark .quick-chat__item--selected {
            background: rgba(14, 165, 233, 0.22);
        }

        html.dark .quick-chat__bubble,
        html.dark .quick-chat__textarea {
            background: #111827;
        }

        html.dark .quick-chat__bubble--own {
            background: rgba(22, 101, 52, 0.82);
        }

        @media (max-width: 1023px) {
            .quick-chat__launcher {
                right: 1rem;
            }

            .quick-chat__drawer {
                width: 100vw;
            }
        }

        @media (max-width: 760px) {
            .quick-chat__layout {
                grid-template-columns: minmax(0, 1fr);
                grid-template-rows: auto 1fr;
            }

            .quick-chat__list {
                border-right: 0;
                border-bottom: 1px solid rgba(148, 163, 184, 0.22);
            }

            .quick-chat__items {
                grid-auto-flow: column;
                grid-auto-columns: minmax(13rem, 16rem);
                max-height: none;
                overflow-x: auto;
                overflow-y: hidden;
                padding-bottom: 0.75rem;
            }

            .quick-chat__thread {
                min-height: 0;
            }

            .quick-chat__bubble {
                max-width: 88%;
            }

            .quick-chat__composer-row {
                grid-template-columns: minmax(0, 1fr);
            }
        }
    </style>

    <button type="button" class="quick-chat__launcher" wire:click="openDrawer" aria-label="Открыть диалоги">
        <x-filament::icon icon="heroicon-o-chat-bubble-left-right" class="h-5 w-5" />
        <span>Диалоги</span>
        @if ($unreadCount > 0)
            <span class="quick-chat__badge">{{ $unreadCount > 99 ? '99+' : $unreadCount }}</span>
        @endif
    </button>

    @if ($isOpen)
        <div class="quick-chat__backdrop" wire:click="closeDrawer"></div>

        <aside class="quick-chat__drawer" role="dialog" aria-modal="true" aria-label="Диалоги">
            <header class="quick-chat__header">
                <div>
                    <h2 class="quick-chat__title">Диалоги</h2>
                    <div class="quick-chat__subtitle">Сообщения сотрудников и обращения арендаторов</div>
                </div>

                <button type="button" class="quick-chat__close" wire:click="closeDrawer" aria-label="Закрыть">
                    <x-filament::icon icon="heroicon-o-x-mark" class="h-5 w-5" />
                </button>
            </header>

            <div class="quick-chat__layout">
                <section class="quick-chat__list" aria-label="Список диалогов">
                    <div class="quick-chat__search-wrap">
                        <input
                            type="search"
                            class="quick-chat__search"
                            wire:model.live.debounce.400ms="search"
                            placeholder="Поиск"
                        >
                    </div>

                    <div class="quick-chat__items">
                        @forelse ($recentChats as $chat)
                            @php
                                $key = $chat['type'] . ':' . $chat['id'];
                                $isSelected = $selectedKey === $key;
                            @endphp

                            <button
                                type="button"
                                wire:key="quick-chat-item-{{ $key }}"
                                wire:click="selectChat('{{ $chat['type'] }}', {{ (int) $chat['id'] }})"
                                class="quick-chat__item {{ $isSelected ? 'quick-chat__item--selected' : '' }}"
                            >
                                <span class="quick-chat__avatar quick-chat__avatar--{{ $chat['type'] }}">
                                    @if ($chat['type'] === 'staff')
                                        <x-filament::icon icon="heroicon-o-user-group" class="h-5 w-5" />
                                    @else
                                        <x-filament::icon icon="heroicon-o-building-storefront" class="h-5 w-5" />
                                    @endif
                                </span>

                                <span style="min-width: 0;">
                                    <span class="quick-chat__item-title">{{ $chat['title'] }}</span>
                                    <span class="quick-chat__item-meta">
                                        <span style="min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">{{ $chat['subtitle'] }}</span>
                                        @if ($chat['meta'])
                                            <span>·</span>
                                            <span>{{ $chat['meta'] }}</span>
                                        @endif
                                        <span class="quick-chat__count">{{ $chat['count'] }}</span>
                                    </span>
                                    @if ($chat['preview'])
                                        <span class="quick-chat__item-preview">{{ $chat['preview'] }}</span>
                                    @endif
                                </span>
                            </button>
                        @empty
                            <div class="quick-chat__empty">Диалогов пока нет.</div>
                        @endforelse
                    </div>
                </section>

                <section
                    class="quick-chat__thread"
                    aria-label="Переписка"
                    x-data="{ scroll() { this.$nextTick(() => { const el = this.$refs.messages; if (el) el.scrollTop = el.scrollHeight }) } }"
                    x-init="scroll()"
                    x-on:quick-chat-updated.window="scroll()"
                >
                    @if ($selectedChat)
                        <div class="quick-chat__chat-head">
                            <div class="quick-chat__chat-title">{{ $selectedChat['title'] }}</div>
                            <div class="quick-chat__chat-meta">
                                {{ $selectedChat['subtitle'] }}
                                @if ($selectedChat['meta'])
                                    · {{ $selectedChat['meta'] }}
                                @endif
                                · {{ $selectedChat['count'] }} в ленте
                            </div>
                            @if ($selectedChat['description'])
                                <div class="quick-chat__chat-description">{{ \Illuminate\Support\Str::limit($selectedChat['description'], 220) }}</div>
                            @endif
                        </div>

                        <div class="quick-chat__messages" x-ref="messages">
                            @php $lastDateKey = null; @endphp

                            @forelse ($messages as $message)
                                @if ($message['date_key'] !== $lastDateKey)
                                    @php $lastDateKey = $message['date_key']; @endphp
                                    <div class="quick-chat__date"><span>{{ $message['date_label'] }}</span></div>
                                @endif

                                <div class="quick-chat__bubble-row {{ $message['is_own'] ? 'quick-chat__bubble-row--own' : '' }}" wire:key="quick-chat-message-{{ $message['id'] }}">
                                    <div class="quick-chat__bubble {{ $message['is_own'] ? 'quick-chat__bubble--own' : '' }}">
                                        <div class="quick-chat__bubble-meta">
                                            <span>{{ $message['user_name'] }}</span>
                                            @if ($message['created_at'])
                                                <span>·</span>
                                                <span>{{ $message['created_at'] }}</span>
                                            @endif
                                        </div>
                                        <div class="quick-chat__bubble-text">{{ $message['body'] }}</div>
                                    </div>
                                </div>
                            @empty
                                <div class="quick-chat__empty">В этом диалоге пока нет сообщений.</div>
                            @endforelse
                        </div>

                        <form class="quick-chat__composer" wire:submit.prevent="sendMessage">
                            <div class="quick-chat__composer-row">
                                <div>
                                    <textarea
                                        class="quick-chat__textarea"
                                        wire:model.defer="messageBody"
                                        placeholder="Сообщение..."
                                    ></textarea>

                                    @error('messageBody')
                                        <div class="quick-chat__error">{{ $message }}</div>
                                    @enderror
                                </div>

                                <button type="submit" class="quick-chat__send">
                                    <x-filament::icon icon="heroicon-o-paper-airplane" class="h-5 w-5" />
                                    <span>Отправить</span>
                                </button>
                            </div>
                        </form>
                    @else
                        <div class="quick-chat__empty">Выберите диалог слева.</div>
                    @endif
                </section>
            </div>
        </aside>
    @endif
</div>
