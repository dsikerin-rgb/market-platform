<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Pages\Requests;
use App\Filament\Resources\TaskResource;
use App\Models\Market;
use App\Models\Task;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;
use Illuminate\Support\Carbon;

class TasksWorkspaceWidget extends Widget
{
    protected string $view = 'filament.widgets.tasks-workspace-widget';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = -100;

    public static function canView(): bool
    {
        return TaskResource::canViewAny();
    }

    protected function getViewData(): array
    {
        $marketId = $this->resolveMarketId();
        $market = $marketId > 0
            ? Market::query()->select(['id', 'name'])->find($marketId)
            : null;

        $baseQuery = TaskResource::getEloquentQuery();

        $open = (clone $baseQuery)->open()->count();
        $inProgress = (clone $baseQuery)->inWork()->count();
        $overdue = (clone $baseQuery)->overdue()->count();
        $urgent = (clone $baseQuery)->urgent()->count();
        $unassigned = (clone $baseQuery)->unassigned()->count();

        $nearestDeadline = (clone $baseQuery)
            ->open()
            ->whereNotNull('due_at')
            ->orderBy('due_at')
            ->value('due_at');

        return [
            'marketName' => $market?->name,
            'open' => $open,
            'inProgress' => $inProgress,
            'overdue' => $overdue,
            'urgent' => $urgent,
            'unassigned' => $unassigned,
            'nearestDeadline' => $this->formatDeadline($nearestDeadline),
            'createUrl' => TaskResource::getUrl('create'),
            'listUrl' => TaskResource::getUrl('index'),
            'calendarUrl' => TaskResource::getUrl('index', ['view' => 'calendar']),
            'requestsUrl' => Requests::getUrl(),
        ];
    }

    private function formatDeadline(mixed $value): string
    {
        if (! filled($value)) {
            return 'Нет дедлайнов';
        }

        try {
            return Carbon::parse((string) $value)->format('d.m.Y H:i');
        } catch (\Throwable) {
            return (string) $value;
        }
    }

    private function resolveMarketId(): int
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return 0;
        }

        if (! $user->isSuperAdmin()) {
            return (int) ($user->market_id ?: 0);
        }

        $panelId = Filament::getCurrentPanel()?->getId() ?? 'admin';
        $value = session("filament_{$panelId}_market_id");

        return filled($value) ? (int) $value : 0;
    }
}
