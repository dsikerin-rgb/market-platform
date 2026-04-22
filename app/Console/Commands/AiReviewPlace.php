<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AiContextPackBuilder;
use App\Services\Ai\AiReviewService;
use App\Services\Ai\GigaChatClient;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\DB;

class AiReviewPlace extends Command
{
    protected $signature = 'ai:review-place
        {market_space_id}
        {--market_id= : Market ID (auto-detected if omitted)}
        {--model= : Override model (default: from config)}
        {--diag : Use diag_model instead of model}
        {--fresh-token : Clear GigaChat token cache before request}
        {--dry-run : Show request without sending to GigaChat}';

    protected $description = 'Send one market_space context pack to GigaChat and return structured review (read-only)';

    public function handle(AiContextPackBuilder $packBuilder, AiReviewService $reviewService, Http $http): int
    {
        $spaceId = (int) $this->argument('market_space_id');
        $marketId = $this->option('market_id')
            ? (int) $this->option('market_id')
            : $this->autoDetectMarketId($spaceId);

        if ($marketId === null) {
            $this->error("Market ID не найден для market_space_id={$spaceId}");
            return Command::FAILURE;
        }

        // 1. Собираем context pack
        $this->info("[1/4] Собираю context pack для market_space_id={$spaceId}...");
        $pack = $packBuilder->build($spaceId, $marketId);

        if (isset($pack['error'])) {
            $this->error("Ошибка сборки context pack: {$pack['reason']}");
            return Command::FAILURE;
        }

        $this->info("     ✓ Context pack собран: {$pack['map_review_status']}");

        // 2. Создаём GigaChat client
        $model = $this->option('diag')
            ? config('gigachat.diag_model')
            : ($this->option('model') ?: config('gigachat.model'));

        $this->info("[2/4] GigaChat client: model={$model}");

        $client = new GigaChatClient(
            http: $http,
            authKey: config('gigachat.auth_key'),
            scope: config('gigachat.scope'),
            model: $model,
            verifySsl: false, // local
        );

        if ($this->option('fresh-token')) {
            $client->clearTokenCache();
            $this->info("     ✓ Token cache cleared");
        }

        // 3. Формируем messages
        $messages = $reviewService->buildMessagesForPack($pack);
        $systemPrompt = $messages['system'];
        $userMessage = $messages['user'];

        // --dry-run: показать и выйти
        if ($this->option('dry-run')) {
            $this->info("[dry-run] Показываю запрос без отправки в GigaChat");
            $this->newLine();
            $this->line("  <fg=cyan>API URL:</>   " . GigaChatClient::chatUrl());
            $this->line("  <fg=cyan>Model:</>    {$model}");
            $this->line("  <fg=cyan>Auth Key:</> " . substr(config('gigachat.auth_key'), 0, 12) . '...');
            $this->line("  <fg=cyan>Scope:</>    " . config('gigachat.scope'));
            $this->newLine();

            $requestPreview = [
                'model'       => $model,
                'temperature' => 0.0,
                'max_tokens'  => 2000,
                'messages'    => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userMessage],
                ],
            ];

            $this->info("────────────────── REQUEST JSON ──────────────────");
            $this->output->writeln(
                json_encode($requestPreview, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
            );
            $this->newLine();
            $this->info("Dry-run complete.");
            return Command::SUCCESS;
        }

        // 3b. Отправляем в GigaChat
        $this->info("[3/4] Отправляю в GigaChat...");

        $response = $client->chat([
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userMessage],
        ], temperature: 0.0, maxTokens: 2000);

        if (! $response['ok']) {
            $this->error("✗ GigaChat error: {$response['error']}");
            $this->newLine();
            $this->warn("  Подсказка:");
            $this->warn("  - Проверьте доступ к ngw.devices.sberbank.ru:9443");
            $this->warn("  - Для offline-тестов используйте --dry-run");
            $this->warn("  - GIGACHAT_AUTH_KEY: " . substr(config('gigachat.auth_key'), 0, 12) . '...');
            return Command::FAILURE;
        }

        $this->info("     ✓ Ответ получен (model: {$response['model_used']})");

        // 4. Парсим и валидируем JSON
        $this->info("[4/4] Валидирую ответ...");

        $parsed = $this->parseAndValidate($response['content'], $pack['map_review_status'] ?? null);

        if (! $parsed['ok']) {
            $this->error("✗ Невалидный ответ: {$parsed['error']}");
            $this->line("Raw content:");
            $this->line($response['content']);
            return Command::FAILURE;
        }

        $this->info("     ✓ Ответ валиден");
        $this->newLine();

        // 5. Вывод
        $review = $parsed['data'];

        $this->info("╔══════════════════════════════════════════════════════════╗");
        $this->info("║         AI Review — Market Space #{$spaceId}            ║");
        $this->info("╚══════════════════════════════════════════════════════════╝");
        $this->newLine();

        $this->line("  <fg=cyan>summary:</>             {$review['summary']}");
        $this->line("  <fg=cyan>why_flagged:</>         {$review['why_flagged']}");
        $this->line("  <fg=cyan>recommended_next_step:</> {$review['recommended_next_step']}");
        $this->line("  <fg=cyan>risk_score:</>          {$review['risk_score']} / 10");
        $this->line("  <fg=cyan>confidence:</>          {$review['confidence']}");

        $this->newLine();
        $this->info("────────────────── JSON ──────────────────");
        $this->newLine();

        $output = [
            'market_space_id'  => $spaceId,
            'map_review_status'=> $pack['map_review_status'],
            'model_used'       => $response['model_used'],
            'review'           => $review,
        ];

        $this->output->writeln(
            json_encode($output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
        );

        $this->newLine();
        $this->info("AI review complete.");

        return Command::SUCCESS;
    }

    private function buildSystemPrompt(array $pack): string
    {
        $status = $pack['map_review_status'];
        $statusLabels = [
            'changed_tenant' => 'на месте другой арендатор',
            'conflict'       => 'конфликт по занятости',
            'not_found'      => 'место не найдено на карте',
        ];

        $label = $statusLabels[$status] ?? $status;

        // Определяем тип статуса
        $isDisputed = in_array($status, ['conflict', 'changed_tenant', 'not_found'], true);

        // Разделяем решения на applied и observed
        $appliedDecisions = array_filter(
            $pack['decision_options']['relevant_decisions'],
            fn ($d) => $d['is_applied']
        );
        $observedDecisions = array_filter(
            $pack['decision_options']['relevant_decisions'],
            fn ($d) => $d['is_observed']
        );

        $decisionsList = implode(', ', array_map(
            fn ($d) => "{$d['decision']} ({$d['label']})",
            $pack['decision_options']['relevant_decisions']
        ));

        $observedList = count($observedDecisions) > 0
            ? implode(', ', array_map(fn ($d) => "{$d['decision']} ({$d['label']})", $observedDecisions))
            : '(нет observed-решений в списке)';

        $appliedList = count($appliedDecisions) > 0
            ? implode(', ', array_map(fn ($d) => "{$d['decision']} ({$d['label']})", $appliedDecisions))
            : '(нет applied-решений в списке)';

        // Правила безопасности для спорных статусов
        $expectedRecommendation = self::STATUS_TO_RECOMMENDATION[$status] ?? null;
        $expectedLabel = self::RECOMMENDATION_LABELS[$expectedRecommendation] ?? null;

        $safetyRules = $isDisputed
            ? <<<RULES

⛔ ПРАВИЛА БЕЗОПАСНОСТИ (статус «{$label}» — СПОРНЫЙ):
1. ЗАПРЕЩЕНО рекомендовать applied-действия: {$appliedList}
   Эти решения изменяют данные (статус места, привязку фигур, идентификацию).
2. РАЗРЕШЕНО рекомендовать только observed-решения: {$observedList}
   Эти решения только фиксируют наблюдение, НЕ мутируют данные.
3. Для статуса '{$status}' ({$label}) ТРЕБУЕТСЯ рекомендовать:
   '{$expectedRecommendation}' — {$expectedLabel}
4. Если данных недостаточно — добавь к рекомендации «требуется ручной review управляющим рынком».
5. НИКОГДА не предлагай для спорных статусов:
   - mark_space_free (отметить свободным) — без подтверждения фактического статуса
   - mark_space_service (отметить служебным) — без приказа администрации
   - fix_space_identity (изменить номер/название) — без верификации на месте
   - bind/unbind shape — без проверки геометрии карты
   - occupancy_conflict для статуса changed_tenant — это семантически неверно
   - tenant_changed_on_site для статуса conflict — это семантически неверно
6. risk_score для спорных случаев должен быть >= 7.
7. confidence должен отражать неполноту данных.
RULES
            : <<<RULES

ПРАВИЛА БЕЗОПАСНОСТИ:
- Не предлагай applied-действия без достаточных данных.
- Если уверенности мало — рекомендуй ручной review.
- risk_score >= 7, если есть риски потери данных.
RULES;

        return <<<PROMPT
Ты — ассистент-аналитик для системы управления торговым рынком.
Твоя задача: проанализировать данные одного спорного торгового места и дать структурированную рекомендацию.

Контекст:
- Место попало в секцию "Нужно уточнить" (map_review_status = {$status}: {$label}).
- Ревизор обнаружил несоответствие между данными системы и фактическим состоянием.
- Ты НЕ принимаешь решения, а только рекомендуешь действие.

Доступные решения для этого статуса:
{$decisionsList}

Applied-решения (изменяют данные): {$appliedList}
Observed-решения (только фиксация): {$observedList}
{$safetyRules}

Формат ответа — СТРОГО JSON со следующей схемой (без markdown, без дополнительного текста):
{
  "summary": "Краткое описание ситуации, 2-3 предложения на русском",
  "why_flagged": "Почему это место попало в 'Нужно уточнить', 1-2 предложения",
  "recommended_next_step": "Конкретное действие: какое решение выбрать и почему, 2-4 предложения",
  "risk_score": 5,
  "confidence": 0.75
}

Правила:
- summary: опиши факты, которые видишь в данных
- why_flagged: объясни причину попадания в "Нужно уточнить" на основе map_review_status и review_history
- recommended_next_step: выбери одно из РЕЛЕВАНТНЫХ решений и обоснуй
  Для спорных статусов выбирай ТОЛЬКО observed-решения или ручной review.
- risk_score: целое 1-10, где 1 = безопасно авто-применить, 10 = только ручной review
- confidence: float 0.0-1.0 — твоя уверенность в рекомендации

НЕ добавляй markdown-обёртки, НЕ добавляй текст до или после JSON.
PROMPT;
    }

    private function buildUserMessage(array $pack): string
    {
        $space = $pack['space_snapshot'];
        $tenant = $pack['tenant_context'];
        $debt = $pack['debt_context'];
        $history = $pack['review_history'];
        $contractContour = $tenant['contract_contour'] ?? [];

        $historyLines = count($history) > 0
            ? collect($history)->map(fn ($h) =>
                "- {$h['decision']} ({$h['status']}): {$h['reason']} — {$h['effective_at']}"
            )->join("\n")
            : '(нет предыдущих ревизий)';

        $hasMapShape = $space['has_map_shape'] ? 'да' : 'нет';
        $hasTenant = $tenant['has_tenant'] ? 'да' : 'нет';
        $tenantName = $tenant['has_tenant'] ? "- tenant: {$tenant['tenant']['display_name']}" : '(нет)';
        $contractsInfo = $tenant['has_tenant'] && !empty($tenant['contracts'])
            ? count($tenant['contracts']) . ' контракт(ов)'
            : ($tenant['has_tenant'] ? 'контрактов, привязанных к месту: 0' : 'арендатор не привязан');

        $parts = [];
        $parts[] = 'Данные торгового места:';
        $parts[] = '';
        $parts[] = '[space_snapshot]';
        $parts[] = "- id: {$space['id']}";
        $parts[] = "- number: {$space['number']}";
        $parts[] = "- display_name: {$space['display_name']}";
        $parts[] = "- status: {$space['status']}";
        $parts[] = "- area_sqm: {$space['area_sqm']}";
        $parts[] = "- activity_type: {$space['activity_type']}";
        $parts[] = "- has_map_shape: {$hasMapShape}";
        $parts[] = '';
        $parts[] = '[tenant_context]';
        $parts[] = "- has_tenant: {$hasTenant}";
        $parts[] = $tenantName;
        $parts[] = "- contracts: {$contractsInfo}";
        $parts[] = "- active_current_contracts: " . (int) ($contractContour['active_current_total'] ?? 0);
        $parts[] = "- historical_contracts: " . (int) ($contractContour['historical_total'] ?? 0);
        $parts[] = "- has_historical_tail: " . (! empty($contractContour['has_historical_tail']) ? 'yes' : 'no');
        $parts[] = '';
        $parts[] = '[debt_context]';
        $parts[] = "- debt_status: {$debt['debt_status']} ({$debt['debt_label']})";
        $parts[] = "- debt_scope: {$debt['debt_scope']}";
        $parts[] = "- total_debt: {$debt['total_debt']}";
        $parts[] = "- overdue_days: {$debt['overdue_days']}";
        $parts[] = "- source_marker: {$debt['source_marker']}";
        $parts[] = '';
        $parts[] = '[review_history]';
        $parts[] = $historyLines;

        return implode("\n", $parts);
    }

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
     * Семантический маппинг: какой map_review_status → какое observed-решение ожидается.
     * Это гарантирует, что рекомендация соответствует природе проблемы.
     */
    private const STATUS_TO_RECOMMENDATION = [
        'conflict'       => 'occupancy_conflict',
        'changed_tenant' => 'tenant_changed_on_site',
        'not_found'      => 'shape_not_found',
    ];

    /**
     * Обратный маппинг для человекочитаемых меток.
     */
    private const RECOMMENDATION_LABELS = [
        'occupancy_conflict'     => 'Конфликт по месту (зафиксировать наблюдение)',
        'tenant_changed_on_site' => 'На месте другой арендатор (зафиксировать наблюдение)',
        'shape_not_found'        => 'Место не найдено на карте (зафиксировать наблюдение)',
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
     * Парсит JSON из ответа GigaChat и валидирует схему.
     *
     * @return array{ok: bool, data: array|null, error: string|null}
     */
    private function parseAndValidate(?string $raw, ?string $mapReviewStatus = null): array
    {
        if ($raw === null) {
            return ['ok' => false, 'data' => null, 'error' => 'Пустой ответ от GigaChat'];
        }

        // Пробуем извлечь JSON из markdown-обёртки
        $jsonStr = $this->extractJson($raw);

        if ($jsonStr === null) {
            return ['ok' => false, 'data' => null, 'error' => 'Не найден JSON в ответе'];
        }

        $decoded = json_decode($jsonStr, true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            return [
                'ok'    => false,
                'data'  => null,
                'error' => 'JSON parse error: ' . json_last_error_msg(),
            ];
        }

        // Валидация схемы
        $required = ['summary', 'why_flagged', 'recommended_next_step', 'risk_score', 'confidence'];
        foreach ($required as $field) {
            if (! isset($decoded[$field])) {
                return [
                    'ok'    => false,
                    'data'  => null,
                    'error' => "Отсутствует обязательное поле: {$field}",
                ];
            }
        }

        // Типы
        if (! is_string($decoded['summary'])) {
            return ['ok' => false, 'data' => null, 'error' => 'summary должен быть строкой'];
        }
        if (! is_string($decoded['why_flagged'])) {
            return ['ok' => false, 'data' => null, 'error' => 'why_flagged должен быть строкой'];
        }
        if (! is_string($decoded['recommended_next_step'])) {
            return ['ok' => false, 'data' => null, 'error' => 'recommended_next_step должен быть строкой'];
        }
        if (! is_int($decoded['risk_score']) || $decoded['risk_score'] < 1 || $decoded['risk_score'] > 10) {
            return ['ok' => false, 'data' => null, 'error' => 'risk_score должен быть int 1-10'];
        }
        if (! is_float($decoded['confidence']) && ! is_int($decoded['confidence'])) {
            return ['ok' => false, 'data' => null, 'error' => 'confidence должен быть float 0.0-1.0'];
        }
        $confidence = (float) $decoded['confidence'];
        if ($confidence < 0.0 || $confidence > 1.0) {
            return ['ok' => false, 'data' => null, 'error' => 'confidence должен быть 0.0-1.0'];
        }

        // ── AI Safety: блокировка unsafe recommendations ──
        $safetyCheck = $this->validateSafety($decoded, $mapReviewStatus);
        if (! $safetyCheck['ok']) {
            return $safetyCheck;
        }

        // Нормализация типов
        $decoded['risk_score'] = (int) $decoded['risk_score'];
        $decoded['confidence'] = (float) $decoded['confidence'];

        return ['ok' => true, 'data' => $decoded, 'error' => null];
    }

    /**
     * Проверка безопасности: запрещает applied-действия для спорных статусов
     * и обеспечивает семантическое соответствие recommendation → map_review_status.
     *
     * @return array{ok: bool, data: null, error: string|null}
     */
    private function validateSafety(array $decoded, ?string $mapReviewStatus): array
    {
        $isDisputed = in_array($mapReviewStatus, ['conflict', 'changed_tenant', 'not_found'], true);
        if (! $isDisputed) {
            return ['ok' => true, 'data' => null, 'error' => null];
        }

        $recommendation = strtolower($decoded['recommended_next_step']);

        // 1. Запрет applied-действий
        foreach (self::FORBIDDEN_FOR_DISPUTED as $forbidden) {
            if (str_contains($recommendation, $forbidden)) {
                return [
                    'ok'    => false,
                    'data'  => null,
                    'error' => "AI safety violation: для статуса '{$mapReviewStatus}' запрещено рекомендовать applied-действие '{$forbidden}'. Используй только observed-решения или ручной review.",
                ];
            }
        }

        // 2. Семантическое соответствие: каждый статус должен рекомендовать своё решение
        // Принимается либо технический код, либо русская пользовательская формулировка
        $expected = self::STATUS_TO_RECOMMENDATION[$mapReviewStatus] ?? null;
        $expectedHumanLabel = self::EXPECTED_RECOMMENDATION_LABELS[$expected] ?? null;
        $expectedVerboseLabel = self::RECOMMENDATION_LABELS[$expected] ?? null;

        $matchesExpected = $expected !== null && (
            str_contains($recommendation, $expected)
            || ($expectedHumanLabel !== null && str_contains($recommendation, $expectedHumanLabel))
            || ($expectedVerboseLabel !== null && str_contains($recommendation, strtolower($expectedVerboseLabel)))
        );

        if ($expected && ! $matchesExpected) {
            // Определяем, что модель вернула вместо ожидаемого
            $got = 'unknown';
            foreach (self::STATUS_TO_RECOMMENDATION as $status => $rec) {
                if (str_contains($recommendation, $rec)) {
                    $got = "{$rec} (для статуса '{$status}', а не '{$mapReviewStatus}')";
                    break;
                }
            }
            if ($got === 'unknown') {
                $got = "нерелевантное решение: '{$decoded['recommended_next_step']}'";
            }

            return [
                'ok'    => false,
                'data'  => null,
                'error' => "Semantic mismatch: для статуса '{$mapReviewStatus}' ожидается '{$expected}' или '{$expectedHumanLabel}', но модель вернула {$got}.",
            ];
        }

        // 3. risk_score >= 7 для спорных статусов
        if ($decoded['risk_score'] < 7) {
            return [
                'ok'    => false,
                'data'  => null,
                'error' => "AI safety violation: для спорного статуса '{$mapReviewStatus}' risk_score должен быть >= 7, получено {$decoded['risk_score']}.",
            ];
        }

        return ['ok' => true, 'data' => null, 'error' => null];
    }

    private function extractJson(string $raw): ?string
    {
        // Пробуем прямой парсинг
        json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $raw;
        }

        // Ищем markdown JSON block
        if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $raw, $m)) {
            return $m[1];
        }

        // Ищем любой JSON object
        if (preg_match('/\{(?:[^{}]|(?R))*\}/s', $raw, $m)) {
            $candidate = $m[0];
            json_decode($candidate, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $candidate;
            }
        }

        return null;
    }

    private function autoDetectMarketId(int $spaceId): ?int
    {
        return DB::table('market_spaces')
            ->where('id', $spaceId)
            ->value('market_id');
    }
}
