{{-- resources/views/filament/resources/task-resource/pages/calendar.blade.php --}}

<x-filament-panels::page>
    <div class="grid gap-6 lg:grid-cols-12">
        <div class="lg:col-span-3">
            <x-filament::section>
                <x-slot name="heading">Фильтры</x-slot>

                <form method="GET" class="space-y-4">
                    @if(! empty($filters['date']))
                        <input type="hidden" name="date" value="{{ $filters['date'] }}" />
                    @endif

                    <div class="space-y-2">
                        <label class="text-sm font-medium text-gray-700 dark:text-gray-200">Показывать</label>
                        <input type="hidden" name="assigned" value="0">
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" name="assigned" value="1" @checked($filters['assigned']) class="rounded border-gray-300 text-primary-600">
                            Поручено мне
                        </label>
                        <input type="hidden" name="observing" value="0">
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" name="observing" value="1" @checked($filters['observing']) class="rounded border-gray-300 text-primary-600">
                            Наблюдаю
                        </label>
                        <input type="hidden" name="coexecuting" value="0">
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" name="coexecuting" value="1" @checked($filters['coexecuting']) class="rounded border-gray-300 text-primary-600">
                            Соисполняю
                        </label>
                        <input type="hidden" name="holidays" value="0">
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" name="holidays" value="1" @checked($filters['holidays']) class="rounded border-gray-300 text-primary-600">
                            Праздники рынка
                        </label>
                        <input type="hidden" name="overdue" value="0">
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" name="overdue" value="1" @checked($filters['overdue']) class="rounded border-gray-300 text-primary-600">
                            Только просроченные
                        </label>
                    </div>

                    <div class="space-y-2">
                        <label class="text-sm font-medium text-gray-700 dark:text-gray-200" for="status">Статус</label>
                        <select id="status" name="status[]" multiple class="w-full rounded-lg border border-gray-300 bg-white p-2 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900">
                            @foreach ($statusOptions as $value => $label)
                                <option value="{{ $value }}" @selected(in_array($value, $filters['statuses'], true))>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="space-y-2">
                        <label class="text-sm font-medium text-gray-700 dark:text-gray-200" for="priority">Приоритет</label>
                        <select id="priority" name="priority[]" multiple class="w-full rounded-lg border border-gray-300 bg-white p-2 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900">
                            @foreach ($priorityOptions as $value => $label)
                                <option value="{{ $value }}" @selected(in_array($value, $filters['priorities'], true))>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="space-y-2">
                        <label class="text-sm font-medium text-gray-700 dark:text-gray-200" for="search">Поиск по названию</label>
                        <input id="search" type="text" name="search" value="{{ $filters['search'] }}" class="w-full rounded-lg border border-gray-300 bg-white p-2 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900">
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <x-filament::button type="submit" color="primary" size="sm">Применить</x-filament::button>
                        <x-filament::button type="button" color="gray" size="sm" onclick="window.location='{{ url()->current() }}'">Сбросить</x-filament::button>
                    </div>
                </form>
            </x-filament::section>
        </div>

        <div class="lg:col-span-6 space-y-6">
            <x-filament::section>
                <x-slot name="heading">Календарь задач</x-slot>
                @livewire(\App\Filament\Widgets\TaskCalendarWidget::class)
            </x-filament::section>
        </div>

        <div class="lg:col-span-3 space-y-6">
            <x-filament::section>
                <x-slot name="heading">Без дедлайна</x-slot>

                @if (empty($tasksWithoutDue))
                    <div class="text-sm text-gray-500">Нет задач без дедлайна.</div>
                @else
                    <ul class="space-y-2">
                        @foreach ($tasksWithoutDue as $task)
                            <li>
                                <a
                                    href="{{ \App\Filament\Resources\TaskResource::getUrl(\App\Filament\Resources\TaskResource::canEdit($task) ? 'edit' : 'view', ['record' => $task]) }}"
                                    class="block rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-800 shadow-sm hover:bg-gray-50 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-100"
                                >
                                    <div class="font-medium">{{ $task->title }}</div>
                                    <div class="text-xs text-gray-500">{{ \App\Models\Task::STATUS_LABELS[$task->status] ?? $task->status }}</div>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-filament::section>
        </div>
    </div>

    @if ($selectedHoliday)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
            <div class="w-full max-w-lg rounded-xl bg-white p-6 shadow-lg dark:bg-gray-900">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <div class="text-sm uppercase text-purple-500">Праздник рынка</div>
                        <div class="text-xl font-semibold text-gray-900 dark:text-gray-100">🎉 {{ $selectedHoliday->title }}</div>
                    </div>
                    <a href="{{ $holidayCloseUrl }}" class="text-gray-400 hover:text-gray-600">✕</a>
                </div>

                <div class="mt-4 space-y-2 text-sm text-gray-700 dark:text-gray-300">
                    <div>
                        <span class="font-medium">Даты:</span>
                        {{ $selectedHoliday->starts_at?->toDateString() }}
                        @if ($selectedHoliday->ends_at)
                            — {{ $selectedHoliday->ends_at->toDateString() }}
                        @endif
                    </div>

                    @if ($selectedHoliday->description)
                        <div>
                            <span class="font-medium">Описание:</span>
                            {{ $selectedHoliday->description }}
                        </div>
                    @endif

                    <div>
                        <span class="font-medium">Уведомление:</span>
                        @if ($selectedHoliday->notify_before_days !== null)
                            За {{ $selectedHoliday->notify_before_days }} дн.
                        @else
                            По умолчанию рынка
                        @endif
                    </div>
                </div>

                <div class="mt-6 flex flex-wrap gap-2">
                    <x-filament::button type="button" color="gray" size="sm" onclick="window.location='{{ $holidayCloseUrl }}'">Закрыть</x-filament::button>
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
</x-filament-panels::page>
