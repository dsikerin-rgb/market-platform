<?php

declare(strict_types=1);

namespace App\Services\Ai;

use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Cache;

/**
 * Shared service для генерации и валидации AI review.
 *
 * Единый источник AI-разбора — используется:
 *  - в командах (ai:review-place, ai:review-merge-tail)
 *  - в UI (MapReviewResults page)
 *
 * Гарантирует:
 *  - единый policy prompt для всех потребителей
 *  - единую safety/semantic validation
 *  - отсутствие дублирования логики
 */
class AiReviewService
{
    /**
     * Applied-действия, запрещённые для спорных статусов.
     */
    private const FORBIDDEN_FOR_DISPUTED = [
        'mark_space_free',
        'mark_space_service',
        'fix_space_identity',
        'bind_shape_to_space',
        'unbind_shape_from_space',
    ];

    /**
     * Семантический маппинг: map_review_status → ожидаемое observed-решение.
     */
    private const STATUS_TO_RECOMMENDATION = [
        'conflict'       => 'occupancy_conflict',
        'changed_tenant' => 'tenant_changed_on_site',
        'not_found'      => 'shape_not_found',
    ];

    /**
     * Максимум AI-запросов за один вызов (UI protection).
     */
    public const MAX_REVIEWS_PER_BATCH = 5;

    /**
     * Получить AI review для одного market_space.
     *
     * Кэширует ТОЛЬКО успешный валидный ответ (10 мин).
     * Ошибки НЕ кэшируются — следующий вызов повторит запрос.
     *
     * @return array{review: array{summary:string, why_flagged:string, recommended_next_step:string, risk_score:int, confidence:float}|null, error_type: 'connectivity'|'policy'|null}
     *
     * error_type:
     *   null            — успех (review содержит результат)
     *   'connectivity'  — сеть/auth/GigaChat недоступен (caller может включить cooldown)
     *   'policy'        — модель вернула ответ, но он не прошёл safety/semantic check
     *                     (caller НЕ включает cooldown — это может быть единичный случай)
     */
    public function getReviewForSpace(int $spaceId, int $marketId): array
    {
        // Кэшируем только успешные ответы
        $cacheKey = "ai_review_ok_{$marketId}_{$spaceId}";
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return ['review' => $cached, 'error_type' => null];
        }

        return $this->doFetchReview($spaceId, $marketId);
    }

    /**
     * Сохранить успешный review в кеш (для预热ки из CLI).
     */
    public function cacheSuccess(int $spaceId, int $marketId, array $review): void
    {
        $cacheKey = "ai_review_ok_{$marketId}_{$spaceId}";
        Cache::put($cacheKey, $review, now()->addMinutes(10));
    }

    /**
     * Проверить, доступен ли GigaChat для этого окружения.
     */
    public function isAvailable(): bool
    {
        return filled(config('gigachat.auth_key'));
    }

    /**
     * Сбросить кеш review для конкретного места.
     */
    public function clearCache(int $spaceId, int $marketId): void
    {
        Cache::forget("ai_review_ok_{$marketId}_{$spaceId}");
    }

    /**
     * Основная логика: собрать context pack → отправить в GigaChat → валидировать.
     *
     * @return array{review: array{summary:string, why_flagged:string, recommended_next_step:string, risk_score:int, confidence:float}|null, error_type: 'connectivity'|'policy'|null}
     */
    private function doFetchReview(int $spaceId, int $marketId): array
    {
        try {
            $packBuilder = app(AiContextPackBuilder::class);
            $pack = $packBuilder->build($spaceId, $marketId);

            if (isset($pack['error'])) {
                return ['review' => null, 'error_type' => 'connectivity'];
            }

            $http = app(Http::class);
            $client = new GigaChatClient(
                http: $http,
                authKey: config('gigachat.auth_key'),
                scope: config('gigachat.scope'),
                model: config('gigachat.model'),
                verifySsl: (bool) config('gigachat.verify_ssl', true),
            );

            $systemPrompt = $this->buildSystemPrompt($pack);
            $userMessage = $this->buildUserMessage($pack);

            $response = $client->chat([
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userMessage],
            ], temperature: 0.0, maxTokens: 1500);

            if (! $response['ok'] || $response['content'] === null) {
                return ['review' => null, 'error_type' => 'connectivity'];
            }

            $parsed = $this->parseResponse($response['content']);
            if ($parsed === null) {
                return ['review' => null, 'error_type' => 'policy'];
            }

            // Safety/semantic validation — единый для всех потребителей
            $safety = $this->validateSafety($parsed, $pack['map_review_status'] ?? null);
            if (! $safety['ok']) {
                logger()->info('AI review safety violation', [
                    'space_id'    => $spaceId,
                    'market_id'   => $marketId,
                    'error'       => $safety['error'],
                    'recommended' => $parsed['recommended_next_step'],
                ]);
                return ['review' => null, 'error_type' => 'policy'];
            }

            // Кэшируем только успешный валидный ответ
            $cacheKey = "ai_review_ok_{$marketId}_{$spaceId}";
            Cache::put($cacheKey, $parsed, now()->addMinutes(10));

            return ['review' => $parsed, 'error_type' => null];
        } catch (\GuzzleHttp\Exception\ConnectException|\GuzzleHttp\Exception\RequestException $e) {
            logger()->error('AI review connectivity error', [
                'space_id'  => $spaceId,
                'market_id' => $marketId,
                'message'   => $e->getMessage(),
            ]);
            return ['review' => null, 'error_type' => 'connectivity'];
        } catch (\Throwable $e) {
            logger()->error('AI review fetch error', [
                'space_id'  => $spaceId,
                'market_id' => $marketId,
                'message'   => $e->getMessage(),
            ]);
            return ['review' => null, 'error_type' => 'connectivity'];
        }
    }

    /**
     * Построить system prompt с учётом политики безопасности.
     */
    private function buildSystemPrompt(array $pack): string
    {
        $status = $pack['map_review_status'];
        $statusLabels = [
            'changed_tenant' => 'сменился арендатор',
            'conflict'       => 'конфликт occupacy',
            'not_found'      => 'место не найдено на карте',
        ];
        $label = $statusLabels[$status] ?? $status;

        $isDisputed = in_array($status, ['conflict', 'changed_tenant', 'not_found'], true);

        $appliedDecisions = array_filter(
            $pack['decision_options']['relevant_decisions'],
            fn ($d) => $d['is_applied']
        );
        $observedDecisions = array_filter(
            $pack['decision_options']['relevant_decisions'],
            fn ($d) => $d['is_observed']
        );

        $observedList = count($observedDecisions) > 0
            ? implode(', ', array_map(fn ($d) => "{$d['decision']}", $observedDecisions))
            : 'нет observed-решений';

        $appliedList = count($appliedDecisions) > 0
            ? implode(', ', array_map(fn ($d) => "{$d['decision']}", $appliedDecisions))
            : 'нет applied-решений';

        $expected = self::STATUS_TO_RECOMMENDATION[$status] ?? null;

        $safetyRules = $isDisputed
            ? "\n\nПРАВИЛА БЕЗОПАСНОСТИ (статус «{$label}» — СПОРНЫЙ):\n"
              . "1. ЗАПРЕЩЕНО рекомендовать applied-действия: {$appliedList}\n"
              . "2. РАЗРЕШЕНО рекомендовать только observed-решения: {$observedList}\n"
              . "3. Для статуса '{$status}' ТРЕБУЕТСЯ рекомендовать: '{$expected}'\n"
              . "4. risk_score должен быть >= 7 для спорных статусов.\n"
              . "5. Если данных недостаточно — добавь «требуется ручной review управляющим рынком»."
            : "\n\nПРАВИЛА БЕЗОПАСНОСТИ:\n"
              . "- Не предлагай applied-действия без достаточных данных.\n"
              . "- risk_score >= 7, если есть риски потери данных.";

        return <<<PROMPT
Ты — ассистент-аналитик для системы управления торговым рынком.
Анализируй данные спорного торгового места и дай краткую структурированную рекомендацию.

Статус: {$status} ({$label}).
Ты НЕ принимаешь решения — только рекомендуешь действие.{$safetyRules}

Ответ — СТРОГО JSON:
{
  "summary": "Краткое описание ситуации, 2-3 предложения",
  "why_flagged": "Почему место попало в 'Нужно уточнить', 1-2 предложения",
  "recommended_next_step": "Конкретное действие, 2-4 предложения",
  "risk_score": 5,
  "confidence": 0.75
}

risk_score: 1-10, где 10 = только ручной review.
confidence: 0.0-1.0.
Без markdown, без текста до/после JSON.
PROMPT;
    }

    /**
     * Построить user message из context pack.
     */
    private function buildUserMessage(array $pack): string
    {
        $space = $pack['space_snapshot'];
        $tenant = $pack['tenant_context'];
        $debt = $pack['debt_context'];
        $history = $pack['review_history'];

        $historyLines = count($history) > 0
            ? collect($history)->map(fn ($h) => "- {$h['decision']} ({$h['status']}): {$h['reason']} — {$h['effective_at']}")
                ->join("\n")
            : '(нет предыдущих ревизий)';

        $hasMapShape = $space['has_map_shape'] ? 'да' : 'нет';
        $hasTenant = $tenant['has_tenant'] ? 'да' : 'нет';
        $tenantName = $tenant['has_tenant'] ? "- tenant: {$tenant['tenant']['display_name']}" : '(нет)';
        $contractsInfo = $tenant['has_tenant'] && !empty($tenant['contracts'])
            ? count($tenant['contracts']) . ' контракт(ов)'
            : ($tenant['has_tenant'] ? 'контрактов к месту: 0' : 'арендатор не привязан');

        $parts = [];
        $parts[] = '[space]';
        $parts[] = "id: {$space['id']}, number: {$space['number']}, display: {$space['display_name']}";
        $parts[] = "status: {$space['status']}, area: {$space['area_sqm']}м², shape: {$hasMapShape}";
        $parts[] = '';
        $parts[] = '[tenant]';
        $parts[] = "has_tenant: {$hasTenant}";
        $parts[] = $tenantName;
        $parts[] = "contracts: {$contractsInfo}";
        $parts[] = '';
        $parts[] = '[debt]';
        $parts[] = "status: {$debt['debt_status']} ({$debt['debt_label']}), scope: {$debt['debt_scope']}";
        $parts[] = "total_debt: {$debt['total_debt']}, overdue: {$debt['overdue_days']}";
        $parts[] = '';
        $parts[] = '[history]';
        $parts[] = $historyLines;

        return implode("\n", $parts);
    }

    /**
     * Распарсить JSON-ответ.
     *
     * @return array{summary:string, why_flagged:string, recommended_next_step:string, risk_score:int, confidence:float}|null
     */
    private function parseResponse(?string $raw): ?array
    {
        if ($raw === null) {
            return null;
        }

        $jsonStr = $this->extractJson($raw);
        if ($jsonStr === null) {
            return null;
        }

        $decoded = json_decode($jsonStr, true);
        if ($decoded === null || json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        $required = ['summary', 'why_flagged', 'recommended_next_step', 'risk_score', 'confidence'];
        foreach ($required as $field) {
            if (! isset($decoded[$field])) {
                return null;
            }
        }

        $riskScore = (int) $decoded['risk_score'];
        $confidence = (float) $decoded['confidence'];

        if ($riskScore < 1 || $riskScore > 10) {
            return null;
        }
        if ($confidence < 0.0 || $confidence > 1.0) {
            return null;
        }

        return [
            'summary'               => (string) $decoded['summary'],
            'why_flagged'           => (string) $decoded['why_flagged'],
            'recommended_next_step' => (string) $decoded['recommended_next_step'],
            'risk_score'            => $riskScore,
            'confidence'            => $confidence,
        ];
    }

    /**
     * Проверка безопасности и семантического соответствия.
     *
     * @return array{ok: bool, error: string|null}
     */
    private function validateSafety(array $parsed, ?string $mapReviewStatus): array
    {
        $isDisputed = in_array($mapReviewStatus, ['conflict', 'changed_tenant', 'not_found'], true);
        if (! $isDisputed) {
            return ['ok' => true, 'error' => null];
        }

        $recommendation = strtolower($parsed['recommended_next_step']);

        foreach (self::FORBIDDEN_FOR_DISPUTED as $forbidden) {
            if (str_contains($recommendation, $forbidden)) {
                return [
                    'ok'    => false,
                    'error' => "Applied action forbidden for status '{$mapReviewStatus}': '{$forbidden}'",
                ];
            }
        }

        $expected = self::STATUS_TO_RECOMMENDATION[$mapReviewStatus] ?? null;
        if ($expected && ! str_contains($recommendation, $expected)) {
            return [
                'ok'    => false,
                'error' => "Semantic mismatch: expected '{$expected}' for status '{$mapReviewStatus}', got '{$parsed['recommended_next_step']}'",
            ];
        }

        if ($parsed['risk_score'] < 7) {
            return [
                'ok'    => false,
                'error' => "Risk score too low for disputed status: {$parsed['risk_score']} < 7",
            ];
        }

        return ['ok' => true, 'error' => null];
    }

    /**
     * Извлечь JSON из сырого ответа.
     */
    private function extractJson(string $raw): ?string
    {
        json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $raw;
        }

        if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $raw, $m)) {
            return $m[1];
        }

        if (preg_match('/\{(?:[^{}]|(?R))*\}/s', $raw, $m)) {
            $candidate = $m[0];
            json_decode($candidate, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $candidate;
            }
        }

        return null;
    }
}
