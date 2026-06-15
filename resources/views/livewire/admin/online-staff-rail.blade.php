@php
    $initials = static function (?string $name): string {
        $name = trim((string) $name);

        if ($name === '') {
            return '?';
        }

        $parts = preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $letters = array_map(static fn (string $part): string => mb_substr($part, 0, 1), array_slice($parts, 0, 2));

        return mb_strtoupper(implode('', $letters));
    };

    $lastSeenLabel = static function ($value): string {
        if (! $value) {
            return 'не появлялся';
        }

        try {
            return \Carbon\CarbonImmutable::parse($value)
                ->timezone(config('app.timezone'))
                ->format('d.m.Y H:i');
        } catch (\Throwable) {
            return 'неизвестно';
        }
    };

    $avatarUrl = static function ($user): ?string {
        return $user instanceof \App\Models\User ? $user->staffAvatarUrl() : null;
    };

    $avatarColor = static function ($user): string {
        return $user instanceof \App\Models\User ? $user->staffAvatarColor() : '#2563eb';
    };
@endphp

<div class="staff-presence" wire:poll.10s aria-label="Сотрудники">
    <style>
        .staff-presence {
            --staff-presence-gutter: 5.75rem;
            --staff-presence-width: 3.25rem;
            pointer-events: none;
            position: fixed;
            inset: 0 0 0 auto;
            z-index: 60;
            width: var(--staff-presence-gutter);
        }

        .staff-presence__stack {
            pointer-events: auto;
            position: fixed;
            right: max(0.75rem, calc((var(--staff-presence-gutter) - var(--staff-presence-width)) / 2));
            display: flex;
            width: var(--staff-presence-width);
            flex-direction: column;
            align-items: center;
            gap: 0.45rem;
            padding: 0.55rem 0.4rem;
            border: 1px solid rgba(148, 163, 184, 0.22);
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.86);
            box-shadow: 0 18px 36px rgba(15, 23, 42, 0.12);
            backdrop-filter: blur(10px);
        }

        .staff-presence__stack--online {
            top: 7rem;
        }

        .staff-presence__stack--offline {
            bottom: 1.25rem;
            opacity: 0.72;
        }

        html.dark .staff-presence__stack {
            border-color: rgba(148, 163, 184, 0.18);
            background: rgba(15, 23, 42, 0.82);
            box-shadow: 0 18px 36px rgba(2, 6, 23, 0.28);
        }

        .staff-presence__label {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 2.25rem;
            height: 2.25rem;
            border-radius: 999px;
            background: rgba(34, 197, 94, 0.12);
            color: #16a34a;
        }

        .staff-presence__label--offline {
            background: rgba(100, 116, 139, 0.11);
            color: #64748b;
        }

        .staff-presence__avatar {
            --staff-avatar-color: #2563eb;
            position: relative;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 2.45rem;
            height: 2.45rem;
            overflow: visible;
            border: 1px solid color-mix(in srgb, var(--staff-avatar-color) 24%, transparent);
            border-radius: 999px;
            background: linear-gradient(180deg, color-mix(in srgb, var(--staff-avatar-color) 16%, #ffffff) 0%, color-mix(in srgb, var(--staff-avatar-color) 30%, #ffffff) 100%);
            color: var(--staff-avatar-color);
            font-size: 0.78rem;
            font-weight: 800;
            letter-spacing: 0;
            box-shadow: 0 8px 18px rgba(15, 23, 42, 0.10);
            cursor: pointer;
            transition: transform 150ms ease, opacity 150ms ease, box-shadow 150ms ease;
        }

        .staff-presence__avatar:hover,
        .staff-presence__avatar:focus-visible {
            transform: translateY(-1px);
            box-shadow: 0 12px 24px rgba(15, 23, 42, 0.16);
            outline: none;
        }

        html.dark .staff-presence__avatar {
            border-color: color-mix(in srgb, var(--staff-avatar-color) 36%, transparent);
            background: linear-gradient(180deg, color-mix(in srgb, var(--staff-avatar-color) 50%, #0f172a) 0%, #0f172a 100%);
            color: #ffffff;
        }

        .staff-presence__avatar-image,
        .staff-presence__card-avatar-image {
            width: 100%;
            height: 100%;
            border-radius: inherit;
            object-fit: cover;
        }

        .staff-presence__avatar::after {
            content: '';
            position: absolute;
            right: 0.1rem;
            bottom: 0.1rem;
            width: 0.62rem;
            height: 0.62rem;
            border: 2px solid #fff;
            border-radius: 999px;
            background: #22c55e;
        }

        .staff-presence__avatar--offline {
            border-color: rgba(100, 116, 139, 0.18);
            background: linear-gradient(180deg, #f8fafc 0%, #e2e8f0 100%);
            color: #64748b;
            opacity: 0.72;
            filter: saturate(0.65);
        }

        .staff-presence__unread-badge {
            position: absolute;
            top: -0.25rem;
            right: -0.25rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 1.05rem;
            height: 1.05rem;
            padding: 0 0.22rem;
            border: 2px solid #fff;
            border-radius: 999px;
            background: #ef4444;
            color: #fff;
            font-size: 0.62rem;
            font-weight: 900;
            line-height: 1;
            box-shadow: 0 8px 16px rgba(239, 68, 68, 0.28);
        }

        html.dark .staff-presence__unread-badge {
            border-color: #0f172a;
        }

        .staff-presence__avatar--offline::after {
            background: #94a3b8;
        }

        .staff-presence__avatar--offline:hover,
        .staff-presence__avatar--offline:focus-visible {
            opacity: 0.95;
            filter: saturate(0.9);
        }

        html.dark .staff-presence__avatar::after {
            border-color: #0f172a;
        }

        .staff-presence__empty {
            width: 2.45rem;
            color: #94a3b8;
            font-size: 0.68rem;
            line-height: 1.15;
            text-align: center;
        }

        .staff-presence__modal {
            pointer-events: auto;
            position: fixed;
            inset: 0;
            z-index: 90;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.25rem;
            background: rgba(15, 23, 42, 0.42);
            backdrop-filter: blur(6px);
        }

        .staff-presence__card {
            width: min(100%, 36rem);
            border: 1px solid rgba(148, 163, 184, 0.22);
            border-radius: 1.25rem;
            background: #fff;
            box-shadow: 0 28px 80px rgba(15, 23, 42, 0.28);
            overflow: hidden;
        }

        html.dark .staff-presence__card {
            border-color: rgba(148, 163, 184, 0.18);
            background: #0f172a;
        }

        .staff-presence__card-head {
            display: flex;
            gap: 0.9rem;
            align-items: flex-start;
            padding: 1.1rem 1.2rem;
            border-bottom: 1px solid rgba(148, 163, 184, 0.16);
        }

        .staff-presence__card-avatar {
            --staff-avatar-color: #2563eb;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 3rem;
            height: 3rem;
            overflow: hidden;
            border-radius: 999px;
            background: linear-gradient(180deg, color-mix(in srgb, var(--staff-avatar-color) 16%, #ffffff) 0%, color-mix(in srgb, var(--staff-avatar-color) 30%, #ffffff) 100%);
            color: var(--staff-avatar-color);
            font-weight: 800;
            flex-shrink: 0;
        }

        html.dark .staff-presence__card-avatar {
            background: linear-gradient(180deg, color-mix(in srgb, var(--staff-avatar-color) 50%, #0f172a) 0%, #0f172a 100%);
            color: #ffffff;
        }

        .staff-presence__card-title {
            margin: 0;
            color: #0f172a;
            font-size: 1rem;
            font-weight: 800;
            line-height: 1.25;
        }

        html.dark .staff-presence__card-title {
            color: #f8fafc;
        }

        .staff-presence__card-meta {
            margin-top: 0.25rem;
            color: #64748b;
            font-size: 0.84rem;
            line-height: 1.4;
        }

        html.dark .staff-presence__card-meta {
            color: #94a3b8;
        }

        .staff-presence__close {
            margin-left: auto;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 2.25rem;
            height: 2.25rem;
            border: 1px solid rgba(148, 163, 184, 0.22);
            border-radius: 999px;
            background: transparent;
            color: #64748b;
            cursor: pointer;
        }

        .staff-presence__card-body {
            display: grid;
            gap: 0.85rem;
            padding: 1rem 1.2rem 1.2rem;
        }

        .staff-presence__facts {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr));
            gap: 0.65rem;
        }

        .staff-presence__fact {
            min-width: 0;
            padding: 0.75rem 0.85rem;
            border: 1px solid rgba(148, 163, 184, 0.18);
            border-radius: 0.9rem;
            background: #f8fafc;
        }

        html.dark .staff-presence__fact {
            border-color: rgba(148, 163, 184, 0.14);
            background: rgba(255, 255, 255, 0.04);
        }

        .staff-presence__fact-label {
            color: #64748b;
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
        }

        .staff-presence__fact-value {
            margin-top: 0.25rem;
            color: #0f172a;
            font-size: 0.9rem;
            font-weight: 700;
            overflow-wrap: break-word;
            word-break: normal;
        }

        html.dark .staff-presence__fact-value {
            color: #e2e8f0;
        }

        .staff-presence__unread-panel {
            display: grid;
            gap: 0.65rem;
            padding: 0.85rem;
            border: 1px solid rgba(239, 68, 68, 0.22);
            border-radius: 0.95rem;
            background: #fff7f7;
        }

        html.dark .staff-presence__unread-panel {
            border-color: rgba(248, 113, 113, 0.22);
            background: rgba(127, 29, 29, 0.18);
        }

        .staff-presence__unread-title {
            display: flex;
            align-items: center;
            gap: 0.45rem;
            color: #b91c1c;
            font-size: 0.82rem;
            font-weight: 900;
        }

        html.dark .staff-presence__unread-title {
            color: #fecaca;
        }

        .staff-presence__unread-list {
            display: grid;
            gap: 0.45rem;
        }

        .staff-presence__unread-item {
            color: #334155;
            font-size: 0.83rem;
            line-height: 1.45;
            overflow-wrap: anywhere;
        }

        html.dark .staff-presence__unread-item {
            color: #e2e8f0;
        }

        .staff-presence__unread-time {
            color: #64748b;
            font-size: 0.74rem;
            font-weight: 700;
        }

        html.dark .staff-presence__unread-time {
            color: #fca5a5;
        }

        .staff-presence__textarea {
            width: 100%;
            min-height: 5.5rem;
            resize: vertical;
            border: 1px solid rgba(148, 163, 184, 0.24);
            border-radius: 0.95rem;
            background: #fff;
            padding: 0.8rem 0.9rem;
            color: #0f172a;
            font-size: 0.92rem;
            line-height: 1.55;
            outline: none;
        }

        html.dark .staff-presence__textarea {
            border-color: rgba(148, 163, 184, 0.20);
            background: rgba(15, 23, 42, 0.86);
            color: #f8fafc;
        }

        .staff-presence__error {
            color: #dc2626;
            font-size: 0.8rem;
            font-weight: 700;
        }

        .staff-presence__actions {
            display: flex;
            justify-content: flex-end;
            gap: 0.65rem;
            flex-wrap: wrap;
        }

        .staff-presence__button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 2.4rem;
            border: 0;
            border-radius: 0.85rem;
            background: #2563eb;
            padding: 0.55rem 0.9rem;
            color: #fff;
            font-size: 0.86rem;
            font-weight: 800;
            cursor: pointer;
        }

        .staff-presence__button--ghost {
            border: 1px solid rgba(148, 163, 184, 0.24);
            background: transparent;
            color: #475569;
        }

        @media (min-width: 1280px) {
            html:not([data-admin-overrides="0"]) .fi-main {
                padding-right: calc(clamp(1rem, 1.2vw, 1.75rem) + var(--staff-presence-gutter, 5.75rem)) !important;
            }
        }

        @media (max-width: 1023px) {
            .staff-presence {
                display: none;
            }
        }

        @media (max-width: 640px) {
            .staff-presence__facts {
                grid-template-columns: minmax(0, 1fr);
            }
        }
    </style>

    <div class="staff-presence__stack staff-presence__stack--online">
        <div class="staff-presence__label" title="Сейчас онлайн">
            <x-filament::icon icon="heroicon-o-users" class="h-5 w-5" />
        </div>

        @forelse ($onlineStaff as $person)
            @php
                $personAvatarUrl = $avatarUrl($person);
                $unreadCount = (int) ($person->unread_staff_messages_count ?? 0);
            @endphp

            <button
                type="button"
                class="staff-presence__avatar"
                title="{{ $person->name }} · онлайн"
                style="--staff-avatar-color: {{ $avatarColor($person) }}"
                wire:click="openStaffModal({{ (int) $person->id }})"
            >
                @if ($personAvatarUrl)
                    <img class="staff-presence__avatar-image" src="{{ $personAvatarUrl }}" alt="{{ $person->name }}" loading="lazy">
                @else
                    {{ $initials($person->name) }}
                @endif

                @if ($unreadCount > 0)
                    <span class="staff-presence__unread-badge">{{ $unreadCount > 9 ? '9+' : $unreadCount }}</span>
                @endif
            </button>
        @empty
            <div class="staff-presence__empty">нет онлайн</div>
        @endforelse
    </div>

    <div class="staff-presence__stack staff-presence__stack--offline">
        <div class="staff-presence__label staff-presence__label--offline" title="Сотрудники офлайн">
            <x-filament::icon icon="heroicon-o-user-minus" class="h-5 w-5" />
        </div>

        @forelse ($offlineStaff as $person)
            @php
                $personAvatarUrl = $avatarUrl($person);
                $unreadCount = (int) ($person->unread_staff_messages_count ?? 0);
            @endphp

            <button
                type="button"
                class="staff-presence__avatar staff-presence__avatar--offline"
                title="{{ $person->name }} · офлайн"
                style="--staff-avatar-color: {{ $avatarColor($person) }}"
                wire:click="openStaffModal({{ (int) $person->id }})"
            >
                @if ($personAvatarUrl)
                    <img class="staff-presence__avatar-image" src="{{ $personAvatarUrl }}" alt="{{ $person->name }}" loading="lazy">
                @else
                    {{ $initials($person->name) }}
                @endif

                @if ($unreadCount > 0)
                    <span class="staff-presence__unread-badge">{{ $unreadCount > 9 ? '9+' : $unreadCount }}</span>
                @endif
            </button>
        @empty
            <div class="staff-presence__empty">нет офлайн</div>
        @endforelse
    </div>

    @if ($selectedStaff)
        @php
            $selectedAvatarUrl = $avatarUrl($selectedStaff);
            $firstUnreadConversationId = (int) ($selectedStaffUnreadMessages->first()?->staff_conversation_id ?? 0);
            $conversationUrl = $firstUnreadConversationId > 0
                ? url('/admin/requests?' . http_build_query([
                    'channel' => 'staff',
                    'conversation_id' => $firstUnreadConversationId,
                ]))
                : null;
        @endphp

        <div class="staff-presence__modal" wire:click.self="closeStaffModal">
            <div class="staff-presence__card" role="dialog" aria-modal="true">
                <div class="staff-presence__card-head">
                    <div class="staff-presence__card-avatar" style="--staff-avatar-color: {{ $avatarColor($selectedStaff) }}">
                        @if ($selectedAvatarUrl)
                            <img class="staff-presence__card-avatar-image" src="{{ $selectedAvatarUrl }}" alt="{{ $selectedStaff->name }}" loading="lazy">
                        @else
                            {{ $initials($selectedStaff->name) }}
                        @endif
                    </div>

                    <div>
                        <h3 class="staff-presence__card-title">{{ $selectedStaff->name }}</h3>
                        <div class="staff-presence__card-meta">
                            {{ $selectedStaff->last_seen_at && $selectedStaff->last_seen_at->greaterThan(now()->subMinutes(5)) ? 'Сейчас онлайн' : 'Офлайн' }}
                        </div>
                    </div>

                    <button type="button" class="staff-presence__close" wire:click="closeStaffModal" aria-label="Закрыть">
                        <x-filament::icon icon="heroicon-o-x-mark" class="h-5 w-5" />
                    </button>
                </div>

                <div class="staff-presence__card-body">
                    @if ($selectedStaffUnreadMessages->isNotEmpty())
                        <div class="staff-presence__unread-panel">
                            <div class="staff-presence__unread-title">
                                <x-filament::icon icon="heroicon-o-bell-alert" class="h-4 w-4" />
                                Новые сообщения
                            </div>

                            <div class="staff-presence__unread-list">
                                @foreach ($selectedStaffUnreadMessages as $message)
                                    <div class="staff-presence__unread-item" wire:key="staff-unread-message-{{ $message->id }}">
                                        <div class="staff-presence__unread-time">{{ $message->created_at?->timezone(config('app.timezone'))->format('d.m.Y H:i') }}</div>
                                        {{ \Illuminate\Support\Str::limit(trim((string) $message->body), 180) }}
                                    </div>
                                @endforeach
                            </div>

                            <div class="staff-presence__actions">
                                @if ($conversationUrl)
                                    <a class="staff-presence__button staff-presence__button--ghost" href="{{ $conversationUrl }}">
                                        Открыть переписку
                                    </a>
                                @endif

                                <button type="button" class="staff-presence__button" wire:click="markSelectedStaffMessagesRead">
                                    Отметить прочитанным
                                </button>
                            </div>
                        </div>
                    @endif

                    <div class="staff-presence__facts">
                        <div class="staff-presence__fact">
                            <div class="staff-presence__fact-label">Email</div>
                            <div class="staff-presence__fact-value">{{ $selectedStaff->email ?: 'не указан' }}</div>
                        </div>

                        <div class="staff-presence__fact">
                            <div class="staff-presence__fact-label">Телефон</div>
                            <div class="staff-presence__fact-value">{{ $selectedStaff->phone ?: 'не указан' }}</div>
                        </div>

                        <div class="staff-presence__fact">
                            <div class="staff-presence__fact-label">Последний раз</div>
                            <div class="staff-presence__fact-value">{{ $lastSeenLabel($selectedStaff->last_seen_at) }}</div>
                        </div>
                    </div>

                    <form wire:submit.prevent="sendStaffMessage">
                        <textarea
                            class="staff-presence__textarea"
                            wire:model.defer="messageBody"
                            placeholder="Напишите сообщение сотруднику..."
                        ></textarea>

                        @error('messageBody')
                            <div class="staff-presence__error">{{ $message }}</div>
                        @enderror

                        <div class="staff-presence__actions" style="margin-top: 0.75rem;">
                            <button type="button" class="staff-presence__button staff-presence__button--ghost" wire:click="closeStaffModal">
                                Отмена
                            </button>
                            <button type="submit" class="staff-presence__button">
                                Отправить
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif
</div>
