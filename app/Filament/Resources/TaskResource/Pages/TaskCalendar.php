<?php

# app/Filament/Resources/TaskResource/Pages/TaskCalendar.php

declare(strict_types=1);

namespace App\Filament\Resources\TaskResource\Pages;

use App\Filament\Resources\TaskResource;
use Filament\Resources\Pages\Page;

class TaskCalendar extends Page
{
    protected static string $resource = TaskResource::class;

    protected static ?string $title = 'Календарь';

    /**
     * Filament v4: у базового класса $view — НЕ static.
     * На случай если redirect не сработает (или отключат JS), оставим валидный view.
     */
    protected string $view = 'filament.resources.task-resource.pages.calendar';

    /**
     * Доступ как у ресурса "Задачи". Если false — Filament вернёт 404.
     */
    public static function canAccess(array $parameters = []): bool
    {
        return TaskResource::canViewAny();
    }

    /**
     * Совместимость: старый URL /admin/tasks/calendar -> новый /admin/tasks?view=calendar
     * Сохраняем query-параметры (date, filters, holiday_id, tab, и т.д.).
     */
    public function mount(): void
    {
        parent::mount();

        $query = request()->query();
        $query['view'] = 'calendar';

        $target = TaskResource::getUrl('index');

        if (! empty($query)) {
            $target .= '?' . http_build_query($query);
        }

        $this->redirect($target);
    }
}
