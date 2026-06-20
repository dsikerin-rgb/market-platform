<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Filament\Pages\Requests;
use App\Filament\Resources\MarketHolidayResource;
use App\Filament\Resources\MarketSpaceResource;
use App\Filament\Resources\TaskResource;
use App\Filament\Resources\TenantResource;
use App\Models\MarketHoliday;
use App\Models\MarketSpace;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\User;
use App\Support\StaffConversationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
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
                'resource_link', 'make_link' => $this->resourceLink($actor, $marketId, $payload),
                default => $this->failure('Неизвестное действие агента.'),
            };
        } catch (Throwable $exception) {
            report($exception);

            return $this->failure('Действие не выполнено из-за внутренней ошибки приложения.');
        }
    }

    public function schemaHint(): string
    {
        return <<<'PROMPT'

Если для выполнения просьбы сотрудника нужно действие в сервисе, сначала верни только JSON одного из видов:
{"tool":"create_task","title":"...","description":"...","due_at":"2026-06-21 10:00","assignee_user_id":123,"assignee_query":"имя сотрудника","priority":"normal"}
{"tool":"create_reminder","title":"...","description":"...","due_at":"2026-06-21 10:00","assignee_user_id":123}
{"tool":"create_event","title":"...","description":"...","starts_at":"2026-06-21","ends_at":"2026-06-21","all_day":true}
{"tool":"send_staff_message","recipient_user_id":123,"recipient_query":"имя сотрудника","subject":"...","message":"..."}
{"tool":"send_tenant_message","tenant_id":123,"tenant_query":"название арендатора","subject":"...","message":"...","market_space_id":456}
{"tool":"resource_link","resource_type":"tenant|space|task|ticket|event|settings|current_page","id":123,"label":"понятное название"}

Используй действия только когда сотрудник просит выполнить работу, отправить сообщение, создать задачу, событие, напоминание или дать ссылку на запись. Не показывай JSON пользователю. После результата действия отвечай простым русским языком и опирайся на приложенные ссылки.
PROMPT;
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

        $assignee = $this->resolveStaffUser($actor, $marketId, $payload['assignee_user_id'] ?? null, $payload['assignee_query'] ?? null);
        if (($payload['assignee_user_id'] ?? null) || ($payload['assignee_query'] ?? null)) {
            if (! $assignee) {
                return $this->failure('Не удалось найти сотрудника для назначения.');
            }
        }

        $priority = $this->priority($payload['priority'] ?? null);
        $description = $this->string($payload['description'] ?? $payload['message'] ?? null, 4000);
        if ($isReminder) {
            $description = trim($description."\n\nСоздано ИИ-агентом как напоминание.");
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
            ['task_id' => (int) $task->id],
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
        $label = $this->string($payload['label'] ?? null, 120);

        $chip = match ($type) {
            'tenant' => $id ? $this->tenantChip($marketId, $id, $label) : null,
            'space', 'market_space' => $id ? $this->spaceChip($marketId, $id, $label) : null,
            'task' => $id ? $this->taskChip($marketId, $id, $label) : null,
            'ticket', 'request' => $id ? $this->ticketChip($marketId, $id, $label) : null,
            'event', 'holiday' => $id ? $this->eventChip($marketId, $id, $label) : null,
            'settings', 'ai_settings' => $this->chip($label !== '' ? $label : 'Настройки ИИ-агента', '/admin/ai-agent-settings'),
            'current_page' => $this->currentPageChip($payload, $label),
            default => null,
        };

        if (! $chip) {
            return $this->failure('Не удалось подготовить ссылку на ресурс.');
        }

        return $this->success('Ссылка подготовлена.', [$chip], ['resource_type' => $type, 'id' => $id]);
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

        return Tenant::query()
            ->where('market_id', $marketId)
            ->where(function (Builder $builder) use ($needle): void {
                $builder
                    ->whereRaw('lower(name) like ?', ['%'.$needle.'%'])
                    ->orWhereRaw('lower(short_name) like ?', ['%'.$needle.'%'])
                    ->orWhereRaw('lower(inn) like ?', ['%'.$needle.'%']);
            })
            ->orderBy('short_name')
            ->orderBy('name')
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
