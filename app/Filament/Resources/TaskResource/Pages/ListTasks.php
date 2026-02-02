<?php

# app/Filament/Resources/TaskResource/Pages/ListTasks.php

declare(strict_types=1);

namespace App\Filament\Resources\TaskResource\Pages;

use App\Filament\Resources\TaskResource;
use Filament\Actions;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListTasks extends ListRecords
{
    protected static string $resource = TaskResource::class;

    protected static ?string $title = 'Задачи';

    /**
     * ✅ Нужно, чтобы URL был вида /admin/tasks?tab=all, а не ?activeTab=...
     * И чтобы дефолтный таб не попадал в URL (except).
     */
    protected array $queryString = [
        'activeTab' => ['as' => 'tab', 'except' => 'all'],
    ];

    /**
     * ✅ Жёстко задаём дефолт.
     * Важно: Filament может инициализировать activeTab до вызова getDefaultActiveTab(),
     * поэтому держим обе настройки.
     */
    public ?string $activeTab = 'all';

    public function getBreadcrumb(): string
    {
        return 'Задачи';
    }

    /**
     * ✅ По умолчанию — «Все».
     */
    public function getDefaultActiveTab(): string|int|null
    {
        return 'all';
    }

    /**
     * Быстрые “рабочие” срезы над таблицей.
     * ✅ «Все» — самый левый.
     */
    public function getTabs(): array
    {
        $tabClass = static::resolveTabClass();

        $user = Filament::auth()->user();

        // Даже если пользователя нет — пусть будет один таб.
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

    protected function getHeaderActions(): array
    {
        $actions = [
            Actions\CreateAction::make()
                ->label('Создать')
                ->icon('heroicon-o-plus'),
        ];

        if (class_exists(Actions\Action::class)) {
            $actions[] = Actions\Action::make('calendar')
                ->label('Календарь')
                ->icon('heroicon-o-calendar-days')
                ->url(fn (): string => TaskResource::getUrl('calendar'));
        }

        return $actions;
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
