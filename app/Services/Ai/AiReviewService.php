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
     * Действия, которые нельзя советовать, если по месту есть только tenant_fallback.
     */
    private const FORBIDDEN_FOR_TENANT_FALLBACK = [
        'matched',
        'отметить, что факт на месте совпадает с системой',
        'mark_space_free',
        'отметить место как свободное',
        'mark_space_service',
        'отметить место как служебное',
        'tenant_changed_on_site',
        'отметить, что на месте другой арендатор',
        'fix_space_identity',
        'уточнить номер и название места',
        'применить уточнение',
        'bind_shape_to_space',
        'привязать фигуру на карте к месту',
        'unbind_shape_from_space',
        'отвязать фигуру от места',
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
     * Пользовательские русские формулировки тех же решений.
     * validateSafety() принимает и технический код, и русский лейбл.
     */
    private const EXPECTED_RECOMMENDATION_LABELS = [
        'occupancy_conflict'     => 'конфликт по занятости',
        'tenant_changed_on_site' => 'другой арендатор',
        'shape_not_found'        => 'не найдено на карте',
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
            $safety = $this->validateSafety(
                $parsed,
                $pack['map_review_status'] ?? null,
                $pack['debt_context']['debt_scope'] ?? null
            );
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
        $debtScope = $pack['debt_context']['debt_scope'] ?? 'none';
        $otherSpacesTotal = (int) ($pack['tenant_context']['other_spaces_total'] ?? 0);
        $relationContext = $pack['relation_context'] ?? [];
        $likelyCanonicalCandidateId = (int) ($relationContext['likely_canonical_candidate_id'] ?? 0);
        $currentRelationScore = (int) data_get($relationContext, 'current_space.canonical_score', 0);
        $bestCandidateScore = collect($relationContext['same_tenant_candidates'] ?? [])
            ->max(fn (array $candidate): int => (int) ($candidate['canonical_score'] ?? 0)) ?? 0;
        $statusLabels = [
            'changed_tenant' => 'на месте другой арендатор',
            'conflict'       => 'конфликт по занятости',
            'not_found'      => 'место не найдено на карте',
            'unconfirmed_link' => 'точная связь с местом не подтверждена',
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

        // Русские лейблы для решений
        $observedList = count($observedDecisions) > 0
            ? implode(', ', array_map(fn ($d) => $d['label'], $observedDecisions))
            : 'нет observed-решений';

        $appliedList = count($appliedDecisions) > 0
            ? implode(', ', array_map(fn ($d) => $d['label'], $appliedDecisions))
            : 'нет applied-решений';

        $expected = self::STATUS_TO_RECOMMENDATION[$status] ?? null;
        $expectedLabels = [
            'occupancy_conflict'     => 'конфликт по занятости',
            'tenant_changed_on_site' => 'на месте другой арендатор',
            'shape_not_found'        => 'место не найдено на карте',
        ];
        $expectedLabel = $expectedLabels[$expected] ?? $expected;

        $safetyRules = $debtScope === 'tenant_fallback'
            ? "\n\nПРАВИЛА БЕЗОПАСНОСТИ (точная связь с местом не подтверждена):\n"
              . "1. На карточке виден статус арендатора, а не подтверждённый статус этого места.\n"
              . "2. ЗАПРЕЩЕНО советовать подтверждать текущее место, менять его статус, уточнять номер/название или перепривязывать фигуру до выбора канонического места.\n"
              . "3. У арендатора " . ($otherSpacesTotal > 0 ? "есть ещё {$otherSpacesTotal} место(места) в этом рынке — сравни их с текущим местом." : "не найдено других мест в этом рынке — опирайся только на доступные факты.") . "\n"
              . "4. recommended_next_step должен вести к безопасному анализу: сравнить места арендатора, найти каноническое место, затем передать кейс на ручную проверку или перенос привязок.\n"
              . "5. Если relation_context показывает кандидата с договорами, начислениями, долгом 1С или tenant_bindings, укажи, что он вероятный кандидат на каноническое место.\n"
              . ($likelyCanonicalCandidateId > 0
                  ? "6. В данных есть вероятный канонический кандидат: market_space_id={$likelyCanonicalCandidateId}. Не называй его окончательным без ручной проверки.\n"
                  : "6. Сильного кандидата нет: score текущего места={$currentRelationScore}, лучший кандидат={$bestCandidateScore}. НЕ называй ситуацию дублем только из-за похожего номера. Напиши, что текущее место не слабее кандидатов по подтверждённым связям, если это видно из relation_context.\n")
              . "7. Не пересказывай текущий конфликт как решение. Объясни, почему подтверждение текущего места опасно.\n"
              . "8. risk_score должен быть >= 7."
            : ($isDisputed
                ? "\n\nПРАВИЛА БЕЗОПАСНОСТИ (статус «{$label}» — СПОРНЫЙ):\n"
                  . "1. ЗАПРЕЩЕНО рекомендовать действия, изменяющие данные: {$appliedList}\n"
                  . "2. РАЗРЕШЕНО рекомендовать только наблюдательные решения: {$observedList}\n"
                  . "3. Для этого статуса ТРЕБУЕТСЯ рекомендовать: '{$expectedLabel}'\n"
                  . "4. risk_score должен быть >= 7 для спорных статусов.\n"
                  . "5. Если данных недостаточно — добавь «требуется ручной review управляющим рынком»."
                : "\n\nПРАВИЛА БЕЗОПАСНОСТИ:\n"
                  . "- Не предлагай действия, изменяющие данные, без достаточных данных.\n"
                  . "- risk_score >= 7, если есть риски потери данных.");

        $recommendedExample = $debtScope === 'tenant_fallback'
            ? '«Не подтверждать текущее место. Сравнить другие места арендатора в этом рынке, выбрать каноническое место и только после этого передать кейс на ручную проверку.»'
            : '«Отметить конфликт по занятости и передать на ручную проверку управляющему рынком.»';

        return <<<PROMPT
Ты — ассистент-аналитик для системы управления торговым рынком.
Анализируй данные спорного торгового места и дай краткую структурированную рекомендацию.

Статус: {$label}.
Ты НЕ принимаешь решения — только рекомендуешь действие.{$safetyRules}

Ответ — СТРОГО JSON:
{
  "summary": "Краткое описание ситуации, 2-3 предложения",
  "why_flagged": "Почему место попало в 'Нужно уточнить', 1-2 предложения",
  "recommended_next_step": "Конкретное действие, 2-4 предложения",
  "risk_score": 5,
  "confidence": 0.75
}

ВАЖНО:
- Используй только русский язык.
- НЕ используй технические кодов (occupancy_conflict, changed_tenant и т.д.).
- Вместо кодов решений пиши понятные фразы:
  * occupancy_conflict → «отметить конфликт по занятости»
  * tenant_changed_on_site → «зафиксировать, что на месте другой арендатор»
  * shape_not_found → «отметить, что место не найдено на карте»
  * mark_space_free → «отметить место как свободное»
  * mark_space_service → «отметить место как служебное»
  * fix_space_identity → «уточнить номер и название места»
  * bind_shape_to_space → «привязать фигуру на карте к месту»
  * unbind_shape_from_space → «отвязать фигуру от места»
- Формулируй recommended_next_step как человеческую инструкцию, например:
  {$recommendedExample}

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
        $accrual = $pack['accrual_context'] ?? [];
        $debt = $pack['debt_context'];
        $history = $pack['review_history'];
        $relations = $pack['relation_context'] ?? [];
        $otherSpaces = $tenant['other_spaces'] ?? [];

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
        $otherSpacesLines = count($otherSpaces) > 0
            ? collect($otherSpaces)->map(fn ($otherSpace) => sprintf(
                '- id: %d, number: %s, display: %s, status: %s, active: %s, shape: %s, exact_contract_link: %s, contracts: %d, accruals: %d, latest_accrual_period: %s',
                (int) $otherSpace['id'],
                (string) ($otherSpace['number'] ?? '—'),
                (string) ($otherSpace['display_name'] ?? '—'),
                (string) ($otherSpace['status'] ?? '—'),
                !empty($otherSpace['is_active']) ? 'да' : 'нет',
                !empty($otherSpace['has_map_shape']) ? 'да' : 'нет',
                !empty($otherSpace['has_exact_contract_link']) ? 'да' : 'нет',
                (int) ($otherSpace['contracts_count'] ?? 0),
                (int) ($otherSpace['accruals_count'] ?? 0),
                (string) ($otherSpace['latest_accrual_period'] ?? '—'),
            ))->join("\n")
            : '(нет других мест арендатора в этом рынке)';

        $currentRelations = $relations['current_space']['relation_counts'] ?? [];
        $relationCandidates = $relations['same_tenant_candidates'] ?? [];
        $currentRelationLine = $this->formatRelationLine($relations['current_space'] ?? [
            'id' => $space['id'],
            'number' => $space['number'],
            'display_name' => $space['display_name'],
            'status' => $space['status'],
            'is_active' => $space['is_active'] ?? true,
            'relation_counts' => $currentRelations,
            'canonical_score' => 0,
        ]);
        $candidateRelationLines = count($relationCandidates) > 0
            ? collect($relationCandidates)->map(fn ($candidate): string => $this->formatRelationLine($candidate))->join("\n")
            : '(нет кандидатов того же арендатора)';

        $parts = [];
        $parts[] = '[space]';
        $parts[] = "id: {$space['id']}, number: {$space['number']}, display: {$space['display_name']}";
        $parts[] = "status: {$space['status']}, area: {$space['area_sqm']}м², shape: {$hasMapShape}";
        $parts[] = '';
        $parts[] = '[tenant]';
        $parts[] = "has_tenant: {$hasTenant}";
        $parts[] = $tenantName;
        $parts[] = "contracts: {$contractsInfo}";
        $parts[] = 'other_spaces_total: ' . (int) ($tenant['other_spaces_total'] ?? 0);
        $parts[] = '';
        $parts[] = '[tenant_other_spaces]';
        $parts[] = $otherSpacesLines;
        $parts[] = '';
        $parts[] = '[relation_context]';
        $parts[] = 'current: ' . $currentRelationLine;
        $parts[] = 'likely_canonical_candidate_id: ' . (($relations['likely_canonical_candidate_id'] ?? null) ?: '—');
        $parts[] = 'duplicate_review_hint: ' . (string) ($relations['duplicate_review_hint'] ?? '—');
        $parts[] = 'candidates:';
        $parts[] = $candidateRelationLines;
        $parts[] = '';
        $parts[] = '[debt]';
        $parts[] = "status: {$debt['debt_status']} ({$debt['debt_label']}), scope: {$debt['debt_scope']}";
        $parts[] = "total_debt: {$debt['total_debt']}, overdue: {$debt['overdue_days']}";
        $parts[] = '';
        $parts[] = '[accruals]';
        $parts[] = 'count: ' . (int) ($accrual['count'] ?? 0);
        $parts[] = 'latest_period: ' . (string) ($accrual['latest_period'] ?? '—');
        $parts[] = 'latest_total_with_vat: ' . ($accrual['latest_total_with_vat'] ?? '—');
        $parts[] = 'latest_source: ' . (string) ($accrual['latest_source'] ?? '—');
        $parts[] = '';
        $parts[] = '[history]';
        $parts[] = $historyLines;

        return implode("\n", $parts);
    }

    private function formatRelationLine(array $space): string
    {
        $counts = is_array($space['relation_counts'] ?? null) ? $space['relation_counts'] : [];
        $debtTotal = $counts['debt_total'] ?? null;

        return sprintf(
            'id: %d, number: %s, display: %s, status: %s, active: %s, score: %d, map_shapes: %d, contracts: %d, accruals: %d, debt_total: %s, cabinet_links: %d, tenant_bindings: %d, products: %d',
            (int) ($space['id'] ?? 0),
            (string) ($space['number'] ?? '—'),
            (string) ($space['display_name'] ?? '—'),
            (string) ($space['status'] ?? '—'),
            ! empty($space['is_active']) ? 'да' : 'нет',
            (int) ($space['canonical_score'] ?? 0),
            (int) ($counts['map_shapes'] ?? 0),
            (int) ($counts['contracts'] ?? 0),
            (int) ($counts['accruals'] ?? 0),
            $debtTotal === null ? '—' : (string) $debtTotal,
            (int) ($counts['cabinet_links'] ?? 0),
            (int) ($counts['tenant_bindings'] ?? 0),
            (int) ($counts['products'] ?? 0),
        );
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
    private function validateSafety(array $parsed, ?string $mapReviewStatus, ?string $debtScope): array
    {
        $recommendation = mb_strtolower($parsed['recommended_next_step'], 'UTF-8');

        if ($debtScope === 'tenant_fallback') {
            foreach (self::FORBIDDEN_FOR_TENANT_FALLBACK as $forbidden) {
                if (str_contains($recommendation, $forbidden)) {
                    return [
                        'ok'    => false,
                        'error' => "Unsafe action forbidden for tenant_fallback: '{$forbidden}'",
                    ];
                }
            }

            if ($parsed['risk_score'] < 7) {
                return [
                    'ok'    => false,
                    'error' => "Risk score too low for tenant_fallback: {$parsed['risk_score']} < 7",
                ];
            }

            return ['ok' => true, 'error' => null];
        }

        $isDisputed = in_array($mapReviewStatus, ['conflict', 'changed_tenant', 'not_found'], true);
        if (! $isDisputed) {
            return ['ok' => true, 'error' => null];
        }

        foreach (self::FORBIDDEN_FOR_DISPUTED as $forbidden) {
            if (str_contains($recommendation, $forbidden)) {
                return [
                    'ok'    => false,
                    'error' => "Applied action forbidden for status '{$mapReviewStatus}': '{$forbidden}'",
                ];
            }
        }

        $expected = self::STATUS_TO_RECOMMENDATION[$mapReviewStatus] ?? null;
        $expectedLabel = self::EXPECTED_RECOMMENDATION_LABELS[$expected] ?? null;

        // Принимается либо технический код, либо русская пользовательская формулировка
        $matchesExpected = $expected !== null && (
            str_contains($recommendation, $expected)
            || ($expectedLabel !== null && str_contains($recommendation, $expectedLabel))
        );

        if ($expected && ! $matchesExpected) {
            return [
                'ok'    => false,
                'error' => "Semantic mismatch: expected '{$expected}' or '{$expectedLabel}' for status '{$mapReviewStatus}', got '{$parsed['recommended_next_step']}'",
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
