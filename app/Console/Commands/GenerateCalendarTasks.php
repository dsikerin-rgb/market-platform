<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Market;
use App\Models\MarketHoliday;
use App\Models\MarketHolidayTaskLink;
use App\Models\Task;
use App\Models\TaskParticipant;
use App\Models\User;
use App\Support\SystemAgentService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;

class GenerateCalendarTasks extends Command
{
    protected $signature = 'market:calendar:generate-tasks
        {--from= : Start date (Y-m-d)}
        {--to= : End date (Y-m-d)}
        {--market_id= : Only one market id}
        {--dry-run : Show changes without write}';

    protected $description = 'Generate or update calendar-driven tasks (idempotent, without duplicates).';

    /**
     * @var array<int, int|null>
     */
    private array $systemAgentIdByMarket = [];

    public function handle(SystemAgentService $systemAgentService): int
    {
        $fromDate = $this->parseDate($this->option('from')) ?? now()->startOfDay();
        $toDate = $this->parseDate($this->option('to')) ?? $fromDate->copy()->addYear();

        if ($toDate->lessThan($fromDate)) {
            $this->error('The --to date must be greater than or equal to --from.');

            return Command::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $this->info($dryRun ? 'mode=DRY-RUN' : 'mode=EXECUTE');
        $this->line(sprintf('scope=%s..%s', $fromDate->toDateString(), $toDate->toDateString()));

        $marketIds = $this->resolveMarketIds();
        if ($marketIds === []) {
            $this->warn('No markets selected.');

            return Command::SUCCESS;
        }

        if (! Schema::hasTable('market_holiday_task_links')) {
            $this->error('Table market_holiday_task_links is missing. Run php artisan migrate first.');

            return Command::FAILURE;
        }

        $holidays = MarketHoliday::query()
            ->whereIn('market_id', $marketIds)
            ->whereBetween('starts_at', [$fromDate->toDateString(), $toDate->toDateString()])
            ->orderBy('market_id')
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();

        if ($holidays->isEmpty()) {
            $this->warn('No calendar events found in selected period.');

            return Command::SUCCESS;
        }

        $created = 0;
        $updated = 0;
        $linked = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($holidays as $holiday) {
            $market = Market::query()->find((int) $holiday->market_id);
            if (! $market) {
                $skipped++;
                continue;
            }

            $scenarios = $this->buildScenarios($holiday, $market);
            foreach ($scenarios as $scenario) {
                try {
                    $result = $this->processScenario(
                        holiday: $holiday,
                        market: $market,
                        scenario: $scenario,
                        dryRun: $dryRun,
                        systemAgentService: $systemAgentService,
                    );

                    $created += $result['created'];
                    $updated += $result['updated'];
                    $linked += $result['linked'];
                    $skipped += $result['skipped'];
                } catch (\Throwable $e) {
                    $errors++;
                    report($e);
                    $this->error(sprintf(
                        '[holiday=%d scenario=%s] %s',
                        (int) $holiday->id,
                        (string) ($scenario['key'] ?? 'unknown'),
                        $e->getMessage(),
                    ));
                }
            }
        }

        $this->newLine();
        $this->info(sprintf(
            'Summary: holidays=%d created=%d updated=%d linked=%d skipped=%d errors=%d',
            $holidays->count(),
            $created,
            $updated,
            $linked,
            $skipped,
            $errors,
        ));

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @return list<int>
     */
    private function resolveMarketIds(): array
    {
        $marketId = $this->option('market_id');
        if (filled($marketId) && is_numeric($marketId)) {
            return [(int) $marketId];
        }

        return Market::query()
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if (! filled($value)) {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return list<array{key:string,title:string,description:string,priority:string,due_at:?Carbon,roles:list<string>,explicit_user_ids:list<int>}>
     */
    private function buildScenarios(MarketHoliday $holiday, Market $market): array
    {
        $source = strtolower(trim((string) ($holiday->source ?? '')));
        $dateLabel = $holiday->starts_at?->format('Y-m-d') ?? 'n/a';
        $notifyDays = is_numeric($holiday->notify_before_days) ? max(0, (int) $holiday->notify_before_days) : 7;

        if ($source === 'sanitary_auto') {
            $dueAt = $holiday->starts_at?->copy()->subDay()->setTime(12, 0);

            return [[
                'key' => 'sanitary_day_preparation',
                'title' => "Санитарный день {$dateLabel}: подготовка рынка",
                'description' => implode("\n", [
                    'calendar_scenario=sanitary_day_preparation',
                    "Событие календаря: {$holiday->title}",
                    '',
                    'Чек-лист:',
                    '- Проверить график уборки и доступ подрядчиков.',
                    '- Подготовить уведомление арендаторам по ограничениям на дату.',
                    '- Назначить ответственных по зонам и контрольный обход.',
                ]),
                'priority' => Task::PRIORITY_HIGH,
                'due_at' => $dueAt,
                'roles' => [
                    'market-engineer',
                    'market-maintenance',
                    'market-manager',
                    'market-admin',
                ],
                'explicit_user_ids' => [],
            ]];
        }

        $leadDays = max(1, min(7, $notifyDays > 0 ? $notifyDays : 7));
        $dueAt = $holiday->starts_at?->copy()->subDays($leadDays)->setTime(12, 0);

        return [[
            'key' => 'holiday_communications',
            'title' => "{$holiday->title}: план информирования и подготовки",
            'description' => implode("\n", [
                'calendar_scenario=holiday_communications',
                "Событие календаря: {$holiday->title}",
                '',
                'Подготовить коммуникационный план:',
                '- Информирование арендаторов.',
                '- Информирование покупателей.',
                '- Каналы: СМИ, рассылка, Telegram, SMS, VK.',
                '- Ответственные и сроки публикаций.',
            ]),
            'priority' => Task::PRIORITY_NORMAL,
            'due_at' => $dueAt,
            'roles' => [
                'market-marketing',
                'market-advertising',
                'market-manager',
                'market-owner',
                'market-admin',
            ],
            'explicit_user_ids' => $this->configuredHolidayRecipients((array) ($market->settings ?? [])),
        ]];
    }

    /**
     * @param array<string,mixed> $settings
     * @return list<int>
     */
    private function configuredHolidayRecipients(array $settings): array
    {
        return array_values(array_filter(
            array_map(static fn ($value) => is_numeric($value) ? (int) $value : 0, (array) ($settings['holiday_notification_recipient_user_ids'] ?? [])),
            static fn (int $value): bool => $value > 0,
        ));
    }

    /**
     * @param array{key:string,title:string,description:string,priority:string,due_at:?Carbon,roles:list<string>,explicit_user_ids:list<int>} $scenario
     * @return array{created:int,updated:int,linked:int,skipped:int}
     */
    private function processScenario(
        MarketHoliday $holiday,
        Market $market,
        array $scenario,
        bool $dryRun,
        SystemAgentService $systemAgentService,
    ): array {
        $recipientIds = $this->resolveRecipientIds(
            marketId: (int) $market->id,
            explicitUserIds: $scenario['explicit_user_ids'],
            roleNames: $scenario['roles'],
        );

        $creatorId = $this->resolveSystemAgentId((int) $market->id, $dryRun, $systemAgentService);
        $assigneeId = $recipientIds[0] ?? $creatorId;
        $coexecutorIds = array_values(array_filter(
            $recipientIds,
            static fn (int $id): bool => $assigneeId === null || $id !== $assigneeId,
        ));

        $link = MarketHolidayTaskLink::query()
            ->where('market_holiday_id', (int) $holiday->id)
            ->where('scenario_key', $scenario['key'])
            ->first();

        $task = $link?->task;
        if (! $task) {
            $task = $this->findLegacyTask($holiday, $scenario['key']);
        }

        if ($dryRun) {
            $action = $task ? 'update/link' : 'create';
            $this->line(sprintf(
                '[DRY-RUN] holiday=%d scenario=%s action=%s assignee=%s coexecutors=%d',
                (int) $holiday->id,
                $scenario['key'],
                $action,
                $assigneeId !== null ? (string) $assigneeId : 'none',
                count($coexecutorIds),
            ));

            return [
                'created' => $task ? 0 : 1,
                'updated' => $task ? 1 : 0,
                'linked' => $link || $task ? 1 : 0,
                'skipped' => 0,
            ];
        }

        return DB::transaction(function () use (
            $holiday,
            $scenario,
            $task,
            $link,
            $market,
            $creatorId,
            $assigneeId,
            $coexecutorIds
        ): array {
            $created = 0;
            $updated = 0;
            $linked = 0;

            if (! $task) {
                $task = new Task();
                $created++;
            } else {
                $updated++;
            }

            $task->market_id = (int) $market->id;
            $task->title = $scenario['title'];
            $task->description = $scenario['description'];
            $task->priority = $scenario['priority'];
            $task->due_at = $scenario['due_at'];
            $task->source_type = MarketHoliday::class;
            $task->source_id = (int) $holiday->id;

            if ($creatorId !== null) {
                $task->created_by_user_id = $creatorId;
                $task->created_by = $creatorId;
            }

            if ($assigneeId !== null) {
                $task->assignee_id = $assigneeId;
            }

            $task->save();

            $resolvedLink = $link;
            if (! $resolvedLink) {
                $resolvedLink = MarketHolidayTaskLink::query()
                    ->where('market_holiday_id', (int) $holiday->id)
                    ->where('scenario_key', $scenario['key'])
                    ->first();
            }

            if ($resolvedLink) {
                if ((int) $resolvedLink->task_id !== (int) $task->id || (int) $resolvedLink->market_id !== (int) $market->id) {
                    $resolvedLink->task_id = (int) $task->id;
                    $resolvedLink->market_id = (int) $market->id;
                    $resolvedLink->save();
                }
            } else {
                MarketHolidayTaskLink::query()->create([
                    'market_id' => (int) $market->id,
                    'market_holiday_id' => (int) $holiday->id,
                    'task_id' => (int) $task->id,
                    'scenario_key' => $scenario['key'],
                ]);
            }

            $linked++;
            $this->syncCoexecutors($task, $coexecutorIds, $assigneeId);

            $this->line(sprintf(
                '[OK] holiday=%d scenario=%s task=%d assignee=%s coexecutors=%d',
                (int) $holiday->id,
                $scenario['key'],
                (int) $task->id,
                $assigneeId !== null ? (string) $assigneeId : 'none',
                count($coexecutorIds),
            ));

            return [
                'created' => $created,
                'updated' => $updated,
                'linked' => $linked,
                'skipped' => 0,
            ];
        });
    }

    /**
     * @param list<int> $coexecutorIds
     */
    private function syncCoexecutors(Task $task, array $coexecutorIds, ?int $assigneeId): void
    {
        if ($coexecutorIds === []) {
            TaskParticipant::query()
                ->where('task_id', (int) $task->id)
                ->where('role', Task::PARTICIPANT_ROLE_COEXECUTOR)
                ->delete();

            return;
        }

        TaskParticipant::query()
            ->where('task_id', (int) $task->id)
            ->where('role', Task::PARTICIPANT_ROLE_COEXECUTOR)
            ->whereNotIn('user_id', $coexecutorIds)
            ->delete();

        foreach ($coexecutorIds as $userId) {
            if ($assigneeId !== null && $userId === $assigneeId) {
                continue;
            }

            TaskParticipant::query()->updateOrCreate(
                [
                    'task_id' => (int) $task->id,
                    'user_id' => (int) $userId,
                ],
                [
                    'role' => Task::PARTICIPANT_ROLE_COEXECUTOR,
                ],
            );
        }
    }

    /**
     * @return list<int>
     */
    private function resolveRecipientIds(int $marketId, array $explicitUserIds, array $roleNames): array
    {
        $ids = [];

        if ($explicitUserIds !== []) {
            $validExplicitIds = User::query()
                ->where('market_id', $marketId)
                ->whereIn('id', $explicitUserIds)
                ->orderBy('id')
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            foreach ($validExplicitIds as $id) {
                $ids[$id] = true;
            }
        }

        foreach ($roleNames as $roleName) {
            if (! Role::query()->where('name', $roleName)->exists()) {
                continue;
            }

            $roleUserIds = User::query()
                ->where('market_id', $marketId)
                ->role($roleName)
                ->orderBy('id')
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            foreach ($roleUserIds as $id) {
                $ids[$id] = true;
            }
        }

        return array_map('intval', array_keys($ids));
    }

    private function resolveSystemAgentId(int $marketId, bool $dryRun, SystemAgentService $service): ?int
    {
        if (array_key_exists($marketId, $this->systemAgentIdByMarket)) {
            return $this->systemAgentIdByMarket[$marketId];
        }

        $existing = $service->findForMarket($marketId);
        if ($existing) {
            $this->systemAgentIdByMarket[$marketId] = (int) $existing->id;

            return (int) $existing->id;
        }

        if ($dryRun) {
            $this->line("[DRY-RUN] market={$marketId} system-agent=would-create");
            $this->systemAgentIdByMarket[$marketId] = null;

            return null;
        }

        $result = $service->ensureForMarket($marketId, true);
        $user = $result['user'] ?? null;

        if ($user instanceof User) {
            $this->line("[OK] market={$marketId} system-agent=ready user_id={$user->id}");
            $this->systemAgentIdByMarket[$marketId] = (int) $user->id;

            return (int) $user->id;
        }

        $this->warn("[WARN] market={$marketId} system-agent={$result['status']} message={$result['message']}");
        $this->systemAgentIdByMarket[$marketId] = null;

        return null;
    }

    private function findLegacyTask(MarketHoliday $holiday, string $scenarioKey): ?Task
    {
        return Task::query()
            ->where('market_id', (int) $holiday->market_id)
            ->where('source_type', MarketHoliday::class)
            ->where('source_id', (int) $holiday->id)
            ->where('description', 'like', '%calendar_scenario=' . $scenarioKey . '%')
            ->orderBy('id')
            ->first();
    }
}
