<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\AiAgentAuditEvent;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class AiAgentAuditService
{
    public const EVENT_TOOL_CALL = 'tool_call';

    public const EVENT_ACTION_PREPARED = 'action_prepared';

    public const EVENT_ACTION_DENIED = 'action_denied';

    public const EVENT_ACTION_CANCELLED = 'action_cancelled';

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $result
     */
    public function recordToolResult(
        User $actor,
        int $marketId,
        string $tool,
        array $payload,
        array $result,
        int $durationMs = 0,
        ?int $aiConversationId = null,
        ?int $aiMessageId = null,
        string $eventType = self::EVENT_TOOL_CALL,
    ): ?AiAgentAuditEvent {
        $status = (bool) ($result['ok'] ?? false) ? 'success' : 'failed';

        return $this->record([
            'market_id' => $marketId > 0 ? $marketId : null,
            'user_id' => (int) $actor->id ?: null,
            'ai_conversation_id' => $aiConversationId,
            'ai_message_id' => $aiMessageId,
            'event_type' => $eventType,
            'tool' => $this->toolName($tool),
            'status' => $status,
            'title' => $this->toolLabel($tool),
            'summary' => $this->summaryFromResult($payload, $result),
            'request_payload' => $this->sanitizePayload($payload),
            'result_payload' => $this->sanitizePayload((array) ($result['data'] ?? [])),
            'result_message' => Str::limit(trim((string) ($result['message'] ?? '')), 1000, ''),
            'chips' => $this->sanitizeChips((array) ($result['chips'] ?? [])),
            'duration_ms' => max(0, $durationMs),
            'error_type' => $status === 'failed' ? 'tool_result' : null,
        ]);
    }

    /**
     * @param  array<string,mixed>  $pendingAction
     */
    public function recordPreparedAction(
        User $actor,
        int $marketId,
        array $pendingAction,
        ?int $aiConversationId = null,
        ?int $aiMessageId = null,
    ): ?AiAgentAuditEvent {
        $payload = (array) ($pendingAction['payload'] ?? []);
        $tool = $this->toolName((string) ($pendingAction['tool'] ?? $payload['tool'] ?? ''));

        return $this->record([
            'market_id' => $marketId > 0 ? $marketId : null,
            'user_id' => (int) $actor->id ?: null,
            'ai_conversation_id' => $aiConversationId,
            'ai_message_id' => $aiMessageId,
            'event_type' => self::EVENT_ACTION_PREPARED,
            'tool' => $tool,
            'status' => 'pending',
            'title' => Str::limit(trim((string) ($pendingAction['title'] ?? $this->toolLabel($tool))), 255, ''),
            'summary' => $this->sanitizeSummary((array) ($pendingAction['summary'] ?? [])),
            'request_payload' => $this->sanitizePayload($payload),
            'result_message' => 'Подготовлено и ожидает подтверждения пользователя.',
        ]);
    }

    /**
     * @param  array<string,mixed>  $pendingAction
     */
    public function recordActionStatus(
        User $actor,
        int $marketId,
        array $pendingAction,
        string $status,
        string $message = '',
        ?int $aiConversationId = null,
        ?int $aiMessageId = null,
    ): ?AiAgentAuditEvent {
        $payload = (array) ($pendingAction['payload'] ?? []);
        $tool = $this->toolName((string) ($pendingAction['tool'] ?? $payload['tool'] ?? ''));

        return $this->record([
            'market_id' => $marketId > 0 ? $marketId : null,
            'user_id' => (int) $actor->id ?: null,
            'ai_conversation_id' => $aiConversationId,
            'ai_message_id' => $aiMessageId,
            'event_type' => $status === 'cancelled' ? self::EVENT_ACTION_CANCELLED : self::EVENT_ACTION_DENIED,
            'tool' => $tool,
            'status' => in_array($status, ['cancelled', 'failed'], true) ? $status : 'failed',
            'title' => Str::limit(trim((string) ($pendingAction['title'] ?? $this->toolLabel($tool))), 255, ''),
            'summary' => $this->sanitizeSummary((array) ($pendingAction['summary'] ?? [])),
            'request_payload' => $this->sanitizePayload($payload),
            'result_message' => Str::limit(trim($message), 1000, ''),
            'error_type' => $status === 'cancelled' ? null : 'action_denied',
        ]);
    }

    /**
     * @param  array<string,mixed>  $attributes
     */
    public function record(array $attributes): ?AiAgentAuditEvent
    {
        try {
            if (! Schema::hasTable('ai_agent_audit_events')) {
                return null;
            }

            return AiAgentAuditEvent::query()->create($attributes);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    public function eventLabel(string $eventType): string
    {
        return match ($eventType) {
            self::EVENT_ACTION_PREPARED => 'Подготовил действие',
            self::EVENT_ACTION_DENIED => 'Отклонил действие',
            self::EVENT_ACTION_CANCELLED => 'Отменил действие',
            default => 'Проверил данные',
        };
    }

    public function statusLabel(string $status): string
    {
        return match ($status) {
            'success' => 'Выполнено',
            'failed' => 'Не выполнено',
            'pending' => 'Ожидает подтверждения',
            'cancelled' => 'Отменено',
            default => 'Записано',
        };
    }

    public function toolLabel(string $tool): string
    {
        return match ($this->toolName($tool)) {
            'read_sql' => 'Проверка данных',
            'find_resource', 'find_record' => 'Поиск ресурса',
            'resource_link', 'make_link' => 'Ссылка на ресурс',
            'debt_leaders', 'top_debt_tenants' => 'Крупнейшие долги',
            'rent_rate_extremes', 'rent_rates' => 'Арендные ставки',
            'vacant_spaces', 'free_spaces' => 'Свободные места',
            'tenant_profile', 'tenant_summary' => 'Сводка по арендатору',
            'open_tickets_summary', 'ticket_summary' => 'Сводка обращений',
            'expiring_contracts', 'contract_expirations' => 'Договоры к окончанию',
            'create_task' => 'Создание задачи',
            'create_reminder' => 'Создание напоминания',
            'create_event' => 'Создание события',
            'send_staff_message' => 'Сообщение сотруднику',
            'send_tenant_message' => 'Сообщение арендатору',
            'update_my_profile', 'profile_update' => 'Обновление профиля',
            'remember_knowledge', 'remember_fact' => 'Запись в справочник',
            default => 'Действие агента',
        };
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $result
     * @return list<array{label:string,value:string}>
     */
    private function summaryFromResult(array $payload, array $result): array
    {
        $rows = [];
        $tool = $this->toolName((string) ($payload['tool'] ?? $payload['name'] ?? ''));
        $query = trim((string) ($payload['query'] ?? $payload['tenant_query'] ?? $payload['assignee_query'] ?? $payload['recipient_query'] ?? ''));
        $title = trim((string) ($payload['title'] ?? $payload['subject'] ?? ''));

        if ($query !== '') {
            $rows[] = ['label' => 'Запрос', 'value' => Str::limit($query, 180, '')];
        }

        if ($title !== '') {
            $rows[] = ['label' => 'Название', 'value' => Str::limit($title, 180, '')];
        }

        $items = data_get($result, 'data.items');
        if (is_array($items)) {
            $rows[] = ['label' => 'Найдено', 'value' => (string) count($items)];
        }

        $sql = trim((string) ($payload['sql'] ?? ''));
        if ($tool === 'read_sql' && $sql !== '') {
            $rows[] = ['label' => 'Проверка', 'value' => Str::limit(preg_replace('/\s+/u', ' ', $sql) ?: $sql, 180, '')];
        }

        $message = trim((string) ($result['message'] ?? ''));
        if ($message !== '') {
            $rows[] = ['label' => 'Результат', 'value' => Str::limit($message, 180, '')];
        }

        return $rows;
    }

    /**
     * @param  array<int,mixed>  $summary
     * @return list<array{label:string,value:string}>
     */
    private function sanitizeSummary(array $summary): array
    {
        return collect($summary)
            ->filter(static fn (mixed $row): bool => is_array($row))
            ->map(static fn (array $row): array => [
                'label' => Str::limit(trim((string) ($row['label'] ?? '')), 80, ''),
                'value' => Str::limit(trim((string) ($row['value'] ?? '')), 240, ''),
            ])
            ->filter(static fn (array $row): bool => $row['label'] !== '' && $row['value'] !== '')
            ->values()
            ->all();
    }

    /**
     * @param  array<int,mixed>  $chips
     * @return list<array{label:string,url:string}>
     */
    private function sanitizeChips(array $chips): array
    {
        return collect($chips)
            ->filter(static fn (mixed $chip): bool => is_array($chip))
            ->map(static fn (array $chip): array => [
                'label' => Str::limit(trim((string) ($chip['label'] ?? '')), 120, ''),
                'url' => Str::limit(trim((string) ($chip['url'] ?? '')), 500, ''),
            ])
            ->filter(static fn (array $chip): bool => $chip['label'] !== '' && $chip['url'] !== '')
            ->take(12)
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function sanitizePayload(array $payload, int $depth = 0): array
    {
        if ($depth >= 4) {
            return ['_truncated' => true];
        }

        $result = [];
        foreach (array_slice($payload, 0, 40, true) as $key => $value) {
            $key = (string) $key;
            if ($this->isSensitiveKey($key)) {
                $result[$key] = '[hidden]';
                continue;
            }

            if (is_array($value)) {
                $result[$key] = array_is_list($value)
                    ? array_map(
                        fn (mixed $item): mixed => is_array($item)
                            ? $this->sanitizePayload($item, $depth + 1)
                            : $this->sanitizeScalar($item),
                        array_slice($value, 0, 30),
                    )
                    : $this->sanitizePayload($value, $depth + 1);
                continue;
            }

            $result[$key] = $this->sanitizeScalar($value);
        }

        return $result;
    }

    private function sanitizeScalar(mixed $value): mixed
    {
        if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
            return $value;
        }

        return Str::limit(trim((string) $value), 1000, '');
    }

    private function isSensitiveKey(string $key): bool
    {
        $key = Str::lower($key);

        return str_contains($key, 'password')
            || str_contains($key, 'token')
            || str_contains($key, 'secret')
            || str_contains($key, 'auth_key');
    }

    private function toolName(string $tool): string
    {
        return Str::limit(strtolower(trim($tool)), 96, '');
    }
}
