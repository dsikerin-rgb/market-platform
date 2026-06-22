<div class="market-learning-mode" data-learning-mode hidden>
    <div class="market-learning-mode__shade" data-learning-close></div>
    <div class="market-learning-mode__spotlight" data-learning-spotlight></div>
    <section class="market-learning-mode__card" data-learning-card role="dialog" aria-live="polite" aria-modal="false">
        <div class="market-learning-mode__eyebrow" data-learning-step-label></div>
        <h2 class="market-learning-mode__title" data-learning-title></h2>
        <p class="market-learning-mode__text" data-learning-text></p>
        <div class="market-learning-mode__actions">
            <button type="button" class="market-learning-mode__button is-secondary" data-learning-prev>Назад</button>
            <button type="button" class="market-learning-mode__button is-secondary" data-learning-close>Закрыть</button>
            <button type="button" class="market-learning-mode__button is-primary" data-learning-next>Дальше</button>
        </div>
    </section>
</div>

<style>
    .market-learning-mode[hidden] {
        display: none !important;
    }

    .market-learning-mode {
        position: fixed;
        inset: 0;
        z-index: 9998;
        pointer-events: none;
    }

    .market-learning-mode__shade {
        position: absolute;
        inset: 0;
        background: rgba(15, 23, 42, 0.24);
        backdrop-filter: blur(1px);
        pointer-events: auto;
    }

    .market-learning-mode__spotlight {
        position: absolute;
        border: 2px solid rgba(14, 165, 233, 0.84);
        border-radius: 14px;
        background: rgba(240, 249, 255, 0.16);
        box-shadow:
            0 0 0 9999px rgba(15, 23, 42, 0.18),
            0 18px 42px rgba(8, 47, 73, 0.18);
        transition: left .18s ease, top .18s ease, width .18s ease, height .18s ease;
        pointer-events: none;
    }

    .market-learning-mode__card {
        position: absolute;
        width: min(22rem, calc(100vw - 2rem));
        border: 1px solid rgba(125, 211, 252, 0.58);
        border-radius: 14px;
        background: rgba(255, 255, 255, 0.9);
        box-shadow: 0 24px 60px rgba(15, 23, 42, 0.22);
        padding: 1rem;
        color: #0f172a;
        pointer-events: auto;
        transition: left .18s ease, top .18s ease;
    }

    .market-learning-mode__eyebrow {
        margin-bottom: .35rem;
        color: #0284c7;
        font-size: .76rem;
        font-weight: 700;
        letter-spacing: .02em;
        text-transform: uppercase;
    }

    .market-learning-mode__title {
        margin: 0;
        color: #0f172a;
        font-size: 1rem;
        font-weight: 800;
        line-height: 1.25;
    }

    .market-learning-mode__text {
        margin: .55rem 0 0;
        color: #334155;
        font-size: .92rem;
        line-height: 1.45;
    }

    .market-learning-mode__actions {
        display: flex;
        flex-wrap: wrap;
        justify-content: flex-end;
        gap: .5rem;
        margin-top: .9rem;
    }

    .market-learning-mode__button {
        min-height: 2.1rem;
        border-radius: 999px;
        padding: .35rem .75rem;
        font-size: .84rem;
        font-weight: 700;
    }

    .market-learning-mode__button.is-secondary {
        border: 1px solid rgba(148, 163, 184, 0.55);
        background: rgba(248, 250, 252, 0.92);
        color: #334155;
    }

    .market-learning-mode__button.is-primary {
        border: 1px solid #0284c7;
        background: #0ea5e9;
        color: #ffffff;
    }

    .market-learning-mode-toggle.is-active {
        background: #0ea5e9 !important;
        border-color: #0284c7 !important;
        color: #ffffff !important;
    }

    @media (max-width: 640px) {
        .market-learning-mode__card {
            left: 1rem !important;
            right: 1rem;
            width: auto;
        }
    }
</style>

<script>
    (() => {
        const STORAGE_KEY = 'marketLearningMode.enabled';
        const STEP_KEY = 'marketLearningMode.step';

        const steps = [
            {
                selector: '[data-learning-target="learning-toggle"]',
                title: 'Режим обучения',
                text: 'Эта кнопка включает подсказки по экрану. Можно пройти шаги по порядку или закрыть обучение в любой момент.',
            },
            {
                selector: '[data-learning-target="map-link"]',
                title: 'Карта рынка',
                text: 'Здесь открывается схема рынка: места, арендаторы, свободные площади и рабочие действия по карте.',
            },
            {
                selector: '[data-learning-target="marketplace-link"]',
                title: 'Маркетплейс',
                text: 'Эта кнопка открывает витрину для покупателей. Там видны товары, объявления и страницы арендаторов.',
            },
            {
                selector: '.fi-global-search, [data-global-search], input[type="search"]',
                title: 'Быстрый поиск',
                text: 'Поиск помогает быстро найти арендатора, место, договор, задачу или другой раздел без переходов по меню.',
            },
            {
                selector: '.fi-sidebar, .fi-sidebar-nav, .fi-topbar-open-sidebar-btn',
                title: 'Меню разделов',
                text: 'В боковом меню собраны основные разделы сервиса. Если места мало, меню можно свернуть и открыть снова.',
            },
            {
                selector: '.fi-header, .fi-page-header, h1',
                title: 'Заголовок страницы',
                text: 'Здесь видно, в каком разделе вы сейчас находитесь. Рядом часто появляются основные действия по текущей странице.',
            },
            {
                selector: '.dashboard-workspace__hero, .fi-wi-stats-overview',
                title: 'Главные показатели',
                text: 'На дашборде здесь собраны ключевые цифры рынка: площадь, занятость, начисления, оплаты и задолженность.',
            },
            {
                selector: '.dashboard-workspace__panel, .fi-wi-widget',
                title: 'Рабочие блоки',
                text: 'Каждый блок отвечает за отдельную часть работы: финансы, места, обращения, задачи или события.',
            },
            {
                selector: '[data-ai-agent-launcher], .ai-agent-floating-button, [data-learning-target="ai-agent"]',
                title: 'ИИ-консультант',
                text: 'ИИ-консультант помогает найти данные, подготовить ссылку, создать задачу или подсказать действие по странице.',
            },
        ];

        const ready = (callback) => {
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', callback, { once: true });
                return;
            }

            callback();
        };

        ready(() => {
            const root = document.querySelector('[data-learning-mode]');
            const card = root?.querySelector('[data-learning-card]');
            const spotlight = root?.querySelector('[data-learning-spotlight]');
            const title = root?.querySelector('[data-learning-title]');
            const text = root?.querySelector('[data-learning-text]');
            const stepLabel = root?.querySelector('[data-learning-step-label]');
            const next = root?.querySelector('[data-learning-next]');
            const prev = root?.querySelector('[data-learning-prev]');
            const toggles = Array.from(document.querySelectorAll('[data-learning-mode-toggle]'));

            if (! root || ! card || ! spotlight || ! title || ! text || ! stepLabel || ! next || ! prev) {
                return;
            }

            let activeSteps = [];
            let current = 0;
            let enabled = false;

            const readStorage = (key, fallback = null) => {
                try {
                    return window.localStorage.getItem(key) ?? fallback;
                } catch (error) {
                    return fallback;
                }
            };

            const writeStorage = (key, value) => {
                try {
                    window.localStorage.setItem(key, value);
                } catch (error) {
                    // Ignore storage restrictions; learning mode still works for the current page.
                }
            };

            const findTarget = (step) => document.querySelector(step.selector);

            const collectSteps = () => steps
                .map((step) => ({ ...step, target: findTarget(step) }))
                .filter((step) => step.target);

            const setToggleState = () => {
                toggles.forEach((toggle) => {
                    toggle.classList.toggle('is-active', enabled);
                    toggle.setAttribute('aria-pressed', enabled ? 'true' : 'false');
                    toggle.textContent = enabled ? 'Скрыть обучение' : 'Обучение';
                });
            };

            const saveState = () => {
                writeStorage(STORAGE_KEY, enabled ? '1' : '0');
                writeStorage(STEP_KEY, String(current));
            };

            const placeCard = (rect) => {
                const gap = 14;
                const viewportWidth = window.innerWidth;
                const viewportHeight = window.innerHeight;
                const cardWidth = Math.min(352, viewportWidth - 32);
                const cardHeight = card.offsetHeight || 180;
                let left = rect.right + gap;
                let top = rect.top;

                if (left + cardWidth > viewportWidth - 16) {
                    left = rect.left - cardWidth - gap;
                }

                if (left < 16) {
                    left = Math.min(Math.max(rect.left, 16), viewportWidth - cardWidth - 16);
                    top = rect.bottom + gap;
                }

                if (top + cardHeight > viewportHeight - 16) {
                    top = rect.top - cardHeight - gap;
                }

                top = Math.max(16, Math.min(top, viewportHeight - cardHeight - 16));

                card.style.left = `${left}px`;
                card.style.top = `${top}px`;
            };

            const render = () => {
                if (! enabled) {
                    root.hidden = true;
                    setToggleState();
                    saveState();
                    return;
                }

                activeSteps = collectSteps();

                if (activeSteps.length === 0) {
                    enabled = false;
                    render();
                    return;
                }

                current = Math.max(0, Math.min(current, activeSteps.length - 1));

                const step = activeSteps[current];
                const rect = step.target.getBoundingClientRect();
                const padding = 8;

                root.hidden = false;
                title.textContent = step.title;
                text.textContent = step.text;
                stepLabel.textContent = `Шаг ${current + 1} из ${activeSteps.length}`;
                prev.disabled = current === 0;
                next.textContent = current === activeSteps.length - 1 ? 'Завершить' : 'Дальше';

                spotlight.style.left = `${Math.max(rect.left - padding, 8)}px`;
                spotlight.style.top = `${Math.max(rect.top - padding, 8)}px`;
                spotlight.style.width = `${Math.min(rect.width + padding * 2, window.innerWidth - 16)}px`;
                spotlight.style.height = `${Math.min(rect.height + padding * 2, window.innerHeight - 16)}px`;

                placeCard(rect);
                setToggleState();
                saveState();
            };

            const open = () => {
                enabled = true;
                activeSteps = collectSteps();
                current = Math.max(0, Math.min(Number(readStorage(STEP_KEY, '0')), activeSteps.length - 1));
                render();
            };

            const close = () => {
                enabled = false;
                render();
            };

            toggles.forEach((toggle) => {
                toggle.addEventListener('click', () => {
                    enabled ? close() : open();
                });
            });

            root.querySelectorAll('[data-learning-close]').forEach((button) => {
                button.addEventListener('click', close);
            });

            next.addEventListener('click', () => {
                if (current >= activeSteps.length - 1) {
                    close();
                    return;
                }

                current += 1;
                render();
            });

            prev.addEventListener('click', () => {
                current = Math.max(0, current - 1);
                render();
            });

            window.addEventListener('resize', () => {
                if (enabled) {
                    render();
                }
            });

            window.addEventListener('scroll', () => {
                if (enabled) {
                    render();
                }
            }, true);

            enabled = readStorage(STORAGE_KEY) === '1';

            if (enabled) {
                open();
            } else {
                setToggleState();
            }
        });
    })();
</script>
