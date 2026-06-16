<div>
    @if ($shouldShow)
        <style>
            .first-login-welcome {
                position: fixed;
                inset: 0;
                z-index: 9998;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 24px 16px;
                background: rgba(15, 23, 42, 0.48);
                backdrop-filter: blur(4px);
            }

            .first-login-welcome__dialog {
                width: min(100%, 600px);
                border: 1px solid rgba(148, 163, 184, 0.35);
                border-radius: 18px;
                background: #fff;
                box-shadow: 0 24px 70px rgba(15, 23, 42, 0.28);
                padding: 28px;
            }

            .first-login-welcome__header {
                display: flex;
                align-items: flex-start;
                gap: 16px;
                margin-bottom: 18px;
            }

            .first-login-welcome__icon {
                flex: 0 0 44px;
                width: 44px;
                height: 44px;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                border-radius: 999px;
                background: #e0f2fe;
                color: #0369a1;
            }

            .first-login-welcome__icon svg {
                width: 24px;
                height: 24px;
                display: block;
            }

            .first-login-welcome__title {
                margin: 0;
                color: #0f172a;
                font-size: 22px;
                font-weight: 700;
                line-height: 1.25;
            }

            .first-login-welcome__lead {
                margin: 8px 0 0;
                color: #475569;
                font-size: 15px;
                line-height: 1.6;
            }

            .first-login-welcome__note {
                border: 1px solid #bae6fd;
                border-radius: 14px;
                background: #f0f9ff;
                color: #334155;
                font-size: 15px;
                line-height: 1.6;
                padding: 14px 16px;
            }

            .first-login-welcome__actions {
                display: flex;
                justify-content: flex-end;
                margin-top: 22px;
            }

            .first-login-welcome__button {
                border: 0;
                border-radius: 12px;
                background: #0ea5e9;
                color: #fff;
                cursor: pointer;
                font-size: 15px;
                font-weight: 700;
                line-height: 1;
                min-height: 42px;
                padding: 0 18px;
                box-shadow: 0 12px 24px rgba(14, 165, 233, 0.24);
            }

            .first-login-welcome__button:hover {
                background: #0284c7;
            }

            .first-login-welcome__button:disabled {
                cursor: wait;
                opacity: 0.7;
            }

            @media (max-width: 640px) {
                .first-login-welcome {
                    align-items: flex-end;
                    padding: 16px;
                }

                .first-login-welcome__dialog {
                    padding: 22px;
                }

                .first-login-welcome__header {
                    gap: 12px;
                }

                .first-login-welcome__title {
                    font-size: 20px;
                }
            }
        </style>

        <div
            class="first-login-welcome"
            role="dialog"
            aria-modal="true"
            aria-labelledby="first-login-welcome-title"
            wire:key="first-login-welcome-modal"
        >
            <div class="first-login-welcome__dialog">
                <div class="first-login-welcome__header">
                    <div class="first-login-welcome__icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6l4 2" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                        </svg>
                    </div>

                    <div>
                        <h2 id="first-login-welcome-title" class="first-login-welcome__title">
                            Добро пожаловать в сервис
                        </h2>
                        <p class="first-login-welcome__lead">
                            Сейчас сервис работает в тестовом режиме. Мы постепенно дорабатываем сценарии, интерфейсы и уведомления, поэтому отдельные функции могут меняться или работать неидеально.
                        </p>
                    </div>
                </div>

                <div class="first-login-welcome__note">
                    Если заметите ошибку, неточность в данных или появится предложение по улучшению, пожалуйста, отправьте сообщение пользователю <strong>super-admin</strong> внутри сервиса. Так мы быстрее увидим обратную связь и сможем исправить проблему.
                </div>

                <div class="first-login-welcome__actions">
                    <button
                        type="button"
                        wire:click="acknowledge"
                        wire:loading.attr="disabled"
                        class="first-login-welcome__button"
                    >
                        Понятно
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
