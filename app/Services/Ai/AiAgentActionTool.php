<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Filament\Pages\Requests;
use App\Filament\Resources\MarketHolidayResource;
use App\Filament\Resources\MarketSpaceResource;
use App\Filament\Resources\TaskResource;
use App\Filament\Resources\TenantContractResource;
use App\Filament\Resources\TenantResource;
use App\Models\ContractDebt;
use App\Models\MarketHoliday;
use App\Models\MarketSpace;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\TenantContract;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Search\LooseSearch;
use App\Support\StaffConversationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class AiAgentActionTool
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok:bool,message:string,chips:list<array{label:string,url:string}>,data:array<string,mixed>}
     */
    public function run(User $actor, int $marketId, array $payload): array
    {
        $tool = strtolower(trim((string) ($payload['tool'] ?? $payload['name'] ?? '')));

        if (! $this->canUseMarket($actor, $marketId)) {
            return $this->failure('Не выбран рынок или у пользователя нет доступа к этому рынку.');
        }

        try {
            return match ($tool) {
                'create_task' => $this->createTask($actor, $marketId, $payload, false),
                'create_reminder' => $this->createTask($actor, $marketId, $payload, true),
                'create_event' => $this->createEvent($actor, $marketId, $payload),
                'send_staff_message' => $this->sendStaffMessage($actor, $marketId, $payload),
                'send_tenant_message' => $this->sendTenantMessage($actor, $marketId, $payload),
                'update_my_profile', 'profile_update' => $this->updateMyProfile($actor, $marketId, $payload),
                'find_resource', 'find_record' => $this->findResource($actor, $marketId, $payload),
                'debt_leaders', 'top_debt_tenants' => $this->debtLeaders($marketId, $payload),
                'rent_rate_extremes', 'rent_rates' => $this->rentRateExtremes($marketId, $payload),
                'vacant_spaces', 'free_spaces' => $this->vacantSpaces($marketId, $payload),
                'tenant_profile', 'tenant_summary' => $this->tenantProfile($marketId, $payload),
                'open_tickets_summary', 'ticket_summary' => $this->openTicketsSummary($marketId, $payload),
                'expiring_contracts', 'contract_expirations' => $this->expiringContracts($marketId, $payload),
                'resource_link', 'make_link' => $this->resourceLink($actor, $marketId, $payload),
                default => $this->failure('Неизвестное действие агента.'),
            };
        } catch (Throwable $exception) {
            report($exception);

            return $this->failure('Действие не выполнено из-за внутренней ошибки приложения.');
        }
    }

    public function schemaHint(bool $includeReadOnlyActions = true, bool $includeMutatingActions = true): string
    {
        $examples = [];

        if ($includeMutatingActions) {
            $examples = [
                ...$examples,
                '{"tool":"create_task","title":"...","description":"...","due_at":"2026-06-21 10:00","assignee_user_id":123,"assignee_query":"имя сотрудника","priority":"normal"}',
                '{"tool":"create_reminder","title":"...","description":"...","due_at":"2026-06-21 10:00","assignee_user_id":123}',
                '{"tool":"create_event","title":"...","description":"...","starts_at":"2026-06-21","ends_at":"2026-06-21","all_day":true}',
                '{"tool":"send_staff_message","recipient_user_id":123,"recipient_query":"имя сотрудника","subject":"...","message":"..."}',
                '{"tool":"send_tenant_message","tenant_id":123,"tenant_query":"название арендатора","subject":"...","message":"...","market_space_id":456}',
                '{"tool":"update_my_profile","job_title":"...","department":"...","responsibility_scope":"...","birth_date":"21.06.1990","phone":"+7...","preferred_contact_channels":["database","mail","telegram"],"notification_channels":["database","telegram"],"communication_status":"available|do_not_disturb","pause_hours":4}',
            ];
        }

        if ($includeReadOnlyActions) {
            $examples = [
                ...$examples,
                '{"tool":"find_resource","resource_type":"tenant|space|task|ticket|event|staff","query":"название, номер или фраза","limit":5}',
                '{"tool":"debt_leaders","limit":5}',
                '{"tool":"rent_rate_extremes","direction":"lowest|highest","limit":5}',
                '{"tool":"vacant_spaces","limit":10}',
                '{"tool":"tenant_profile","tenant_id":123,"tenant_query":"название арендатора"}',
                '{"tool":"open_tickets_summary","limit":10}',
                '{"tool":"expiring_contracts","days":30,"limit":10}',
                '{"tool":"resource_link","resource_type":"tenant|space|task|ticket|event|settings|current_page","id":123,"query":"название или номер","label":"понятное название"}',
            ];
        }

        if ($examples === []) {
            return '';
        }

        $examplesText = implode("\n", $examples);

        return <<<'PROMPT'

Если для выполнения просьбы сотрудника нужно действие в сервисе, сначала верни только JSON одного из видов:
PROMPT
            .$examplesText.
            <<<'PROMPT'

Для поиска записи или ссылки по человеческому названию сначала используй find_resource или resource_link с query, а не угадывай номер записи. Для вопросов "кто больше должен" используй debt_leaders. Для вопросов о самой низкой или высокой арендной ставке используй rent_rate_extremes; если результата нет, только тогда проверяй данные через read_sql.
Для вопросов о свободных местах используй vacant_spaces. Для краткой сводки по арендатору используй tenant_profile. Для вопросов о проблемных обращениях используй open_tickets_summary. Для договоров, которые скоро заканчиваются, используй expiring_contracts.
Для безопасного обновления рабочего профиля текущего сотрудника используй update_my_profile. Это действие приложение покажет сотруднику перед сохранением. Не меняй email, пароль, роли, права доступа и другие данные авторизации через агента.
Если пользователь просит "напомни", "поставь напоминание", "не дай забыть" или похожую личную просьбу, используй create_reminder. Если пользователь не указал другого сотрудника, считай, что напоминание нужно текущему пользователю. Для поручений с исполнителем используй create_task. Для событий рынка, праздников, санитарных дней, акций и календарных событий используй create_event, а не create_reminder.
Используй действия только когда сотрудник просит выполнить работу, отправить сообщение, создать задачу, событие, напоминание, проверить типовую аналитику или дать ссылку на запись. Если в ответе нужна карточка арендатора, места, задачи, обращения, события или настроек, всегда используй resource_link/make_link. Не показывай пользователю JSON, ID, идентификаторы и сырые адреса страниц. После результата действия отвечай простым русским языком и опирайся на приложенные чипы.
PROMPT;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{ok:bool,message:string,chips:list<array{label:string,url:string}>,data:array<string,mixed>}
     */
    private function updateMyProfile(User $actor, int $marketId, array $payload): array
    {
        $result = app(AiUserProfileService::class)->updateEditableProfile($actor, $marketId, $payload);

        if (! (bool) ($result['ok'] ?? false)) {
            return $this->failure((string) ($result['message'] ?? 'Не удалось обновить профиль.'));
        }

        return $this->success(
            (string) ($result['message'] ?? 'Профиль обновлён.'),
            [],
            ['changed' => (array) ($result['changed'] ?? [])],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok:bool,message:string,chips:list<array{label:string,url:string}>,data:array<string,mixed>}
     */
    private function createTask(User $actor, int $marketId, array $payload, bool $isReminder): array
    {
        if (Gate::forUser($actor)->denies('create', Task::class)) {
            return $this->failure('У пользователя нет прав на создание задач.');
        }

        $title = $this->string($payload['title'] ?? null, 255);
        if ($title === '') {
            return $this->failure('Для задачи нужно название.');
        }

        $dueAt = $this->parseDateTime($payload['due_at'] ?? $payload['remind_at'] ?? null);
        if ($isReminder && ! $dueAt) {
            return $this->failure('Для напоминания нужно указать дату или время.');
        }

        $hasExplicitAssignee = (bool) (($payload['assignee_user_id'] ?? null) || ($payload['assignee_query'] ?? null));
        $assignee = $this->resolveStaffUser($actor, $marketId, $payload['assignee_user_id'] ?? null, $payload['assignee_query'] ?? null);
        if ($hasExplicitAssignee) {
            if (! $assignee) {
                return $this->failure('Не удалось найти сотрудника для назначения.');
            }
        } elseif ($isReminder) {
            $assignee = $actor;
        }

        $priority = $this->priority($payload['priority'] ?? null);
        $description = $this->string($payload['description'] ?? $payload['message'] ?? null, 4000);
        if ($isReminder) {
            $description = trim($description."\n\nСоздано ИИ-агентом как напоминание.");
        }

        if ($isReminder) {
            $existingReminder = Task::query()
                ->where('market_id', $marketId)
                ->where('title', $title)
                ->where('due_at', $dueAt)
                ->where('created_by_user_id', (int) $actor->id)
                ->where('assignee_id', $assignee ? (int) $assignee->id : null)
                ->whereNotIn('status', Task::CLOSED_STATUSES)
                ->where('description', 'like', '%Создано ИИ-агентом как напоминание.%')
                ->latest('id')
                ->first();

            if ($existingReminder instanceof Task) {
                return $this->success(
                    'Такое напоминание уже есть.',
                    [$this->chip('Напоминание: '.$existingReminder->title, $this->taskUrl($existingReminder))],
                    ['task_id' => (int) $existingReminder->id, 'created' => false],
                );
            }
        }

        $task = Task::query()->create([
            'market_id' => $marketId,
            'title' => $title,
            'description' => $description !== '' ? $description : null,
            'status' => Task::STATUS_NEW,
            'priority' => $priority,
            'due_at' => $dueAt,
            'created_by' => (int) $actor->id,
            'created_by_user_id' => (int) $actor->id,
            'assignee_id' => $assignee ? (int) $assignee->id : null,
        ]);

        $label = ($isReminder ? 'Напоминание: ' : 'Задача: ').$task->title;

        return $this->success(
            ($isReminder ? 'Напоминание создано.' : 'Задача создана.'),
            [$this->chip($label, $this->taskUrl($task))],
            ['task_id' => (int) $task->id, 'created' => true],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok:bool,message:string,chips:list<array{label:string,url:string}>,data:array<string,mixed>}
     */
    private function createEvent(User $actor, int $marketId, array $payload): array
    {
        if (! $this->canManageEvents($actor, $marketId)) {
            return $this->failure('У пользователя нет прав на создание событий.');
        }

        $title = $this->string($payload['title'] ?? null, 255);
        if ($title === '') {
            return $this->failure('Для события нужно название.');
        }

        $startsAt = $this->parseDateTime($payload['starts_at'] ?? $payload['date'] ?? null);
        if (! $startsAt) {
            return $this->failure('Для события нужно указать дату начала.');
        }

        $endsAt = $this->parseDateTime($payload['ends_at'] ?? null);
        if ($endsAt && $endsAt->lt($startsAt)) {
            $endsAt = $startsAt->copy();
        }

        $event = MarketHoliday::query()->firstOrCreate(
            [
                'market_id' => $marketId,
                'title' => $title,
                'starts_at' => $startsAt->toDateString(),
            ],
            [
                'ends_at' => $endsAt?->toDateString(),
                'all_day' => array_key_exists('all_day', $payload) ? (bool) $payload['all_day'] : true,
                'description' => $this->string($payload['description'] ?? null, 4000) ?: null,
                'source' => 'ai_agent',
            ],
        );

        return $this->success(
            $event->wasRecentlyCreated ? 'Событие создано.' : 'Такое событие уже есть.',
            [$this->chip('Событие: '.$event->title, $this->eventUrl($event))],
            ['event_id' => (int) $event->id, 'created' => $event->wasRecentlyCreated],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok:bool,message:string,chips:list<array{label:string,url:string}>,data:array<string,mixed>}
     */
    private function sendStaffMessage(User $actor, int $marketId, array $payload): array
    {
        $recipient = $this->resolveStaffUser($actor, $marketId, $payload['recipient_user_id'] ?? null, $payload['recipient_query'] ?? null);
        if (! $recipient) {
            return $this->failure('Не удалось найти сотрудника для сообщения.');
        }

        $message = $this->string($payload['message'] ?? $payload['body'] ?? null, 5000);
        if ($message === '') {
            return $this->failure('Для отправки сообщения нужен текст.');
        }

        $subject = $this->string($payload['subject'] ?? null, 255);
        $conversation = app(StaffConversationService::class)->startConversation($actor, $recipient, $subject, $message);

        return $this->success(
            'Сообщение сотруднику отправлено.',
            [$this->chip('Диалог с '.($recipient->name ?: 'сотрудником'), $this->staffConversationUrl((int) $conversation->id))],
            ['conversation_id' => (int) $conversation->id, 'recipient_user_id' => (int) $recipient->id],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok:bool,message:string,chips:list<array{label:string,url:string}>,data:array<string,mixed>}
     */
    private function sendTenantMessage(User $actor, int $marketId, array $payload): array
    {
        $tenant = $this->resolveTenant($marketId, $payload['tenant_id'] ?? null, $payload['tenant_query'] ?? null);
        if (! $tenant) {
            return $this->failure('Не удалось найти арендатора для сообщения.');
        }

        $message = $this->string($payload['message'] ?? $payload['body'] ?? null, 5000);
        if ($message === '') {
            return $this->failure('Для отправки сообщения нужен текст.');
        }

        $spaceId = $this->spaceIdForTenant($tenant, $payload['market_space_id'] ?? null);
        $subject = $this->string($payload['subject'] ?? null, 255);
        if ($subject === '') {
            $subject = 'Сообщение от администрации';
        }

        $ticket = Ticket::query()->create([
            'market_id' => $marketId,
            'tenant_id' => (int) $tenant->id,
            'market_space_id' => $spaceId,
            'subject' => $subject,
            'description' => $message,
            'category' => 'message',
            'priority' => $this->priority($payload['priority'] ?? null),
            'status' => 'new',
            'assigned_to' => (int) $actor->id,
        ]);

        return $this->success(
            'Сообщение арендатору отправлено через обращение.',
            [$this->chip('Обращение: '.$ticket->subject, $this->ticketUrl($ticket))],
            ['ticket_id' => (int) $ticket->id, 'tenant_id' => (int) $tenant->id],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok:bool,message:string,chips:list<array{label:string,url:string}>,data:array<string,mixed>}
     */
    private function resourceLink(User $actor, int $marketId, array $payload): array
    {
        $type = strtolower(trim((string) ($payload['resource_type'] ?? $payload['type'] ?? '')));
        $id = $this->nullableInt($payload['id'] ?? $payload['record_id'] ?? null);
        $query = $payload['query'] ?? $payload['search'] ?? null;
        $label = $this->string($payload['label'] ?? null, 120);

        $chip = match ($type) {
            'tenant' => $this->tenantChipForPayload($marketId, $id, $query ?? $payload['tenant_query'] ?? null, $label),
            'space', 'market_space' => $this->spaceChipForPayload($marketId, $id, $query ?? $payload['space_query'] ?? null, $label),
            'task' => $this->taskChipForPayload($marketId, $id, $query ?? $payload['task_query'] ?? null, $label),
            'ticket', 'request' => $this->ticketChipForPayload($marketId, $id, $query ?? $payload['ticket_query'] ?? null, $label),
            'event', 'holiday' => $this->eventChipForPayload($marketId, $id, $query ?? $payload['event_query'] ?? null, $label),
            'settings', 'ai_settings' => $this->chip($label !== '' ? $label : 'Настройки ИИ-агента', '/admin/ai-agent-settings'),
            'current_page' => $this->currentPageChip($payload, $label),
            default => null,
        };

        if (! $chip) {
            return $this->failure('Не удалось подготовить ссылку на ресурс.');
        }

        return $this->success('Ссылка подготовлена.', [$chip], ['resource_type' => $type, 'id' => $id]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok:bool,message:string,chips:list<array{label:string,url:string}>,data:array<string,mixed>}
     */
    private function findResource(User $actor, int $marketId, array $payload): array
    {
        $type = strtolower(trim((string) ($payload['resource_type'] ?? $payload['type'] ?? '')));
        $query = $this->string($payload['query'] ?? $payload['search'] ?? '', 160);
        $limit = max(1, min((int) ($payload['limit'] ?? 5), 10));

        if ($query === '') {
            return $this->failure('Для поиска нужно указать название, номер или фразу.');
        }

        return match ($type) {
            'tenant' => $this->resourceSearchResult('Найдены арендаторы.', $this->searchTenants($marketId, $query, $limit)),
            'space', 'market_space' => $this->resourceSearchResult('Найдены места.', $this->searchSpaces($marketId, $query, $limit)),
            'task' => $this->resourceSearchResult('Найдены задачи.', $this->searchTasks($marketId, $query, $limit)),
            'ticket', 'request' => $this->resourceSearchResult('Найдены обращения.', $this->searchTickets($marketId, $query, $limit)),
            'event', 'holiday' => $this->resourceSearchResult('Найдены события.', $this->searchEvents($marketId, $query, $limit)),
            'staff' => $this->resourceSearchResult('Найдены сотрудники.', $this->searchStaff($actor, $marketId, $query, $limit)),
            default => $this->failure('Неизвестный тип записи для поиска.'),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok:bool,message:string,chips:list<array{label:string,url:string}>,data:array<string,mixed>}
     */
    private function debtLeaders(int $marketId, array $payload): array
    {
        if (
            ! Schema::hasTable('contract_debts')
            || ! Schema::hasColumn('contract_debts', 'market_id')
            || ! Schema::hasColumn('contract_debts', 'tenant_id')
            || ! Schema::hasColumn('contract_debts', 'debt_amount')
        ) {
            return $this->failure('Данные по задолженности сейчас недоступны.');
        }

        $limit = max(1, min((int) ($payload['limit'] ?? 5), 10));

        $rows = DB::query()
            ->fromSub(ContractDebt::currentStateQuery($marketId), 'cd')
            ->leftJoin('tenants as t', 't.id', '=', 'cd.tenant_id')
            ->where('cd.market_id', $marketId)
            ->where('cd.debt_amount', '>', 0)
            ->selectRaw('cd.tenant_id')
            ->selectRaw("COALESCE(NULLIF(MAX(t.short_name), ''), NULLIF(MAX(t.name), ''), 'Арендатор') as tenant_name")
            ->selectRaw('SUM(cd.debt_amount) as debt_amount')
            ->selectRaw('MAX(cd.period) as latest_period')
            ->groupBy('cd.tenant_id')
            ->orderByDesc(DB::raw('SUM(cd.debt_amount)'))
            ->limit($limit)
            ->get();

        if ($rows->isEmpty()) {
            return $this->success('Должники не найдены.', [], ['items' => []]);
        }

        $chips = [];
        $items = $rows->map(function (object $row) use ($marketId, &$chips): array {
            $tenantId = (int) $row->tenant_id;
            $tenantName = (string) ($row->tenant_name ?? 'Арендатор');
            $chip = $this->tenantChip($marketId, $tenantId, 'Открыть арендатора: '.$tenantName);
            if ($chip) {
                $chips[] = $chip;
            }

            return [
                'tenant_id' => $tenantId,
                'tenant_name' => $tenantName,
                'debt_amount_rub' => round((float) $row->debt_amount, 2),
                'latest_period' => (string) ($row->latest_period ?? ''),
            ];
        })->values()->all();

        return $this->success('Должники проверены.', $chips, ['items' => $items]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok:bool,message:string,chips:list<array{label:string,url:string}>,data:array<string,mixed>}
     */
    private function rentRateExtremes(int $marketId, array $payload): array
    {
        $direction = strtolower(trim((string) ($payload['direction'] ?? 'lowest')));
        $isHighest = in_array($direction, ['highest', 'max', 'maximum', 'высокая', 'самая высокая'], true);
        $limit = max(1, min((int) ($payload['limit'] ?? 5), 10));
        $rows = collect();
        $source = null;

        if (
            Schema::hasTable('tenant_accruals')
            && Schema::hasColumn('tenant_accruals', 'market_id')
            && Schema::hasColumn('tenant_accruals', 'period')
            && Schema::hasColumn('tenant_accruals', 'rent_rate')
        ) {
            $latestPeriod = DB::table('tenant_accruals')
                ->where('market_id', $marketId)
                ->whereNotNull('rent_rate')
                ->where('rent_rate', '>', 0)
                ->max('period');

            if ($latestPeriod !== null) {
                $query = DB::table('tenant_accruals as ta')
                    ->leftJoin('tenants as t', 't.id', '=', 'ta.tenant_id')
                    ->leftJoin('market_spaces as ms', 'ms.id', '=', 'ta.market_space_id')
                    ->where('ta.market_id', $marketId)
                    ->where('ta.period', $latestPeriod)
                    ->whereNotNull('ta.rent_rate')
                    ->where('ta.rent_rate', '>', 0)
                    ->selectRaw('ta.tenant_id')
                    ->selectRaw("COALESCE(NULLIF(MAX(t.short_name), ''), NULLIF(MAX(t.name), ''), 'Арендатор') as tenant_name")
                    ->selectRaw('ta.market_space_id')
                    ->selectRaw('MAX(COALESCE(ms.number, ta.source_place_code)) as space_number')
                    ->selectRaw('MAX(COALESCE(ms.display_name, ta.source_place_name)) as space_name')
                    ->selectRaw('MIN(ta.rent_rate) as rent_rate')
                    ->selectRaw('MAX(ta.period) as period')
                    ->groupBy('ta.tenant_id', 'ta.market_space_id');

                $rows = ($isHighest ? $query->orderByDesc('rent_rate') : $query->orderBy('rent_rate'))
                    ->limit($limit)
                    ->get();
                $source = 'latest_accruals';
            }
        }

        if (
            $rows->isEmpty()
            && Schema::hasTable('market_space_tenant_bindings')
            && Schema::hasColumn('market_space_tenant_bindings', 'market_id')
            && Schema::hasColumn('market_space_tenant_bindings', 'tenant_id')
            && Schema::hasColumn('market_space_tenant_bindings', 'market_space_id')
            && Schema::hasColumn('market_space_tenant_bindings', 'ended_at')
            && Schema::hasColumn('market_space_tenant_bindings', 'rent_rate')
        ) {
            $query = DB::table('market_space_tenant_bindings as b')
                ->leftJoin('tenants as t', 't.id', '=', 'b.tenant_id')
                ->leftJoin('market_spaces as ms', 'ms.id', '=', 'b.market_space_id')
                ->where('b.market_id', $marketId)
                ->whereNull('b.ended_at')
                ->whereNotNull('b.rent_rate')
                ->where('b.rent_rate', '>', 0)
                ->selectRaw('b.tenant_id')
                ->selectRaw("COALESCE(NULLIF(t.short_name, ''), NULLIF(t.name, ''), 'Арендатор') as tenant_name")
                ->selectRaw('b.market_space_id')
                ->selectRaw('ms.number as space_number')
                ->selectRaw('ms.display_name as space_name')
                ->selectRaw('b.rent_rate')
                ->selectRaw('NULL as period');

            $rows = ($isHighest ? $query->orderByDesc('b.rent_rate') : $query->orderBy('b.rent_rate'))
                ->limit($limit)
                ->get();
            $source = 'active_bindings';
        }

        if ($rows->isEmpty()) {
            return $this->success('Арендные ставки не найдены.', [], ['items' => []]);
        }

        $chips = [];
        $items = $rows->map(function (object $row) use ($marketId, $source, &$chips): array {
            $tenantId = $row->tenant_id !== null ? (int) $row->tenant_id : null;
            $tenantName = (string) ($row->tenant_name ?? 'Арендатор');

            if ($tenantId) {
                $chip = $this->tenantChip($marketId, $tenantId, 'Открыть арендатора: '.$tenantName);
                if ($chip) {
                    $chips[] = $chip;
                }
            }

            return [
                'tenant_id' => $tenantId,
                'tenant_name' => $tenantName,
                'market_space_id' => $row->market_space_id !== null ? (int) $row->market_space_id : null,
                'space' => trim((string) ($row->space_number ?? '').' '.(string) ($row->space_name ?? '')),
                'rent_rate_rub' => round((float) $row->rent_rate, 2),
                'period' => $row->period !== null ? (string) $row->period : null,
                'source' => $source,
            ];
        })->values()->all();

        return $this->success('Арендные ставки проверены.', $chips, ['items' => $items]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok:bool,message:string,chips:list<array{label:string,url:string}>,data:array<string,mixed>}
     */
    private function vacantSpaces(int $marketId, array $payload): array
    {
        if (! Schema::hasTable('market_spaces')) {
            return $this->failure('Данные по местам сейчас недоступны.');
        }

        $limit = max(1, min((int) ($payload['limit'] ?? 10), 20));
        $query = MarketSpace::query()
            ->where('market_id', $marketId)
            ->where(function (Builder $builder): void {
                $builder
                    ->whereNull('tenant_id')
                    ->orWhereIn('status', ['free', 'vacant']);
            })
            ->orderByRaw('COALESCE(number, display_name, code)')
            ->limit($limit);

        if (Schema::hasColumn('market_spaces', 'is_active')) {
            $query->where('is_active', true);
        }

        $spaces = $query->get();
        if ($spaces->isEmpty()) {
            return $this->success('Свободные места не найдены.', [], ['items' => []]);
        }

        $chips = [];
        $items = $spaces->map(function (MarketSpace $space) use (&$chips): array {
            $label = $this->spaceLabel($space);
            $chip = $this->spaceChip((int) $space->market_id, (int) $space->id, 'Открыть место: '.$label);
            if ($chip) {
                $chips[] = $chip;
            }

            return [
                'space' => $label,
                'status' => (string) ($space->status ?? ''),
                'area_sqm' => $space->area_sqm !== null ? round((float) $space->area_sqm, 2) : null,
                'rent_rate_rub' => $space->rent_rate_value !== null ? round((float) $space->rent_rate_value, 2) : null,
                'rent_rate_unit' => (string) ($space->rent_rate_unit ?? ''),
            ];
        })->values()->all();

        return $this->success('Свободные места проверены.', $chips, ['items' => $items]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok:bool,message:string,chips:list<array{label:string,url:string}>,data:array<string,mixed>}
     */
    private function tenantProfile(int $marketId, array $payload): array
    {
        $tenant = $this->resolveTenant($marketId, $payload['tenant_id'] ?? null, $payload['tenant_query'] ?? $payload['query'] ?? null);
        if (! $tenant) {
            return $this->failure('Не удалось найти арендатора.');
        }

        $debtAmount = null;
        $debtPeriod = null;
        if (
            Schema::hasTable('contract_debts')
            && Schema::hasColumn('contract_debts', 'market_id')
            && Schema::hasColumn('contract_debts', 'tenant_id')
            && Schema::hasColumn('contract_debts', 'debt_amount')
        ) {
            $debtRow = DB::query()
                ->fromSub(ContractDebt::currentStateQuery($marketId), 'cd')
                ->where('cd.market_id', $marketId)
                ->where('cd.tenant_id', (int) $tenant->id)
                ->selectRaw('SUM(cd.debt_amount) as debt_amount')
                ->selectRaw('MAX(cd.period) as latest_period')
                ->first();
            $debtAmount = $debtRow?->debt_amount !== null ? round((float) $debtRow->debt_amount, 2) : null;
            $debtPeriod = $debtRow?->latest_period !== null ? (string) $debtRow->latest_period : null;
        }

        $spaces = MarketSpace::query()
            ->where('market_id', $marketId)
            ->where('tenant_id', (int) $tenant->id)
            ->orderBy('number')
            ->limit(10)
            ->get();

        $openTicketsCount = Schema::hasTable('tickets')
            ? (int) Ticket::query()
                ->where('market_id', $marketId)
                ->where('tenant_id', (int) $tenant->id)
                ->whereNotIn('status', ['resolved', 'closed', 'cancelled'])
                ->count()
            : 0;

        $activeContractsCount = Schema::hasTable('tenant_contracts')
            ? (int) TenantContract::query()
                ->where('market_id', $marketId)
                ->where('tenant_id', (int) $tenant->id)
                ->where('is_active', true)
                ->count()
            : 0;

        $chips = array_values(array_filter([
            $this->tenantChip($marketId, (int) $tenant->id, 'Открыть арендатора: '.$tenant->display_name),
            ...$spaces
                ->take(3)
                ->map(fn (MarketSpace $space): ?array => $this->spaceChip($marketId, (int) $space->id, 'Открыть место: '.$this->spaceLabel($space)))
                ->all(),
        ]));

        return $this->success('Сводка по арендатору подготовлена.', $chips, [
            'tenant' => [
                'name' => $tenant->display_name,
                'is_active' => (bool) $tenant->is_active,
                'debt_status' => (string) ($tenant->debt_status_label ?? 'Не указано'),
                'debt_amount_rub' => $debtAmount,
                'latest_debt_period' => $debtPeriod,
                'active_contracts_count' => $activeContractsCount,
                'open_tickets_count' => $openTicketsCount,
                'spaces' => $spaces
                    ->map(fn (MarketSpace $space): array => [
                        'space' => $this->spaceLabel($space),
                        'status' => (string) ($space->status ?? ''),
                    ])
                    ->values()
                    ->all(),
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok:bool,message:string,chips:list<array{label:string,url:string}>,data:array<string,mixed>}
     */
    private function openTicketsSummary(int $marketId, array $payload): array
    {
        if (! Schema::hasTable('tickets')) {
            return $this->failure('Данные по обращениям сейчас недоступны.');
        }

        $limit = max(1, min((int) ($payload['limit'] ?? 10), 20));
        $tickets = Ticket::query()
            ->with(['tenant:id,name,short_name', 'marketSpace:id,number,display_name,code,market_id'])
            ->where('market_id', $marketId)
            ->whereNotIn('status', ['resolved', 'closed', 'cancelled'])
            ->orderByRaw("CASE priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 ELSE 3 END")
            ->latest('updated_at')
            ->limit($limit)
            ->get();

        if ($tickets->isEmpty()) {
            return $this->success('Открытые обращения не найдены.', [], ['items' => []]);
        }

        $chips = [];
        $items = $tickets->map(function (Ticket $ticket) use ($marketId, &$chips): array {
            $chip = $this->ticketChip($marketId, (int) $ticket->id, 'Открыть обращение: '.$ticket->subject);
            if ($chip && count($chips) < 8) {
                $chips[] = $chip;
            }

            return [
                'subject' => (string) $ticket->subject,
                'tenant_name' => (string) ($ticket->tenant?->display_name ?? ''),
                'space' => $ticket->marketSpace instanceof MarketSpace ? $this->spaceLabel($ticket->marketSpace) : '',
                'status' => (string) ($ticket->status ?? ''),
                'priority' => (string) ($ticket->priority ?? ''),
                'updated_at' => $ticket->updated_at?->timezone(config('app.timezone'))->format('d.m.Y H:i'),
            ];
        })->values()->all();

        return $this->success('Открытые обращения проверены.', $chips, ['items' => $items]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok:bool,message:string,chips:list<array{label:string,url:string}>,data:array<string,mixed>}
     */
    private function expiringContracts(int $marketId, array $payload): array
    {
        if (! Schema::hasTable('tenant_contracts')) {
            return $this->failure('Данные по договорам сейчас недоступны.');
        }

        $days = max(1, min((int) ($payload['days'] ?? 30), 365));
        $limit = max(1, min((int) ($payload['limit'] ?? 10), 20));
        $from = now()->toDateString();
        $to = now()->addDays($days)->toDateString();

        $contracts = TenantContract::query()
            ->with(['tenant:id,market_id,name,short_name', 'marketSpace:id,market_id,number,display_name,code'])
            ->where('market_id', $marketId)
            ->where('is_active', true)
            ->whereNotNull('ends_at')
            ->whereBetween('ends_at', [$from, $to])
            ->orderBy('ends_at')
            ->limit($limit)
            ->get();

        if ($contracts->isEmpty()) {
            return $this->success('Договоры с ближайшим окончанием не найдены.', [], ['items' => []]);
        }

        $chips = [];
        $items = $contracts->map(function (TenantContract $contract) use ($marketId, &$chips): array {
            $tenant = $contract->tenant;
            $space = $contract->marketSpace;
            $label = trim((string) ($contract->number ?? '')) !== ''
                ? 'Открыть договор: '.$contract->number
                : 'Открыть договор';
            $chips[] = $this->chip($label, $this->contractUrl($contract));

            return [
                'contract_number' => (string) ($contract->number ?? ''),
                'tenant_name' => $tenant instanceof Tenant ? $tenant->display_name : '',
                'space' => $space instanceof MarketSpace ? $this->spaceLabel($space) : '',
                'ends_at' => $contract->ends_at?->format('d.m.Y'),
                'monthly_rent_rub' => $contract->monthly_rent !== null ? round((float) $contract->monthly_rent, 2) : null,
                'status' => (string) ($contract->status ?? ''),
            ];
        })->values()->all();

        return $this->success('Договоры с ближайшим окончанием проверены.', array_slice($chips, 0, 8), ['items' => $items]);
    }

    private function canUseMarket(User $actor, int $marketId): bool
    {
        if ($marketId <= 0) {
            return false;
        }

        if ($actor->isSuperAdmin()) {
            return true;
        }

        return (int) ($actor->market_id ?? 0) === $marketId
            && (int) ($actor->tenant_id ?? 0) <= 0;
    }

    private function canManageEvents(User $actor, int $marketId): bool
    {
        if ($actor->isSuperAdmin()) {
            return true;
        }

        return (int) ($actor->market_id ?? 0) === $marketId
            && method_exists($actor, 'hasRole')
            && $actor->hasRole('market-admin');
    }

    private function resolveStaffUser(User $actor, int $marketId, mixed $id, mixed $query): ?User
    {
        $userId = $this->nullableInt($id);
        $staff = null;

        if ($userId) {
            $staff = User::query()->whereKey($userId)->first();
        } else {
            $search = trim((string) $query);
            if ($search === '') {
                return null;
            }

            $needle = mb_strtolower($search);
            $staff = User::query()
                ->where(function (Builder $builder): void {
                    $builder->whereNull('tenant_id')->orWhere('tenant_id', 0);
                })
                ->where(function (Builder $builder) use ($needle): void {
                    $builder
                        ->whereRaw('lower(name) like ?', ['%'.$needle.'%'])
                        ->orWhereRaw('lower(email) like ?', ['%'.$needle.'%']);
                })
                ->orderBy('name')
                ->first();
        }

        if (! $staff || ! $this->canAccessStaffPeer($actor, $staff, $marketId)) {
            return null;
        }

        return $staff;
    }

    private function canAccessStaffPeer(User $actor, User $peer, int $marketId): bool
    {
        if ((int) $actor->id === (int) $peer->id) {
            return false;
        }

        if ((int) ($peer->tenant_id ?? 0) > 0) {
            return false;
        }

        if ($actor->isSuperAdmin()) {
            return (int) ($peer->market_id ?? 0) === $marketId || $peer->isSuperAdmin();
        }

        return (int) ($actor->market_id ?? 0) === $marketId
            && (
                (int) ($peer->market_id ?? 0) === $marketId
                || $peer->isSuperAdmin()
            );
    }

    private function resolveTenant(int $marketId, mixed $id, mixed $query): ?Tenant
    {
        $tenantId = $this->nullableInt($id);

        if ($tenantId) {
            return Tenant::query()
                ->whereKey($tenantId)
                ->where('market_id', $marketId)
                ->first();
        }

        $search = trim((string) $query);
        if ($search === '') {
            return null;
        }

        $needle = mb_strtolower($search);
        $pattern = $this->likePattern($search);
        $operator = Tenant::query()->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

        $tenant = Tenant::query()
            ->where('market_id', $marketId)
            ->where(function (Builder $builder) use ($operator, $pattern): void {
                $builder
                    ->where('name', $operator, $pattern)
                    ->orWhere('short_name', $operator, $pattern)
                    ->orWhere('inn', $operator, $pattern);
            })
            ->orderByRaw('CASE WHEN lower(short_name) = ? OR lower(name) = ? THEN 0 ELSE 1 END', [$needle, $needle])
            ->orderBy('short_name')
            ->orderBy('name')
            ->first();

        if ($tenant instanceof Tenant) {
            return $tenant;
        }

        return Tenant::query()
            ->where('market_id', $marketId)
            ->orderBy('short_name')
            ->orderBy('name')
            ->limit(500)
            ->get()
            ->first(function (Tenant $candidate) use ($search): bool {
                return LooseSearch::matchesText(implode(' ', array_filter([
                    (string) $candidate->name,
                    (string) $candidate->short_name,
                    (string) $candidate->inn,
                ])), $search);
            });
    }

    private function resolveSpace(int $marketId, mixed $id, mixed $query): ?MarketSpace
    {
        $spaceId = $this->nullableInt($id);

        if ($spaceId) {
            return MarketSpace::query()
                ->whereKey($spaceId)
                ->where('market_id', $marketId)
                ->first();
        }

        $search = trim((string) $query);
        if ($search === '') {
            return null;
        }

        $needle = Str::lower($search);
        $pattern = $this->likePattern($needle);

        return MarketSpace::query()
            ->where('market_id', $marketId)
            ->where(function (Builder $builder) use ($pattern): void {
                $builder
                    ->whereRaw('lower(number) like ?', [$pattern])
                    ->orWhereRaw('lower(display_name) like ?', [$pattern])
                    ->orWhereRaw('lower(code) like ?', [$pattern]);
            })
            ->orderByRaw('CASE WHEN lower(number) = ? OR lower(display_name) = ? OR lower(code) = ? THEN 0 ELSE 1 END', [$needle, $needle, $needle])
            ->orderBy('number')
            ->first();
    }

    private function resolveTask(int $marketId, mixed $id, mixed $query): ?Task
    {
        $taskId = $this->nullableInt($id);

        if ($taskId) {
            return Task::query()
                ->whereKey($taskId)
                ->where('market_id', $marketId)
                ->first();
        }

        $search = trim((string) $query);
        if ($search === '') {
            return null;
        }

        return Task::query()
            ->where('market_id', $marketId)
            ->whereRaw('lower(title) like ?', [$this->likePattern(Str::lower($search))])
            ->latest('updated_at')
            ->first();
    }

    private function resolveTicket(int $marketId, mixed $id, mixed $query): ?Ticket
    {
        $ticketId = $this->nullableInt($id);

        if ($ticketId) {
            return Ticket::query()
                ->whereKey($ticketId)
                ->where('market_id', $marketId)
                ->first();
        }

        $search = trim((string) $query);
        if ($search === '') {
            return null;
        }

        return Ticket::query()
            ->where('market_id', $marketId)
            ->whereRaw('lower(subject) like ?', [$this->likePattern(Str::lower($search))])
            ->latest('updated_at')
            ->first();
    }

    private function resolveEvent(int $marketId, mixed $id, mixed $query): ?MarketHoliday
    {
        $eventId = $this->nullableInt($id);

        if ($eventId) {
            return MarketHoliday::query()
                ->whereKey($eventId)
                ->where('market_id', $marketId)
                ->first();
        }

        $search = trim((string) $query);
        if ($search === '') {
            return null;
        }

        return MarketHoliday::query()
            ->where('market_id', $marketId)
            ->whereRaw('lower(title) like ?', [$this->likePattern(Str::lower($search))])
            ->latest('starts_at')
            ->first();
    }

    private function spaceIdForTenant(Tenant $tenant, mixed $spaceId): ?int
    {
        $id = $this->nullableInt($spaceId);
        if (! $id) {
            return null;
        }

        return MarketSpace::query()
            ->whereKey($id)
            ->where('market_id', (int) $tenant->market_id)
            ->where('tenant_id', (int) $tenant->id)
            ->exists()
                ? $id
                : null;
    }

    private function tenantChip(int $marketId, int $id, string $label): ?array
    {
        $tenant = Tenant::query()->whereKey($id)->where('market_id', $marketId)->first();

        return $tenant ? $this->chip($label !== '' ? $label : 'Арендатор: '.$tenant->display_name, $this->tenantUrl($tenant)) : null;
    }

    private function spaceChip(int $marketId, int $id, string $label): ?array
    {
        $space = MarketSpace::query()->whereKey($id)->where('market_id', $marketId)->first();

        return $space ? $this->chip($label !== '' ? $label : 'Место: '.$this->spaceLabel($space), $this->spaceUrl($space)) : null;
    }

    private function taskChip(int $marketId, int $id, string $label): ?array
    {
        $task = Task::query()->whereKey($id)->where('market_id', $marketId)->first();

        return $task ? $this->chip($label !== '' ? $label : 'Задача: '.$task->title, $this->taskUrl($task)) : null;
    }

    private function ticketChip(int $marketId, int $id, string $label): ?array
    {
        $ticket = Ticket::query()->whereKey($id)->where('market_id', $marketId)->first();

        return $ticket ? $this->chip($label !== '' ? $label : 'Обращение: '.$ticket->subject, $this->ticketUrl($ticket)) : null;
    }

    private function eventChip(int $marketId, int $id, string $label): ?array
    {
        $event = MarketHoliday::query()->whereKey($id)->where('market_id', $marketId)->first();

        return $event ? $this->chip($label !== '' ? $label : 'Событие: '.$event->title, $this->eventUrl($event)) : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function currentPageChip(array $payload, string $label): ?array
    {
        $url = $this->internalUrl($payload['url'] ?? null);
        if (! $url) {
            return null;
        }

        return $this->chip($label !== '' ? $label : 'Текущая страница', $url);
    }

    private function tenantChipForPayload(int $marketId, ?int $id, mixed $query, string $label): ?array
    {
        $tenant = $id
            ? Tenant::query()->whereKey($id)->where('market_id', $marketId)->first()
            : $this->resolveTenant($marketId, null, $query);

        return $tenant ? $this->chip($label !== '' ? $label : 'Арендатор: '.$tenant->display_name, $this->tenantUrl($tenant)) : null;
    }

    private function spaceChipForPayload(int $marketId, ?int $id, mixed $query, string $label): ?array
    {
        $space = $id
            ? MarketSpace::query()->whereKey($id)->where('market_id', $marketId)->first()
            : $this->resolveSpace($marketId, null, $query);

        return $space ? $this->chip($label !== '' ? $label : 'Место: '.$this->spaceLabel($space), $this->spaceUrl($space)) : null;
    }

    private function taskChipForPayload(int $marketId, ?int $id, mixed $query, string $label): ?array
    {
        $task = $id
            ? Task::query()->whereKey($id)->where('market_id', $marketId)->first()
            : $this->resolveTask($marketId, null, $query);

        return $task ? $this->chip($label !== '' ? $label : 'Задача: '.$task->title, $this->taskUrl($task)) : null;
    }

    private function ticketChipForPayload(int $marketId, ?int $id, mixed $query, string $label): ?array
    {
        $ticket = $id
            ? Ticket::query()->whereKey($id)->where('market_id', $marketId)->first()
            : $this->resolveTicket($marketId, null, $query);

        return $ticket ? $this->chip($label !== '' ? $label : 'Обращение: '.$ticket->subject, $this->ticketUrl($ticket)) : null;
    }

    private function eventChipForPayload(int $marketId, ?int $id, mixed $query, string $label): ?array
    {
        $event = $id
            ? MarketHoliday::query()->whereKey($id)->where('market_id', $marketId)->first()
            : $this->resolveEvent($marketId, null, $query);

        return $event ? $this->chip($label !== '' ? $label : 'Событие: '.$event->title, $this->eventUrl($event)) : null;
    }

    /**
     * @param  list<array<string,mixed>>  $items
     * @return array{ok:bool,message:string,chips:list<array{label:string,url:string}>,data:array<string,mixed>}
     */
    private function resourceSearchResult(string $message, array $items): array
    {
        $chips = [];
        $data = [];

        foreach ($items as $item) {
            $chip = $item['chip'] ?? null;
            unset($item['chip']);

            if (is_array($chip)) {
                $chips[] = $chip;
            }

            $data[] = $item;
        }

        return $this->success($items === [] ? 'Ничего не найдено.' : $message, $chips, ['items' => $data]);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function searchTenants(int $marketId, string $query, int $limit): array
    {
        $needle = Str::lower($query);
        $pattern = $this->likePattern($query);
        $operator = Tenant::query()->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

        $tenants = Tenant::query()
            ->where('market_id', $marketId)
            ->where(function (Builder $builder) use ($operator, $pattern): void {
                $builder
                    ->where('name', $operator, $pattern)
                    ->orWhere('short_name', $operator, $pattern)
                    ->orWhere('inn', $operator, $pattern);
            })
            ->orderByRaw('CASE WHEN lower(short_name) = ? OR lower(name) = ? THEN 0 ELSE 1 END', [$needle, $needle])
            ->orderByDesc('is_active')
            ->orderBy('short_name')
            ->orderBy('name')
            ->limit($limit)
            ->get();

        if ($tenants->isEmpty()) {
            $tenants = Tenant::query()
                ->where('market_id', $marketId)
                ->orderByDesc('is_active')
                ->orderBy('short_name')
                ->orderBy('name')
                ->limit(500)
                ->get()
                ->filter(function (Tenant $tenant) use ($query): bool {
                    return LooseSearch::matchesText(implode(' ', array_filter([
                        (string) $tenant->name,
                        (string) $tenant->short_name,
                        (string) $tenant->inn,
                    ])), $query);
                })
                ->take($limit)
                ->values();
        }

        return $tenants
            ->map(fn (Tenant $tenant): array => [
                'resource_type' => 'tenant',
                'tenant_id' => (int) $tenant->id,
                'tenant_name' => $tenant->display_name,
                'is_active' => (bool) $tenant->is_active,
                'debt_status' => (string) ($tenant->debt_status ?? ''),
                'chip' => $this->tenantChip((int) $tenant->market_id, (int) $tenant->id, 'Открыть арендатора: '.$tenant->display_name),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function searchSpaces(int $marketId, string $query, int $limit): array
    {
        $needle = Str::lower($query);
        $pattern = $this->likePattern($needle);

        return MarketSpace::query()
            ->where('market_id', $marketId)
            ->where(function (Builder $builder) use ($pattern): void {
                $builder
                    ->whereRaw('lower(number) like ?', [$pattern])
                    ->orWhereRaw('lower(display_name) like ?', [$pattern])
                    ->orWhereRaw('lower(code) like ?', [$pattern]);
            })
            ->orderByRaw('CASE WHEN lower(number) = ? OR lower(display_name) = ? OR lower(code) = ? THEN 0 ELSE 1 END', [$needle, $needle, $needle])
            ->orderBy('number')
            ->limit($limit)
            ->get()
            ->map(fn (MarketSpace $space): array => [
                'resource_type' => 'space',
                'market_space_id' => (int) $space->id,
                'space' => $this->spaceLabel($space),
                'status' => (string) ($space->status ?? ''),
                'chip' => $this->spaceChip((int) $space->market_id, (int) $space->id, 'Открыть место: '.$this->spaceLabel($space)),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function searchTasks(int $marketId, string $query, int $limit): array
    {
        $pattern = $this->likePattern(Str::lower($query));

        return Task::query()
            ->where('market_id', $marketId)
            ->whereRaw('lower(title) like ?', [$pattern])
            ->latest('updated_at')
            ->limit($limit)
            ->get()
            ->map(fn (Task $task): array => [
                'resource_type' => 'task',
                'task_id' => (int) $task->id,
                'title' => (string) $task->title,
                'status' => (string) ($task->status ?? ''),
                'chip' => $this->taskChip((int) $task->market_id, (int) $task->id, 'Открыть задачу: '.$task->title),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function searchTickets(int $marketId, string $query, int $limit): array
    {
        $pattern = $this->likePattern(Str::lower($query));

        return Ticket::query()
            ->where('market_id', $marketId)
            ->whereRaw('lower(subject) like ?', [$pattern])
            ->latest('updated_at')
            ->limit($limit)
            ->get()
            ->map(fn (Ticket $ticket): array => [
                'resource_type' => 'ticket',
                'ticket_id' => (int) $ticket->id,
                'subject' => (string) $ticket->subject,
                'status' => (string) ($ticket->status ?? ''),
                'chip' => $this->ticketChip((int) $ticket->market_id, (int) $ticket->id, 'Открыть обращение: '.$ticket->subject),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function searchEvents(int $marketId, string $query, int $limit): array
    {
        $pattern = $this->likePattern(Str::lower($query));

        return MarketHoliday::query()
            ->where('market_id', $marketId)
            ->whereRaw('lower(title) like ?', [$pattern])
            ->latest('starts_at')
            ->limit($limit)
            ->get()
            ->map(fn (MarketHoliday $event): array => [
                'resource_type' => 'event',
                'event_id' => (int) $event->id,
                'title' => (string) $event->title,
                'starts_at' => $event->starts_at?->toDateString(),
                'chip' => $this->eventChip((int) $event->market_id, (int) $event->id, 'Открыть событие: '.$event->title),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function searchStaff(User $actor, int $marketId, string $query, int $limit): array
    {
        $pattern = $this->likePattern(Str::lower($query));

        return User::query()
            ->where(function (Builder $builder): void {
                $builder->whereNull('tenant_id')->orWhere('tenant_id', 0);
            })
            ->where(function (Builder $builder) use ($pattern): void {
                $builder
                    ->whereRaw('lower(name) like ?', [$pattern])
                    ->orWhereRaw('lower(email) like ?', [$pattern]);
            })
            ->orderBy('name')
            ->limit(max(50, $limit * 5))
            ->get()
            ->filter(fn (User $user): bool => $this->canAccessStaffPeer($actor, $user, $marketId))
            ->take($limit)
            ->map(static fn (User $user): array => [
                'resource_type' => 'staff',
                'user_id' => (int) $user->id,
                'name' => (string) $user->name,
            ])
            ->values()
            ->all();
    }

    private function taskUrl(Task $task): string
    {
        return $this->resourceUrl(TaskResource::class, 'edit', ['record' => $task], '/admin/tasks/'.(int) $task->id.'/edit');
    }

    private function eventUrl(MarketHoliday $event): string
    {
        return $this->resourceUrl(MarketHolidayResource::class, 'edit', ['record' => $event], '/admin/market-holidays/'.(int) $event->id.'/edit');
    }

    private function tenantUrl(Tenant $tenant): string
    {
        return $this->resourceUrl(TenantResource::class, 'edit', ['record' => $tenant], '/admin/tenants/'.(int) $tenant->id.'/edit');
    }

    private function spaceUrl(MarketSpace $space): string
    {
        return $this->resourceUrl(MarketSpaceResource::class, 'edit', ['record' => $space], '/admin/market-spaces/'.(int) $space->id.'/edit');
    }

    private function ticketUrl(Ticket $ticket): string
    {
        try {
            return Requests::getUrl().'?quick_chat=ticket&ticket_id='.(int) $ticket->id;
        } catch (Throwable) {
            return '/admin/requests?quick_chat=ticket&ticket_id='.(int) $ticket->id;
        }
    }

    private function contractUrl(TenantContract $contract): string
    {
        return $this->resourceUrl(TenantContractResource::class, 'edit', ['record' => $contract], '/admin/tenant-contracts/'.(int) $contract->id.'/edit');
    }

    private function staffConversationUrl(int $conversationId): string
    {
        try {
            return Requests::getUrl().'?quick_chat=staff&conversation_id='.$conversationId;
        } catch (Throwable) {
            return '/admin/requests?quick_chat=staff&conversation_id='.$conversationId;
        }
    }

    /**
     * @param  class-string  $resourceClass
     * @param  array<string, mixed>  $parameters
     */
    private function resourceUrl(string $resourceClass, string $page, array $parameters, string $fallback): string
    {
        try {
            if (method_exists($resourceClass, 'getUrl')) {
                return (string) $resourceClass::getUrl($page, $parameters);
            }
        } catch (Throwable) {
            // Fallback keeps the chip usable if Filament cannot resolve a panel in the current context.
        }

        return $fallback;
    }

    private function parseDateTime(mixed $value): ?Carbon
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value, config('app.timezone'));
        } catch (Throwable) {
            return null;
        }
    }

    private function priority(mixed $value): string
    {
        $priority = strtolower(trim((string) $value));

        return in_array($priority, [
            Task::PRIORITY_LOW,
            Task::PRIORITY_NORMAL,
            Task::PRIORITY_HIGH,
            Task::PRIORITY_URGENT,
        ], true) ? $priority : Task::PRIORITY_NORMAL;
    }

    private function string(mixed $value, int $limit): string
    {
        return Str::limit(trim((string) $value), $limit, '');
    }

    private function likePattern(string $value): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value).'%';
    }

    private function nullableInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $id = (int) $value;

        return $id > 0 ? $id : null;
    }

    private function internalUrl(mixed $value): ?string
    {
        $url = trim((string) $value);
        if ($url === '') {
            return null;
        }

        if (str_starts_with($url, '/admin')) {
            return $url;
        }

        $appUrl = rtrim((string) config('app.url'), '/');
        if ($appUrl !== '' && str_starts_with($url, $appUrl.'/admin')) {
            return $url;
        }

        return null;
    }

    private function spaceLabel(MarketSpace $space): string
    {
        foreach (['name', 'number', 'code'] as $field) {
            $value = trim((string) ($space->{$field} ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '#'.(int) $space->id;
    }

    /**
     * @return array{label:string,url:string}
     */
    private function chip(string $label, string $url): array
    {
        return [
            'label' => Str::limit(trim($label), 120, ''),
            'url' => $url,
        ];
    }

    /**
     * @param  list<array{label:string,url:string}>  $chips
     * @param  array<string,mixed>  $data
     * @return array{ok:bool,message:string,chips:list<array{label:string,url:string}>,data:array<string,mixed>}
     */
    private function success(string $message, array $chips = [], array $data = []): array
    {
        return [
            'ok' => true,
            'message' => $message,
            'chips' => array_values($chips),
            'data' => $data,
        ];
    }

    /**
     * @return array{ok:bool,message:string,chips:list<array{label:string,url:string}>,data:array<string,mixed>}
     */
    private function failure(string $message): array
    {
        return [
            'ok' => false,
            'message' => $message,
            'chips' => [],
            'data' => [],
        ];
    }
}
