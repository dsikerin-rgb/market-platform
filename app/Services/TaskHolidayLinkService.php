<?php
# app/Services/TaskHolidayLinkService.php

declare(strict_types=1);

namespace App\Services;

use App\Models\MarketHoliday;
use App\Models\MarketHolidayTaskLink;
use App\Models\Task;
use Illuminate\Support\Facades\DB;

class TaskHolidayLinkService
{
    /**
     * Создать событие из задачи и связать их.
     *
     * @return array{success: bool, holiday?: MarketHoliday, message: string}
     */
    public function createHolidayFromTask(Task $task): array
    {
        if (! $task->market_id) {
            return [
                'success' => false,
                'message' => 'У задачи не указан рынок',
            ];
        }

        if ($task->linkedMarketHoliday()) {
            return [
                'success' => false,
                'message' => 'Связанное событие уже существует',
            ];
        }

        $existingLink = MarketHolidayTaskLink::query()
            ->where('task_id', $task->id)
            ->first();

        if ($existingLink) {
            return [
                'success' => false,
                'message' => 'Связь с событием уже существует',
            ];
        }

        return DB::transaction(function () use ($task): array {
            $publicDescription = $this->getPublicDescription($task);

            $holiday = MarketHoliday::create([
                'market_id' => (int) $task->market_id,
                'title' => trim((string) $task->title) !== '' ? (string) $task->title : 'Задача #' . $task->id,
                'description' => $publicDescription !== '' ? $publicDescription : null,
                'starts_at' => $task->due_at?->toDateString() ?? now()->toDateString(),
                'ends_at' => null,
                'all_day' => true,
                'source' => 'market_event',
                'audience_scope' => 'staff',
                'audience_payload' => [
                    'scenarios' => [
                        'enabled_tasks' => false,
                    ],
                ],
                'public_payload' => [],
            ]);

            MarketHolidayTaskLink::create([
                'market_id' => (int) $task->market_id,
                'market_holiday_id' => (int) $holiday->id,
                'task_id' => (int) $task->id,
                'scenario_key' => 'manual_task_' . $task->id,
            ]);

            if (blank($task->source_type) && blank($task->source_id)) {
                $task->forceFill([
                    'source_type' => MarketHoliday::class,
                    'source_id' => (int) $holiday->id,
                ])->save();
            }

            return [
                'success' => true,
                'holiday' => $holiday->fresh(),
                'message' => 'Событие создано и связано с задачей',
            ];
        });
    }

    /**
     * Создать связь между существующей задачей и событием.
     *
     * @return array{success: bool, link?: MarketHolidayTaskLink, message: string}
     */
    public function linkTaskToHoliday(Task $task, MarketHoliday $holiday): array
    {
        if (! $task->market_id) {
            return [
                'success' => false,
                'message' => 'У задачи не указан рынок',
            ];
        }

        if ((int) $task->market_id !== (int) $holiday->market_id) {
            return [
                'success' => false,
                'message' => 'Задача и событие относятся к разным рынкам',
            ];
        }

        $existingLink = MarketHolidayTaskLink::query()
            ->where('task_id', $task->id)
            ->where('market_holiday_id', $holiday->id)
            ->first();

        if ($existingLink) {
            return [
                'success' => false,
                'message' => 'Связь уже существует',
            ];
        }

        $existingTaskLink = MarketHolidayTaskLink::query()
            ->where('task_id', $task->id)
            ->first();

        if ($existingTaskLink) {
            return [
                'success' => false,
                'message' => 'Задача уже связана с другим событием',
            ];
        }

        return DB::transaction(function () use ($task, $holiday): array {
            $link = MarketHolidayTaskLink::create([
                'market_id' => (int) $task->market_id,
                'market_holiday_id' => (int) $holiday->id,
                'task_id' => (int) $task->id,
                'scenario_key' => 'manual_task_' . $task->id,
            ]);

            if (blank($task->source_type) && blank($task->source_id)) {
                $task->forceFill([
                    'source_type' => MarketHoliday::class,
                    'source_id' => (int) $holiday->id,
                ])->save();
            }

            return [
                'success' => true,
                'link' => $link->fresh(),
                'message' => 'Связь создана',
            ];
        });
    }

    /**
     * Удалить связь задачи и события.
     *
     * @return array{success: bool, message: string}
     */
    public function unlinkTaskFromHoliday(Task $task): array
    {
        $link = MarketHolidayTaskLink::query()
            ->where('task_id', $task->id)
            ->first();

        if (! $link) {
            return [
                'success' => false,
                'message' => 'Связь не найдена',
            ];
        }

        $linkedHolidayId = (int) $link->market_holiday_id;

        return DB::transaction(function () use ($task, $link, $linkedHolidayId): array {
            $link->delete();

            if (
                $task->source_type === MarketHoliday::class
                && (int) $task->source_id === $linkedHolidayId
            ) {
                $task->forceFill([
                    'source_type' => null,
                    'source_id' => null,
                ])->save();
            }

            return [
                'success' => true,
                'message' => 'Связь удалена',
            ];
        });
    }

    /**
     * Получить описание задачи без технического префикса.
     */
    private function getPublicDescription(Task $task): string
    {
        $description = (string) ($task->description ?? '');
        $lines = preg_split('/\R/u', $description) ?: [];

        $public = [];
        $collectTechnical = true;

        foreach ($lines as $line) {
            $normalized = trim($line);

            $isTechnicalLine = $normalized === ''
                || str_starts_with($normalized, 'calendar_scenario=')
                || str_starts_with($normalized, 'Событие календаря:');

            if ($collectTechnical && $isTechnicalLine) {
                continue;
            }

            $collectTechnical = false;
            $public[] = rtrim($line);
        }

        return trim(implode("\n", $public));
    }
}
