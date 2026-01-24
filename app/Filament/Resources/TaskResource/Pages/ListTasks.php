<?php
# app/Filament/Resources/TaskResource/Pages/ListTasks.php

namespace App\Filament\Resources\TaskResource\Pages;

use App\Filament\Resources\TaskResource;
use App\Models\Task;
use Filament\Actions;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListTasks extends ListRecords
{
    protected static string $resource = TaskResource::class;

    protected static ?string $title = 'Задачи';

    public function getBreadcrumb(): string
    {
        return 'Задачи';
    }

    /**
     * Быстрые “рабочие” срезы над таблицей.
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

            'all' => $tabClass::make('Все'),
        ];
    }

    public function getDefaultActiveTab(): ?string
    {
        return 'in_progress';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Создать')
                ->icon('heroicon-o-plus'),
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
