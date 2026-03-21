<?php

declare(strict_types=1);

namespace App\Filament\Resources\TaskResource\Pages;

use App\Filament\Resources\TaskResource;
use App\Filament\Widgets\TasksWorkspaceWidget;
use App\Models\MarketHoliday;
use App\Models\Task;
use App\Support\TaskCalendarFilters;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use RuntimeException;

class ListTasks extends ListRecords
{
    protected static string $resource = TaskResource::class;

    protected static ?string $title = 'Задачи';

    protected array $queryString = [
        'activeTab' => ['as' => 'tab', 'except' => 'all'],
        'viewMode' => ['as' => 'view', 'except' => 'list'],
    ];

    public ?string $activeTab = 'all';

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

    public function getBreadcrumbs(): array
    {
        return [];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            TasksWorkspaceWidget::make([
                'viewMode' => $this->viewMode,
            ]),
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    public function getHeading(): string|Htmlable|null
    {
        return null;
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'all';
    }

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

        return array_merge($data, $this->getCalendarViewData());
    }

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
            'my' => $this->makeTab(
                $tabClass,
                'Мне назначено',
                fn (Builder $query) => $query->assignedTo($myId)->workOrder()
            ),
            'observing' => $this->makeTab(
                $tabClass,
                'Наблюдаю',
                fn (Builder $query) => $query->where(function (Builder $builder) use ($myId): void {
                    $builder->whereHas('observers', fn (Builder $q) => $q->whereKey($myId));

                    if (Task::supportsWatchers()) {
                        $builder->orWhereHas('watchers', fn (Builder $q) => $q->whereKey($myId));
                    }
                })->workOrder()
            ),
            'coexecuting' => $this->makeTab(
                $tabClass,
                'Соисполняю',
                fn (Builder $query) => $query
                    ->whereHas('coexecutors', fn (Builder $q) => $q->whereKey($myId))
                    ->workOrder()
            ),
            'in_progress' => $this->makeTab(
                $tabClass,
                'В работе',
                fn (Builder $query) => $query->inWork()->workOrder()
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

    protected function getHeaderActions(): array
    {
        return [];
    }

    private function getCalendarViewData(): array
    {
        $user = Filament::auth()->user();
        $filters = TaskCalendarFilters::fromRequest();

        $isSuperAdmin = (bool) $user
            && method_exists($user, 'isSuperAdmin')
            && $user->isSuperAdmin();

        $selectedHoliday = null;
        $holidayId = request()->query('holiday_id');

        if ($holidayId && $user) {
            $selectedHoliday = MarketHoliday::query()
                ->whereKey((int) $holidayId)
                ->when(! $isSuperAdmin, fn ($query) => $query->where('market_id', (int) $user->market_id))
                ->first();
        }

        $canEditHoliday = (bool) $user && (
            $isSuperAdmin
            || (method_exists($user, 'hasRole') && $user->hasRole('market-admin'))
        );

        $holidayCloseUrl = url()->current();
        $queryWithoutHoliday = Arr::except(request()->query(), ['holiday_id']);

        if (count($queryWithoutHoliday) > 0) {
            $holidayCloseUrl .= '?' . http_build_query($queryWithoutHoliday);
        }

        return [
            'filters' => $filters,
            'statusOptions' => Task::STATUS_LABELS,
            'priorityOptions' => Task::PRIORITY_LABELS,
            'calendarTabs' => $this->getCalendarTabsData(),
            'selectedHoliday' => $selectedHoliday,
            'holidayCloseUrl' => $holidayCloseUrl,
            'canEditHoliday' => $canEditHoliday,
        ];
    }

    /**
     * @return list<array{key: string, label: string, url: string, active: bool}>
     */
    private function getCalendarTabsData(): array
    {
        $query = Arr::except(request()->query(), ['page', 'tab']);
        $query['view'] = 'calendar';

        return collect($this->getTabs())
            ->map(function (object $tab, string $key) use ($query): array {
                $tabQuery = $query;

                if ($key !== 'all') {
                    $tabQuery['tab'] = $key;
                }

                return [
                    'key' => $key,
                    'label' => method_exists($tab, 'getLabel') ? (string) $tab->getLabel() : $key,
                    'url' => TaskResource::getUrl('index', $tabQuery),
                    'active' => ($this->activeTab ?? 'all') === $key,
                ];
            })
            ->values()
            ->all();
    }

    protected static function resolveTabClass(): string
    {
        if (class_exists(\Filament\Schemas\Components\Tabs\Tab::class)) {
            return \Filament\Schemas\Components\Tabs\Tab::class;
        }

        if (class_exists(\Filament\Resources\Components\Tab::class)) {
            return \Filament\Resources\Components\Tab::class;
        }

        throw new RuntimeException('Filament Tab class not found for this version.');
    }

    protected function makeTab(string $tabClass, string $label, ?callable $modifyQueryUsing = null): object
    {
        $tab = $tabClass::make($label);

        if ($modifyQueryUsing && method_exists($tab, 'modifyQueryUsing')) {
            $tab->modifyQueryUsing($modifyQueryUsing);
        }

        return $tab;
    }

    public function getPageClasses(): array
    {
        return [
            ...parent::getPageClasses(),
            'fi-resource-tasks-list-page',
        ];
    }
}
