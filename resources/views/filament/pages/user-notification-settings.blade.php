<x-filament-panels::page>
    @php
        $telegramProfile = is_array($currentUser?->telegram_profile ?? null) ? $currentUser->telegram_profile : [];
        $telegramUsername = trim((string) ($telegramProfile['username'] ?? ''));
        $telegramFirstName = trim((string) ($telegramProfile['first_name'] ?? ''));
        $telegramLastName = trim((string) ($telegramProfile['last_name'] ?? ''));
        $telegramDisplayName = trim($telegramFirstName . ' ' . $telegramLastName);
        $telegramConnected = filled($currentUser?->telegram_chat_id);
        $fallbackUrl = \App\Filament\Pages\Dashboard::getUrl();
    @endphp

    <style>
        .notification-settings-dialog-page {
            max-width: 760px;
            margin: 0 auto;
        }

        .notification-settings-dialog {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            padding: 1.25rem;
            border-radius: 1.5rem;
            border: 1px solid rgba(148, 163, 184, 0.18);
            background: rgba(255, 255, 255, 0.98);
            box-shadow: 0 24px 48px rgba(15, 23, 42, 0.14);
        }

        html.dark .notification-settings-dialog {
            background: rgba(15, 23, 42, 0.92);
            border-color: rgba(148, 163, 184, 0.16);
            box-shadow: 0 24px 48px rgba(2, 6, 23, 0.35);
        }

        .notification-settings-dialog-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 1rem;
        }

        .notification-settings-dialog-title {
            margin: 0;
            color: #0f172a;
            font-size: 1.5rem;
            font-weight: 700;
            line-height: 1.15;
        }

        html.dark .notification-settings-dialog-title {
            color: #f8fafc;
        }

        .notification-settings-dialog-text {
            margin: 0.5rem 0 0;
            color: #475569;
            line-height: 1.65;
        }

        html.dark .notification-settings-dialog-text {
            color: #cbd5e1;
        }

        .notification-settings-row {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .notification-settings-card,
        .notification-settings-helper {
            border-radius: 1.1rem;
            border: 1px solid rgba(148, 163, 184, 0.18);
            background: #f8fafc;
            padding: 1rem;
        }

        html.dark .notification-settings-card,
        html.dark .notification-settings-helper {
            background: rgba(255, 255, 255, 0.04);
            border-color: rgba(148, 163, 184, 0.14);
        }

        .notification-settings-card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 1rem;
        }

        .notification-settings-card-title {
            margin: 0;
            color: #0f172a;
            font-size: 1rem;
            font-weight: 700;
        }

        html.dark .notification-settings-card-title {
            color: #f8fafc;
        }

        .notification-settings-card-text {
            margin: 0.35rem 0 0;
            color: #475569;
            font-size: 0.95rem;
            line-height: 1.6;
        }

        html.dark .notification-settings-card-text {
            color: #cbd5e1;
        }

        .notification-settings-status {
            display: inline-flex;
            align-items: center;
            gap: 0.55rem;
            padding: 0.45rem 0.8rem;
            border-radius: 999px;
            font-size: 0.8rem;
            font-weight: 700;
            white-space: nowrap;
        }

        .notification-settings-status::before {
            content: "";
            width: 0.55rem;
            height: 0.55rem;
            border-radius: 999px;
            background: currentColor;
            opacity: 0.9;
        }

        .notification-settings-status--connected {
            color: #047857;
            background: #ecfdf5;
            border: 1px solid #a7f3d0;
        }

        .notification-settings-status--disconnected {
            color: #b45309;
            background: #fffbeb;
            border: 1px solid #fde68a;
        }

        html.dark .notification-settings-status--connected {
            color: #6ee7b7;
            background: rgba(16, 185, 129, 0.12);
            border-color: rgba(16, 185, 129, 0.28);
        }

        html.dark .notification-settings-status--disconnected {
            color: #fbbf24;
            background: rgba(245, 158, 11, 0.1);
            border-color: rgba(245, 158, 11, 0.28);
        }

        .notification-settings-meta {
            margin-top: 0.8rem;
            color: #64748b;
            font-size: 0.9rem;
            line-height: 1.6;
        }

        html.dark .notification-settings-meta {
            color: #94a3b8;
        }

        .notification-settings-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            margin-top: 1rem;
        }

        .notification-settings-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0.9rem;
        }

        .notification-settings-link-box {
            margin-top: 1rem;
            padding: 1rem;
            border-radius: 1rem;
            border: 1px dashed rgba(245, 158, 11, 0.42);
            background: rgba(255, 251, 235, 0.9);
        }

        html.dark .notification-settings-link-box {
            background: rgba(245, 158, 11, 0.08);
        }

        .notification-settings-link-step + .notification-settings-link-step {
            margin-top: 0.75rem;
        }

        .notification-settings-link-title {
            margin: 0;
            color: #0f172a;
            font-size: 0.95rem;
            font-weight: 700;
        }

        html.dark .notification-settings-link-title {
            color: #f8fafc;
        }

        .notification-settings-link-value {
            margin-top: 0.45rem;
            word-break: break-all;
            color: #d97706;
            line-height: 1.6;
        }

        .notification-settings-command {
            margin: 0.5rem 0 0;
            padding: 0.8rem 0.9rem;
            border-radius: 0.8rem;
            background: #020617;
            color: #fde68a;
            font-size: 0.85rem;
            overflow-x: auto;
        }

        .notification-settings-link-layout {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 160px;
            gap: 1rem;
            align-items: start;
        }

        .notification-settings-qr {
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 0.75rem;
            border-radius: 1rem;
            background: #ffffff;
            border: 1px solid rgba(148, 163, 184, 0.2);
        }

        .notification-settings-qr img {
            width: 140px;
            height: 140px;
            display: block;
        }

        .notification-settings-form {
            padding-top: 0.25rem;
        }

        .notification-settings-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            padding-top: 1rem;
            margin-top: 1rem;
            border-top: 1px solid rgba(148, 163, 184, 0.18);
        }

        .notification-settings-helper-title {
            margin: 0;
            color: #0f172a;
            font-size: 0.95rem;
            font-weight: 700;
        }

        html.dark .notification-settings-helper-title {
            color: #f8fafc;
        }

        .notification-settings-helper-text {
            margin: 0.45rem 0 0;
            color: #475569;
            line-height: 1.6;
            font-size: 0.92rem;
        }

        html.dark .notification-settings-helper-text {
            color: #cbd5e1;
        }

        @media (max-width: 720px) {
            .notification-settings-dialog {
                padding: 1rem;
                border-radius: 1rem;
            }

            .notification-settings-dialog-header,
            .notification-settings-card-header,
            .notification-settings-footer,
            .notification-settings-grid,
            .notification-settings-link-layout {
                display: flex;
                flex-direction: column;
            }
        }
    </style>

    <div class="notification-settings-dialog-page">
        <div class="notification-settings-dialog">
            <div class="notification-settings-dialog-header">
                <div>
                    <h1 class="notification-settings-dialog-title">Кабинет уведомлений</h1>
                    <p class="notification-settings-dialog-text">
                        Здесь можно быстро настроить каналы доставки, Telegram и уведомления о входах в админку.
                    </p>
                </div>

                <x-filament::button
                    type="button"
                    color="gray"
                    icon="heroicon-o-x-mark"
                    x-on:click="window.history.length > 1 ? window.history.back() : window.location.assign('{{ $fallbackUrl }}')"
                >
                    Закрыть
                </x-filament::button>
            </div>

            <div class="notification-settings-row">
                <section class="notification-settings-card">
                    <div class="notification-settings-card-header">
                        <div>
                            <h2 class="notification-settings-card-title">Telegram</h2>
                            <p class="notification-settings-card-text">
                                Подключите чат, чтобы получать уведомления в Telegram и проверять работу канала доставки.
                            </p>
                        </div>

                        <div class="notification-settings-status {{ $telegramConnected ? 'notification-settings-status--connected' : 'notification-settings-status--disconnected' }}">
                            {{ $telegramConnected ? 'Подключено' : 'Не подключено' }}
                        </div>
                    </div>

                    <div class="notification-settings-grid" style="margin-top: 1rem;">
                        <div class="notification-settings-helper">
                            <h3 class="notification-settings-helper-title">Статус чата</h3>
                            <p class="notification-settings-helper-text">
                                @if ($telegramConnected)
                                    chat_id: {{ $currentUser->telegram_chat_id }}
                                @else
                                    Telegram ещё не привязан.
                                @endif
                            </p>
                            @if ($telegramUsername !== '' || $telegramDisplayName !== '')
                                <p class="notification-settings-helper-text" style="margin-top:0.35rem;">
                                    @if ($telegramDisplayName !== '')
                                        {{ $telegramDisplayName }}
                                    @endif
                                    @if ($telegramUsername !== '')
                                        @if ($telegramDisplayName !== '')
                                            <span style="color:#94a3b8;"> • </span>
                                        @endif
                                        {{ '@' . $telegramUsername }}
                                    @endif
                                </p>
                            @endif
                        </div>

                        <div class="notification-settings-helper">
                            <h3 class="notification-settings-helper-title">Что делает тема безопасности</h3>
                            <p class="notification-settings-helper-text">{{ $this->securityTopicHelper() }}</p>
                        </div>
                    </div>

                    <div class="notification-settings-actions">
                        <x-filament::button type="button" wire:click="generateTelegramConnectLink" icon="heroicon-o-link">
                            Подключить Telegram
                        </x-filament::button>

                        <x-filament::button type="button" wire:click="refreshTelegramStatus" color="gray" icon="heroicon-o-arrow-path">
                            Обновить статус
                        </x-filament::button>
                    </div>

                    @if ($telegramLinkData)
                        <div class="notification-settings-link-box">
                            <div class="notification-settings-link-layout">
                                <div>
                                    @if (! empty($telegramLinkData['deep_link']))
                                        <div class="notification-settings-link-step">
                                            <h3 class="notification-settings-link-title">1. Откройте ссылку</h3>
                                            <div class="notification-settings-link-value">
                                                <a href="{{ $telegramLinkData['deep_link'] }}" target="_blank" rel="noopener noreferrer">
                                                    {{ $telegramLinkData['deep_link'] }}
                                                </a>
                                            </div>
                                        </div>
                                    @elseif (! empty($telegramLinkData['bot_username']))
                                        <div class="notification-settings-link-step">
                                            <h3 class="notification-settings-link-title">1. Откройте бота</h3>
                                            <div class="notification-settings-link-value">{{ '@' . $telegramLinkData['bot_username'] }}</div>
                                        </div>
                                    @endif

                                    <div class="notification-settings-link-step">
                                        <h3 class="notification-settings-link-title">2. Отправьте команду</h3>
                                        <pre class="notification-settings-command">{{ $telegramLinkData['command'] }}</pre>
                                    </div>

                                    <div class="notification-settings-meta">
                                        Ссылка действует до {{ $telegramLinkData['expires_at'] }}.
                                    </div>
                                </div>

                                @if (! empty($telegramLinkData['qr_svg_data_uri']))
                                    <div class="notification-settings-qr">
                                        <img
                                            src="{{ $telegramLinkData['qr_svg_data_uri'] }}"
                                            alt="QR-код для подключения Telegram"
                                            loading="lazy"
                                        />
                                    </div>
                                @endif
                            </div>
                        </div>
                    @endif
                </section>

                <section class="notification-settings-card">
                    <h2 class="notification-settings-card-title">Настройки уведомлений</h2>
                    <p class="notification-settings-card-text">
                        Выберите каналы доставки и события, по которым хотите получать сообщения.
                    </p>

                    <form wire:submit.prevent="save" class="notification-settings-form">
                        {{ $this->form }}

                        <div class="notification-settings-footer">
                            @if ($canSelfManage)
                                <p class="notification-settings-dialog-text" style="margin:0;">
                                    Изменения применяются сразу после сохранения.
                                </p>

                                <x-filament::button type="submit" icon="heroicon-o-check">
                                    Сохранить
                                </x-filament::button>
                            @else
                                <p class="notification-settings-dialog-text" style="margin:0;">
                                    Для вашей роли настройки изменяет super-admin или market-admin.
                                </p>
                            @endif
                        </div>
                    </form>
                </section>

                <div class="notification-settings-grid">
                    @foreach ($this->helperCards() as $card)
                        <section class="notification-settings-helper">
                            <h3 class="notification-settings-helper-title">{{ $card['title'] }}</h3>
                            <p class="notification-settings-helper-text">{{ $card['body'] }}</p>
                        </section>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</x-filament-panels::page>
