<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Pages\Requests;
use App\Filament\Resources\MarketHolidayResource;
use App\Filament\Resources\TaskResource;
use Filament\Widgets\Widget;

class TasksWorkspaceWidget extends Widget
{
    protected string $view = 'filament.widgets.tasks-workspace-widget';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = -100;

    public string $viewMode = 'list';

    public static function canView(): bool
    {
        return TaskResource::canViewAny();
    }

    protected function getViewData(): array
    {
        $viewMode = $this->viewMode;

        if (! in_array($viewMode, ['list', 'calendar'], true)) {
            $viewMode = request()->query('view', 'list');
        }

        if (! in_array($viewMode, ['list', 'calendar'], true)) {
            $viewMode = 'list';
        }

        return [
            'viewMode' => $viewMode,
            'createUrl' => TaskResource::getUrl('create'),
            'listUrl' => $this->urlForView('list'),
            'calendarUrl' => $this->urlForView('calendar'),
            'eventsUrl' => MarketHolidayResource::getUrl('index'),
            'requestsUrl' => Requests::getUrl(),
        ];
    }

    private function urlForView(string $mode): string
    {
        $query = [];
        $tab = request()->query('tab');

        if (filled($tab) && $tab !== 'all') {
            $query['tab'] = (string) $tab;
        }

        if ($mode === 'calendar') {
            $query['view'] = 'calendar';
        }

        return TaskResource::getUrl('index', $query);
    }
}
