<?php

# app/Filament/Resources/TaskResource/Pages/ListTasks.php

declare(strict_types=1);

namespace App\Filament\Resources\TaskResource\Pages;

use App\Filament\Resources\TaskResource;
use App\Models\MarketHoliday;
use App\Models\Task;
use App\Support\TaskCalendarFilters;
use Filament\Actions;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;

class ListTasks extends ListRecords
{
    protected static string $resource = TaskResource::class;

    protected static ?string $title = 'Задачи';

    /**
     * tab=... (вместо activeTab=...), и view=list|calendar.
     * except убирает дефолт из URL.
     */
    protected array $queryString = [
        'activeTab' => ['as' => 'tab', 'except' => 'all'],
        'viewMode'  => ['as' => 'view', 'except' => 'list'],
    ];

    /** ✅ По умолчанию — «Все». */
    public ?string $activeTab = 'all';

    /** ✅ По умолчанию — список. list|calendar */
    public string $viewMode = 'list';

    public function mount(): void
    {
        parent::mount();

        if (! in_array($this->viewMode, ['list', 'calendar'], true)) {
            $this->viewMode = 'list';
        }
    }

    public function getTitle(): string
    {
        return $this->viewMode === 'calendar' ? 'Календарь задач' : 'Задачи';
    }

    public function getBreadcrumb(): string
    {
        return $this->getTitle();
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'all';
    }

    /**
     * В режиме calendar рендерим calendar.blade.php, оставаясь на /admin/tasks
     * (главное: CreateAction остаётся в header actions этой страницы).
     */
    public function getView(): string
    {
        if ($this->viewMode === 'calendar') {
            return 'filament.resources.task-resource.pages.calendar';
        }

        return parent::getView();
    }

    protected function getViewData(): array
    {
        $data = parent::getViewData();

        if ($this->viewMode !== 'calendar') {
            return $data;
        }

        // Данные, которые ожидает calendar.blade.php
        return array_merge($data, $this->getCalendarViewData());
    }

    /**
     * Табы сверху (то, что ты назвал "фильтрами" на /admin/tasks).
     * Это фильтры СПИСКА. Мы их не ломаем и не переиспользуем как календарные.
     */
    public function getTabs(): array
    {
        $tabClass = static::resolveTabClass();

        $user = Filament::auth()->user();

        if (! $user) {
            return [
                'all' => $tabClass::make('Все'),
            ];
        }

        $myId = (int) $user->id;

        return [
            'all' => $tabClass::make('Все'),

            'in_progress' => $this->makeTab(
                $tabClass,
                'В работе',
                fn (Builder $query) => $query->inWork()->workOrder()
            ),

            'my' => $this->makeTab(
                $tabClass,
                'Мне назначено',
                fn (Builder $query) => $query->assignedTo($myId)->workOrder()
            ),

            'coexecuting' => $this->makeTab(
                $tabClass,
                'Соисполняю',
                fn (Builder $query) => $query->coexecuting($myId)->workOrder()
            ),

            'observing' => $this->makeTab(
                $tabClass,
                'Наблюдаю',
                fn (Builder $query) => $query->watching($myId)->workOrder()
            ),

            'overdue' => $this->makeTab(
                $tabClass,
                'Просроченные',
                fn (Builder $query) => $query->overdue()->workOrder()
            ),

            'unassigned' => $this->makeTab(
                $tabClass,
                'Без исполнителя',
                fn (Builder $query) => $query->unassigned()->workOrder()
            ),

            'urgent' => $this->makeTab(
                $tabClass,
                'Критичные',
                fn (Builder $query) => $query->urgent()->workOrder()
            ),
        ];
    }

    /**
     * ✅ CreateAction остаётся тут — это Filament CreateAction modal.
     * ✅ Кнопки "Список" и "Календарь" показываем ВСЕГДА.
     * ✅ Переключение делаем на /admin/tasks?view=calendar, сохраняя tab=...
     */
    protected function getHeaderActions(): array
    {
        $actions = [
            Actions\CreateAction::make()
                ->label('Создать')
                ->icon('heroicon-o-plus'),
        ];

        if (! class_exists(Actions\Action::class)) {
            return $actions;
        }

        $actions[] = Actions\Action::make('view_list')
            ->label('Список')
            ->icon('heroicon-o-list-bullet')
            ->url(fn (): string => $this->urlForView('list'))
            ->color($this->viewMode === 'list' ? 'primary' : 'gray');

        $actions[] = Actions\Action::make('view_calendar')
            ->label('Календарь')
            ->icon('heroicon-o-calendar-days')
            ->url(fn (): string => $this->urlForView('calendar'))
            ->color($this->viewMode === 'calendar' ? 'primary' : 'gray');

        return $actions;
    }

    /**
     * URL переключения вида.
     * - Сохраняем tab=... (и вообще все "не календарные" параметры).
     * - Для list чистим календарные GET-фильтры, чтобы не тянуть мусор.
     */
    private function urlForView(string $mode): string
    {
        $query = request()->query();

        // пагинация таблицы календарю не нужна; и наоборот
        unset($query['page']);

        // Календарные фильтры (это НЕ табы). Их чистим при возврате в список.
        $calendarKeys = [
            'assigned',
            'observing',
            'coexecuting',
            'holidays',
            'overdue',    // календарный чекбокс "Только просроченные" — НЕ таб overdue
            'status',
            'priority',
            'search',
            'date',
            'holiday_id',
        ];

        if ($mode === 'list') {
            unset($query['view']);
            foreach ($calendarKeys as $k) {
                unset($query[$k]);
            }
        } else {
            $query['view'] = 'calendar';
        }

        // База — URL списка задач.
        $base = TaskResource::getUrl('index');

        return count($query) ? ($base . '?' . http_build_query($query)) : $base;
    }

    /**
     * Данные для calendar.blade.php
     */
    private function getCalendarViewData(): array
    {
        $user = Filament::auth()->user();
        $filters = TaskCalendarFilters::fromRequest();

        $tasksWithoutDue = [];

        if ($user) {
            $query = TaskCalendarFilters::applyToTaskQuery(
                TaskResource::getEloquentQuery(),
                $filters,
                $user,
            )
                ->whereNull('due_at')
                ->orderByDesc('created_at');

            // Если включено "просроченные" (календарный чекбокс) — задачи без дедлайна не показываем.
            if (! empty($filters['overdue'])) {
                $query->whereRaw('1 = 0');
            }

            $tasksWithoutDue = $query->limit(50)->get();
        }

        $isSuperAdmin = (bool) $user
            && method_exists($user, 'isSuperAdmin')
            && $user->isSuperAdmin();

        $selectedHoliday = null;
        $holidayId = request()->query('holiday_id');

        if ($holidayId && $user) {
            $selectedHoliday = MarketHoliday::query()
                ->whereKey((int) $holidayId)
                ->when(! $isSuperAdmin, fn ($q) => $q->where('market_id', (int) $user->market_id))
                ->first();
        }

        $canEditHoliday = (bool) $user && (
            $isSuperAdmin
            || (method_exists($user, 'hasRole') && $user->hasRole('market-admin'))
        );

        // URL закрытия модалки праздника: сохраняем всё, кроме holiday_id
        $holidayCloseUrl = url()->current();
        $queryWithoutHoliday = Arr::except(request()->query(), ['holiday_id']);
        if (count($queryWithoutHoliday)) {
            $holidayCloseUrl .= '?' . http_build_query($queryWithoutHoliday);
        }

        return [
            'filters' => $filters,
            'statusOptions' => Task::STATUS_LABELS,
            'priorityOptions' => Task::PRIORITY_LABELS,
            'tasksWithoutDue' => $tasksWithoutDue,
            'selectedHoliday' => $selectedHoliday,
            'holidayCloseUrl' => $holidayCloseUrl,
            'canEditHoliday' => $canEditHoliday,
        ];
    }

    /**
     * Filament v4: Filament\Schemas\Components\Tabs\Tab
     * Filament v3: Filament\Resources\Components\Tab
     */
    protected static function resolveTabClass(): string
    {
        if (class_exists(\Filament\Schemas\Components\Tabs\Tab::class)) {
            return \Filament\Schemas\Components\Tabs\Tab::class;
        }

        if (class_exists(\Filament\Resources\Components\Tab::class)) {
            return \Filament\Resources\Components\Tab::class;
        }

        throw new \RuntimeException('Filament Tab class not found for this version.');
    }

    /**
     * Безопасный конструктор табов: поддержка разных версий Filament и единый стиль.
     */
    protected function makeTab(string $tabClass, string $label, ?callable $modifyQueryUsing = null): object
    {
        $tab = $tabClass::make($label);

        if ($modifyQueryUsing && method_exists($tab, 'modifyQueryUsing')) {
            $tab->modifyQueryUsing($modifyQueryUsing);
        }

        return $tab;
    }
}
