{{-- resources/views/filament/resources/task-resource/pages/calendar.blade.php --}}

<x-filament-panels::page>
    @php
        /**
         * ВАЖНО:
         * 1) Эта страница рендерится внутри ListTasks (а не отдельной Page),
         *    поэтому "Создать" живёт в header actions ListTasks.
         * 2) Наша задача здесь — не ронять view=calendar и tab при submit/сбросе фильтров.
         */

        $keepView = request()->query('view', 'calendar');
        $keepTab  = request()->query('tab');

        // URL для "Сбросить": остаёмся в календаре и сохраняем tab (если есть).
        $resetQuery = array_filter([
            'view' => 'calendar',
            'tab'  => $keepTab,
        ], fn ($v) => filled($v));

        $resetUrl = url()->current() . (count($resetQuery) ? ('?' . http_build_query($resetQuery)) : '');

        // Подсказка: если каким-то образом сюда попали без view=calendar — всё равно форсируем.
        $keepView = $keepView === 'calendar' ? 'calendar' : 'calendar';
        $taskCreateUrl = \App\Filament\Resources\TaskResource::canCreate()
            ? \App\Filament\Resources\TaskResource::getUrl('create')
            : null;
        $eventCreateUrl = \App\Filament\Resources\MarketHolidayResource::canCreate()
            ? \App\Filament\Resources\MarketHolidayResource::getUrl('create')
            : null;
    @endphp

    <div
        class="task-calendar-page"
        x-data="{
            pickerOpen: false,
            pickedLabel: '',
            taskCreateBase: @js($taskCreateUrl),
            eventCreateBase: @js($eventCreateUrl),
            taskCreateHref: '#',
            eventCreateHref: '#',
            buildUrl(base, params) {
                if (! base) {
                    return '#';
                }

                const url = new URL(base, window.location.origin);

                Object.entries(params).forEach(([key, value]) => {
                    if (value) {
                        url.searchParams.set(key, value);
                    }
                });

                return url.toString();
            },
            openCreatePicker(detail) {
                const date = detail?.date ?? '';

                if (! date || (! this.taskCreateBase && ! this.eventCreateBase)) {
                    return;
                }

                this.pickedLabel = detail?.label ?? date;
                this.taskCreateHref = this.buildUrl(this.taskCreateBase, {
                    due_at: detail?.dueAt ?? '',
                    date: date,
                });
                this.eventCreateHref = this.buildUrl(this.eventCreateBase, {
                    date: date,
                });
                this.pickerOpen = true;
            },
            closeCreatePicker() {
                this.pickerOpen = false;
            },
        }"
        x-on:task-calendar-date-picked.window="openCreatePicker($event.detail)"
        x-on:keydown.escape.window="closeCreatePicker()"
    >
        <style>
            /* ============================================================
             * Task Calendar page (без Tailwind utilities)
             * ============================================================ */

            .task-calendar-page {
                --tc-gap: 12px;
                --tc-radius: 14px;
                --tc-border: rgba(148, 163, 184, .20);
                --tc-border-dark: rgba(148, 163, 184, .14);
                --tc-bg: rgba(255, 255, 255, .04);
                --tc-bg-hover: rgba(255, 255, 255, .07);
                --tc-muted: rgba(148, 163, 184, .85);
            }

            .task-calendar-grid {
                display: grid;
                gap: var(--tc-gap);
                grid-template-columns: 1fr;
                align-items: start;
            }

            .tc-filter-bar {
                display: grid;
                gap: 8px;
            }

            .tc-toolbar-form {
                display: grid;
                gap: 8px;
            }

            .tc-primary-tabs {
                width: max-content;
                max-width: 100%;
                overflow-x: auto;
            }

            .tc-primary-tabs .fi-tabs {
                width: max-content;
                max-width: 100%;
                margin-inline: 0;
            }

            .tc-toolbar-row {
                display: flex;
                flex-wrap: wrap;
                align-items: center;
                justify-content: flex-start;
                gap: 10px;
            }

            .tc-toggle-row {
                display: flex;
                flex-wrap: wrap;
                gap: 6px;
                min-width: 0;
            }

            .tc-toolbar-side {
                display: flex;
                flex-wrap: wrap;
                align-items: center;
                gap: 6px;
                margin-left: auto;
            }

            .tc-toggle {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                min-height: 36px;
                padding: 6px 10px;
                border: 1px solid var(--tc-border);
                border-radius: 999px;
                background: rgba(255, 255, 255, .02);
                cursor: pointer;
                user-select: none;
                transition: background-color .15s ease, border-color .15s ease, box-shadow .15s ease;
                font-size: 13px;
                font-weight: 500;
            }

            .tc-toggle:hover {
                background: var(--tc-bg-hover);
            }

            .tc-toggle input[type="checkbox"] {
                width: 16px;
                height: 16px;
                margin: 0;
            }

            .tc-advanced {
                position: relative;
            }

            .tc-advanced-summary {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                min-height: 36px;
                padding: 6px 10px;
                border: 1px solid var(--tc-border);
                border-radius: 999px;
                background: rgba(255, 255, 255, .02);
                cursor: pointer;
                list-style: none;
                font-size: 13px;
                font-weight: 600;
            }

            .tc-advanced-summary::after {
                content: '▾';
                font-size: 12px;
                color: var(--tc-muted);
                transition: transform .15s ease;
            }

            .tc-advanced.is-open .tc-advanced-summary::after {
                transform: rotate(180deg);
            }

            .tc-advanced-panel {
                position: absolute;
                top: calc(100% + 8px);
                right: 0;
                z-index: 30;
                width: min(44rem, 92vw);
            }

            .tc-advanced-body {
                display: grid;
                gap: 14px;
                padding: 14px;
                border: 1px solid var(--tc-border);
                border-radius: 14px;
                background: rgba(255, 255, 255, .98);
                box-shadow: 0 20px 40px rgba(15, 23, 42, .10);
                backdrop-filter: blur(8px);
            }

            .tc-advanced-grid {
                display: grid;
                gap: 14px;
            }

            @media (min-width: 768px) {
                .tc-advanced-grid {
                    grid-template-columns: repeat(2, minmax(0, 1fr));
                    align-items: start;
                }
            }

            .tc-form { display: flex; flex-direction: column; gap: 14px; }
            .tc-field { display: flex; flex-direction: column; gap: 8px; }

            .tc-label {
                font-size: 13px;
                font-weight: 600;
                letter-spacing: .01em;
                color: inherit;
            }

            .tc-help { font-size: 12px; line-height: 1.35; color: var(--tc-muted); }

            .tc-control,
            .tc-control-multi {
                width: 100%;
                border: 1px solid var(--tc-border);
                border-radius: 12px;
                padding: 10px 12px;
                background: var(--tc-bg);
                color: inherit;
                outline: none;
            }

            .tc-control:focus,
            .tc-control-multi:focus {
                box-shadow: 0 0 0 3px rgba(245, 158, 11, .20);
                border-color: rgba(245, 158, 11, .55);
            }

            .tc-control-multi { min-height: 140px; padding: 8px 10px; }

            .tc-checklist { display: grid; grid-template-columns: 1fr; gap: 8px; }

            .tc-check {
                display: flex;
                align-items: center;
                gap: 10px;
                padding: 10px 12px;
                border: 1px solid var(--tc-border);
                border-radius: 12px;
                background: var(--tc-bg);
                cursor: pointer;
                user-select: none;
                transition: background-color .15s ease, border-color .15s ease;
            }

            .tc-check:hover { background: var(--tc-bg-hover); }

            .tc-check input[type="checkbox"] { width: 16px; height: 16px; margin: 0; }

            .tc-actions { display: flex; flex-wrap: wrap; gap: 10px; padding-top: 2px; }

            .tc-section-divider {
                width: 100%;
                height: 1px;
                margin: 2px 0;
                background: var(--tc-border);
            }

            /* ============================================================
             * FullCalendar cosmetics (чтобы не выглядел “чужеродно”)
             * ============================================================ */
            .task-calendar-page .fc {
                --fc-border-color: var(--tc-border);
                --fc-page-bg-color: transparent;
                --fc-neutral-bg-color: rgba(255, 255, 255, .04);
                --fc-today-bg-color: rgba(245, 158, 11, .10);
                font-size: 13px;
            }

            .task-calendar-page .fc .fc-toolbar {
                gap: 12px;
                flex-wrap: wrap;
                margin-bottom: 12px;
            }

            .task-calendar-page .fc .fc-toolbar-title {
                font-size: 16px;
                font-weight: 700;
                line-height: 1.2;
            }

            .task-calendar-page .fc .fc-button {
                border-radius: 10px;
                padding: 6px 10px;
                background: var(--tc-bg);
                border: 1px solid var(--tc-border);
                color: inherit;
                text-transform: none;
                box-shadow: none;
            }

            .task-calendar-page .fc .fc-button:hover { background: var(--tc-bg-hover); }

            .task-calendar-page .fc .fc-button-primary:not(:disabled).fc-button-active {
                box-shadow: 0 0 0 3px rgba(245, 158, 11, .18);
                border-color: rgba(245, 158, 11, .55);
            }

            .task-calendar-page .fc .fc-scrollgrid,
            .task-calendar-page .fc .fc-scrollgrid table {
                border-radius: 12px;
                overflow: hidden;
            }

            .task-calendar-page .fc .fc-event {
                border: none;
                border-radius: 8px;
                padding: 1px 6px;
            }

            /* Holiday modal */
            .tc-modal-backdrop {
                position: fixed;
                inset: 0;
                z-index: 80;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 16px;
                background: rgba(0, 0, 0, .45);
            }

            .tc-modal {
                width: 100%;
                max-width: 680px;
                border-radius: 16px;
                border: 1px solid var(--tc-border);
                background: rgba(17, 24, 39, .92);
                backdrop-filter: blur(6px);
                padding: 18px 18px 16px;
                box-shadow: 0 20px 60px rgba(0, 0, 0, .55);
            }

            .tc-modal-head {
                display: flex;
                align-items: flex-start;
                justify-content: space-between;
                gap: 12px;
            }

            .tc-modal-kicker {
                font-size: 12px;
                text-transform: uppercase;
                letter-spacing: .06em;
                color: #a78bfa;
                margin-bottom: 6px;
            }

            .tc-modal-title {
                font-size: 18px;
                font-weight: 700;
                line-height: 1.3;
                margin: 0;
            }

            .tc-modal-close {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                width: 34px;
                height: 34px;
                border-radius: 10px;
                border: 1px solid var(--tc-border);
                background: var(--tc-bg);
                color: inherit;
                text-decoration: none;
                transition: background-color .15s ease;
            }

            .tc-modal-close:hover { background: var(--tc-bg-hover); }

            .tc-modal-body {
                margin-top: 14px;
                display: grid;
                gap: 8px;
                font-size: 13px;
                color: rgba(226, 232, 240, .92);
            }

            .tc-create-picker-backdrop {
                position: fixed;
                inset: 0;
                z-index: 70;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 16px;
                background: rgba(15, 23, 42, .20);
            }

            .tc-create-picker {
                width: 100%;
                max-width: 360px;
                display: grid;
                gap: 14px;
                padding: 16px;
                border: 1px solid var(--tc-border);
                border-radius: 16px;
                background: rgba(255, 255, 255, .98);
                box-shadow: 0 24px 48px rgba(15, 23, 42, .16);
                backdrop-filter: blur(10px);
            }

            .tc-create-picker-title {
                margin: 0;
                font-size: 16px;
                font-weight: 700;
                line-height: 1.3;
            }

            .tc-create-picker-text {
                font-size: 13px;
                line-height: 1.4;
                color: var(--tc-muted);
            }

            .tc-create-picker-actions {
                display: grid;
                gap: 10px;
            }

            .tc-modal-row strong { font-weight: 600; color: rgba(226, 232, 240, 1); }

            .tc-modal-actions {
                margin-top: 16px;
                display: flex;
                flex-wrap: wrap;
                gap: 10px;
            }

            html.dark .task-calendar-page .tc-control,
            html.dark .task-calendar-page .tc-control-multi,
            html.dark .task-calendar-page .tc-check,
            html.dark .task-calendar-page .tc-modal-close {
                border-color: var(--tc-border-dark);
            }

            html.dark .task-calendar-page .tc-advanced-body {
                background: rgba(15, 23, 42, .96);
                box-shadow: 0 20px 40px rgba(2, 6, 23, .32);
            }

            @media (max-width: 767px) {
                .tc-toolbar-side {
                    margin-left: 0;
                }

                .tc-toolbar-row {
                    align-items: stretch;
                }

                .tc-advanced-panel {
                    left: 0;
                    right: auto;
                    width: min(100vw - 2rem, 32rem);
                }
            }
        </style>

        <div class="task-calendar-grid">
            <div class="tc-filter-bar">
                <div class="tc-primary-tabs">
                    <x-filament::tabs>
                        @foreach ($calendarTabs as $tab)
                            <x-filament::tabs.item
                                :active="$tab['active']"
                                :href="$tab['url']"
                                tag="a"
                            >
                                {{ $tab['label'] }}
                            </x-filament::tabs.item>
                        @endforeach
                    </x-filament::tabs>
                </div>

                <form method="GET" class="tc-toolbar-form">
                    <input type="hidden" name="view" value="calendar" />
                    @if ($keepTab)
                        <input type="hidden" name="tab" value="{{ $keepTab }}" />
                    @endif

                    @if (! empty($filters['date']))
                        <input type="hidden" name="date" value="{{ $filters['date'] }}" />
                    @endif

                    <div class="tc-toolbar-row">
                        <div class="tc-toggle-row">
                            <input type="hidden" name="holidays" value="0">
                            <label class="tc-toggle">
                                <input type="checkbox" name="holidays" value="1" @checked($filters['holidays']) onchange="this.form.submit()">
                                <span>Праздники рынка</span>
                            </label>

                            <input type="hidden" name="promotions" value="0">
                            <label class="tc-toggle">
                                <input type="checkbox" name="promotions" value="1" @checked($filters['promotions'] ?? true) onchange="this.form.submit()">
                                <span>Акции рынка</span>
                            </label>
                        </div>

                        <div class="tc-toolbar-side">
                            <div
                                class="tc-advanced"
                                x-data="{ open: {{ ! empty($filters['statuses']) || ! empty($filters['priorities']) ? 'true' : 'false' }} }"
                                x-on:click.outside="open = false"
                                x-on:keydown.escape.window="open = false"
                                :class="{ 'is-open': open }"
                            >
                                <button
                                    type="button"
                                    class="tc-advanced-summary"
                                    x-on:click="open = !open"
                                    x-bind:aria-expanded="open ? 'true' : 'false'"
                                >
                                    <span>Дополнительно</span>
                                </button>

                                <div class="tc-advanced-panel" x-show="open" x-cloak>
                                    <div class="tc-advanced-body">
                                    <div class="tc-advanced-grid">
                                        <div class="tc-field">
                                            <label class="tc-label" for="status">Статус</label>
                                            <select id="status" name="status[]" multiple class="tc-control-multi">
                                                @foreach ($statusOptions as $value => $label)
                                                    <option value="{{ $value }}" @selected(in_array($value, $filters['statuses'], true))>
                                                        {{ $label }}
                                                    </option>
                                                @endforeach
                                            </select>
                                            <div class="tc-help">Можно выбрать несколько значений.</div>
                                        </div>

                                        <div class="tc-field">
                                            <label class="tc-label" for="priority">Приоритет</label>
                                            <select id="priority" name="priority[]" multiple class="tc-control-multi">
                                                @foreach ($priorityOptions as $value => $label)
                                                    <option value="{{ $value }}" @selected(in_array($value, $filters['priorities'], true))>
                                                        {{ $label }}
                                                    </option>
                                                @endforeach
                                            </select>
                                            <div class="tc-help">Можно выбрать несколько значений.</div>
                                        </div>
                                    </div>

                                    <div class="tc-actions">
                                        <x-filament::button type="submit" color="primary" size="sm">
                                            Применить
                                        </x-filament::button>

                                        <x-filament::button
                                            type="button"
                                            color="gray"
                                            size="sm"
                                            onclick="window.location='{{ $resetUrl }}'"
                                        >
                                            Сбросить
                                        </x-filament::button>
                                    </div>
                                </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>

            <x-filament::section>
                <x-slot name="heading">Календарь задач</x-slot>

                {{-- Здесь CreateAction-модалка НЕ живёт. Она в header actions страницы ListTasks --}}
                @livewire(\App\Filament\Widgets\TaskCalendarWidget::class)
            </x-filament::section>
        </div>

        <div
            class="tc-create-picker-backdrop"
            x-show="pickerOpen"
            x-cloak
            x-on:click.self="closeCreatePicker()"
        >
            <div class="tc-create-picker">
                <div>
                    <h3 class="tc-create-picker-title">Создать на дату</h3>
                    <div class="tc-create-picker-text" x-text="pickedLabel"></div>
                </div>

                <div class="tc-create-picker-actions">
                    @if ($taskCreateUrl)
                        <x-filament::button
                            tag="a"
                            color="primary"
                            size="sm"
                            x-bind:href="taskCreateHref"
                        >
                            Создать задачу
                        </x-filament::button>
                    @endif

                    @if ($eventCreateUrl)
                        <x-filament::button
                            tag="a"
                            color="gray"
                            size="sm"
                            x-bind:href="eventCreateHref"
                        >
                            Создать событие
                        </x-filament::button>
                    @endif

                    <x-filament::button
                        type="button"
                        color="gray"
                        size="sm"
                        x-on:click="closeCreatePicker()"
                    >
                        Отмена
                    </x-filament::button>
                </div>
            </div>
        </div>

        {{-- Holiday modal --}}
        @if ($selectedHoliday)
            <div class="tc-modal-backdrop" role="dialog" aria-modal="true">
                <div class="tc-modal">
                    <div class="tc-modal-head">
                        <div>
                            <div class="tc-modal-kicker">Праздник рынка</div>
                            <h3 class="tc-modal-title">🎉 {{ $selectedHoliday->title }}</h3>
                        </div>

                        <a href="{{ $holidayCloseUrl }}" class="tc-modal-close" aria-label="Закрыть">✕</a>
                    </div>

                    <div class="tc-modal-body">
                        <div class="tc-modal-row">
                            <strong>Даты:</strong>
                            {{ $selectedHoliday->starts_at?->toDateString() }}
                            @if ($selectedHoliday->ends_at)
                                — {{ $selectedHoliday->ends_at->toDateString() }}
                            @endif
                        </div>

                        @if ($selectedHoliday->description)
                            <div class="tc-modal-row">
                                <strong>Описание:</strong>
                                {{ $selectedHoliday->description }}
                            </div>
                        @endif

                        <div class="tc-modal-row">
                            <strong>Уведомление:</strong>
                            @if ($selectedHoliday->notify_before_days !== null)
                                За {{ $selectedHoliday->notify_before_days }} дн.
                            @else
                                По умолчанию рынка
                            @endif
                        </div>
                    </div>

                    <div class="tc-modal-actions">
                        <x-filament::button
                            type="button"
                            color="gray"
                            size="sm"
                            onclick="window.location='{{ $holidayCloseUrl }}'"
                        >
                            Закрыть
                        </x-filament::button>

                        @if ($canEditHoliday)
                            <x-filament::button
                                type="button"
                                color="primary"
                                size="sm"
                                onclick="window.location='{{ \App\Filament\Resources\MarketHolidayResource::getUrl('edit', ['record' => $selectedHoliday]) }}'"
                            >
                                Редактировать
                            </x-filament::button>
                        @endif
                    </div>
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
