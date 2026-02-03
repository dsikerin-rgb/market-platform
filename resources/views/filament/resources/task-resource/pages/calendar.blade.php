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
    @endphp

    <div class="task-calendar-page">
        <style>
            /* ============================================================
             * Task Calendar page (без Tailwind utilities)
             * ============================================================ */

            .task-calendar-page {
                --tc-gap: 16px;
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

            @media (min-width: 1024px) {
                .task-calendar-grid { grid-template-columns: repeat(12, minmax(0, 1fr)); }
                .task-calendar-col-filters { grid-column: span 3 / span 3; }
                .task-calendar-col-calendar { grid-column: span 6 / span 6; }
                .task-calendar-col-nodue   { grid-column: span 3 / span 3; }

                .task-calendar-sticky { position: sticky; top: 16px; }
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

            .tc-task-list { display: flex; flex-direction: column; gap: 10px; }

            .tc-task-card {
                display: block;
                padding: 10px 12px;
                border: 1px solid var(--tc-border);
                border-radius: 12px;
                background: var(--tc-bg);
                text-decoration: none;
                transition: background-color .15s ease, border-color .15s ease;
            }

            .tc-task-card:hover { background: var(--tc-bg-hover); }

            .tc-task-title {
                font-weight: 600;
                font-size: 13px;
                line-height: 1.35;
                color: inherit;
                margin: 0 0 4px 0;
            }

            .tc-task-meta { font-size: 12px; color: var(--tc-muted); line-height: 1.35; }

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
            html.dark .task-calendar-page .tc-task-card,
            html.dark .task-calendar-page .tc-modal-close {
                border-color: var(--tc-border-dark);
            }
        </style>

        <div class="task-calendar-grid">
            {{-- Filters --}}
            <div class="task-calendar-col-filters">
                <div class="task-calendar-sticky">
                    <x-filament::section>
                        <x-slot name="heading">Фильтры</x-slot>
                        <x-slot name="description">Настрой отображение задач и праздников в календаре.</x-slot>

                        <form method="GET" class="tc-form">
                            {{-- КРИТИЧНО: сохраняем режим календаря и tab при submit --}}
                            <input type="hidden" name="view" value="calendar" />
                            @if ($keepTab)
                                <input type="hidden" name="tab" value="{{ $keepTab }}" />
                            @endif

                            @if(! empty($filters['date']))
                                <input type="hidden" name="date" value="{{ $filters['date'] }}" />
                            @endif

                            <div class="tc-field">
                                <div class="tc-label">Показывать</div>

                                <div class="tc-checklist">
                                    <input type="hidden" name="assigned" value="0">
                                    <label class="tc-check">
                                        <input type="checkbox" name="assigned" value="1" @checked($filters['assigned'])>
                                        <span>Поручено мне</span>
                                    </label>

                                    <input type="hidden" name="observing" value="0">
                                    <label class="tc-check">
                                        <input type="checkbox" name="observing" value="1" @checked($filters['observing'])>
                                        <span>Наблюдаю</span>
                                    </label>

                                    <input type="hidden" name="coexecuting" value="0">
                                    <label class="tc-check">
                                        <input type="checkbox" name="coexecuting" value="1" @checked($filters['coexecuting'])>
                                        <span>Соисполняю</span>
                                    </label>

                                    <input type="hidden" name="holidays" value="0">
                                    <label class="tc-check">
                                        <input type="checkbox" name="holidays" value="1" @checked($filters['holidays'])>
                                        <span>Праздники рынка</span>
                                    </label>

                                    <input type="hidden" name="overdue" value="0">
                                    <label class="tc-check">
                                        <input type="checkbox" name="overdue" value="1" @checked($filters['overdue'])>
                                        <span>Только просроченные</span>
                                    </label>
                                </div>
                            </div>

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

                            <div class="tc-field">
                                <label class="tc-label" for="search">Поиск по названию</label>
                                <input
                                    id="search"
                                    type="text"
                                    name="search"
                                    value="{{ $filters['search'] }}"
                                    class="tc-control"
                                    placeholder="Например: холодильник, павильон 12…"
                                >
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
                        </form>
                    </x-filament::section>
                </div>
            </div>

            {{-- Calendar --}}
            <div class="task-calendar-col-calendar">
                <x-filament::section>
                    <x-slot name="heading">Календарь задач</x-slot>

                    {{-- Здесь CreateAction-модалка НЕ живёт. Она в header actions страницы ListTasks --}}
                    @livewire(\App\Filament\Widgets\TaskCalendarWidget::class)
                </x-filament::section>
            </div>

            {{-- No due --}}
            <div class="task-calendar-col-nodue">
                <x-filament::section>
                    <x-slot name="heading">Без дедлайна</x-slot>
                    <x-slot name="description">Последние задачи без даты исполнения (до 50 шт.).</x-slot>

                    @if (empty($tasksWithoutDue))
                        <div style="font-size: 13px; color: rgba(148, 163, 184, .9);">
                            Нет задач без дедлайна.
                        </div>
                    @else
                        <div class="tc-task-list">
                            @foreach ($tasksWithoutDue as $task)
                                <a
                                    href="{{ \App\Filament\Resources\TaskResource::getUrl(\App\Filament\Resources\TaskResource::canEdit($task) ? 'edit' : 'view', ['record' => $task]) }}"
                                    class="tc-task-card"
                                >
                                    <div class="tc-task-title">{{ $task->title }}</div>
                                    <div class="tc-task-meta">
                                        {{ \App\Models\Task::STATUS_LABELS[$task->status] ?? $task->status }}
                                    </div>
                                </a>
                            @endforeach
                        </div>
                    @endif
                </x-filament::section>
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
