<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\GigaChatClient;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Cache;

/**
 * Acceptance-style test: tenant_merge_tail detection.
 *
 * Загружает JSON fixture → отправляет в GigaChat → валидирует ответ
 * по схеме + good signals + forbidden recommendations.
 *
 * БЕЗ мутаций данных. Только аналитический тест.
 */
class AiReviewMergeTail extends Command
{
    protected $signature = 'ai:review-merge-tail
        {fixture? : Путь к JSON fixture (относительно tests/Fixtures/ai/)}
        {--model= : Override model}
        {--diag : Use diag_model}
        {--fresh-token : Clear GigaChat token cache}
        {--dry-run : Show request without sending}';

    protected $description = 'Acceptance test: AI detects tenant merge tail from fixture';

    /**
     * Семантически валидные решения для merge tail.
     * Это NOT applied-действия — это диагностические рекомендации.
     */
    private const VALID_ACTIONS = [
        'reassign_spaces_to_canonical',
        'reassign_contracts_to_canonical',
        'run_tenants:merge_dry_run',
        'manual_review_by_admin',
        'investigate_orphan_records',
        'verify_merge_transaction_completed',
    ];

    /**
     * Абсолютно запрещённые рекомендации.
     */
    private const FORBIDDEN = [
        'delete old tenant',
        'delete the old tenant',
        'удалить старый',
        'удалить tenant',
        'deactivate canonical',
        'deactivate the canonical',
        'отключить canonical',
        'create new tenant',
        'создать нового',
        'create a new tenant',
        'ignore',
        'игнорировать',
        'everything is fine',
        'всё в порядке',
        'все в порядке',
        'no action needed',
        'нет необходимости',
        'reassign spaces to random',
        'reassign to any',
    ];

    /**
     * Good signals — модель должна распознать паттерн.
     */
    private const GOOD_SIGNALS = [
        'merged'          => 'Распознал паттерн merge (ключи: merged, merged_into, slil)',
        'canonical'       => 'Определил canonical tenant (id=106)',
        'reassign'        => 'Рекомендует перепривязку к canonical',
        'dry_run'         => 'Предлагает dry-run перед применением',
        'manual_review'   => 'Рекомендует ручной review / проверку',
        'risk_aware'      => 'risk_score >= 7 (понимает риск)',
        'no_mutation'     => 'Не предлагает прямую мутацию без проверки',
    ];

    public function handle(Http $http): int
    {
        $fixtureName = $this->argument('fixture') ?: 'merge_tail_tenant_9.json';
        $fixturePath = base_path("tests/Fixtures/ai/{$fixtureName}");

        // 1. Загружаем fixture
        $this->info("[1/5] Загружаю fixture: {$fixtureName}");
        if (! file_exists($fixturePath)) {
            $this->error("Fixture не найден: {$fixturePath}");
            return Command::FAILURE;
        }

        $fixture = json_decode(file_get_contents($fixturePath), true);
        if ($fixture === null) {
            $this->error("JSON parse error: " . json_last_error_msg());
            return Command::FAILURE;
        }
        $this->info("     ✓ Fixture загружен: {$fixture['case_type']}");

        // 2. Формируем сообщения
        $this->info("[2/5] Формирую системный prompt для merge tail detection...");
        $systemPrompt = $this->buildSystemPrompt($fixture);
        $userMessage = $this->buildUserMessage($fixture);

        if ($this->option('dry-run')) {
            $this->info("[dry-run] Показываю запрос без отправки");
            $this->newLine();

            $model = $this->option('diag')
                ? config('gigachat.diag_model')
                : ($this->option('model') ?: config('gigachat.model'));

            $this->line("  <fg=cyan>API URL:</>   " . GigaChatClient::chatUrl());
            $this->line("  <fg=cyan>Model:</>    {$model}");
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

        // 3. Отправляем в GigaChat
        $this->info("[3/5] Отправляю в GigaChat...");

        $model = $this->option('diag')
            ? config('gigachat.diag_model')
            : ($this->option('model') ?: config('gigachat.model'));

        $client = new GigaChatClient(
            http: $http,
            authKey: config('gigachat.auth_key'),
            scope: config('gigachat.scope'),
            model: $model,
            verifySsl: false,
        );

        if ($this->option('fresh-token')) {
            $client->clearTokenCache();
        }

        $response = $client->chat([
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userMessage],
        ], temperature: 0.0, maxTokens: 2000);

        if (! $response['ok']) {
            $this->error("✗ GigaChat error: {$response['error']}");
            return Command::FAILURE;
        }

        $this->info("     ✓ Ответ получен (model: {$response['model_used']})");

        // 4. Парсим и валидируем
        $this->info("[4/5] Валидирую ответ...");

        $parsed = $this->parseAndValidate($response['content']);
        if (! $parsed['ok']) {
            $this->error("✗ Валидация провалена: {$parsed['error']}");
            if (isset($parsed['raw'])) {
                $this->line("Raw content:");
                $this->line($parsed['raw']);
            }
            return Command::FAILURE;
        }

        $this->info("     ✓ Schema валидна");

        // 5. Проверяем good signals и forbidden
        $this->info("[5/5] Проверяю good signals / forbidden recommendations...");

        $review = $parsed['data'];
        $signals = $this->checkSignals($review);

        $this->newLine();
        $this->info("╔══════════════════════════════════════════════════════════╗");
        $this->info("║    AI Merge Tail Detection — Acceptance Test             ║");
        $this->info("╚══════════════════════════════════════════════════════════╝");
        $this->newLine();

        // Good signals
        $this->info("─── Good Signals ───");
        foreach ($signals['good'] as $key => $found) {
            $icon = $found ? '✅' : '⬜';
            $this->line("  {$icon} {$key}: " . self::GOOD_SIGNALS[$key]);
        }

        // Forbidden checks
        $this->newLine();
        $this->info("─── Forbidden Checks ───");
        if (empty($signals['forbidden_found'])) {
            $this->info("  ✅ Нет запрещённых рекомендаций");
        } else {
            foreach ($signals['forbidden_found'] as $f) {
                $this->error("  ✗ Обнаружено: {$f}");
            }
        }

        // Summary
        $this->newLine();
        $passed = $signals['passed'] && empty($signals['forbidden_found']);
        if ($passed) {
            $this->info("─── RESULT: PASS ✅ ───");
        } else {
            $this->error("─── RESULT: FAIL ❌ ───");
        }

        $this->newLine();
        $this->line("  <fg=cyan>diagnosis:</>           {$review['diagnosis']}");
        $this->line("  <fg=cyan>old_tenant_id:</>       {$review['old_tenant_id']}");
        $this->line("  <fg=cyan>canonical_tenant_id:</> {$review['canonical_tenant_id']}");
        $this->line("  <fg=cyan>recommended_action:</>  {$review['recommended_action']}");
        $this->line("  <fg=cyan>risk_score:</>          {$review['risk_score']} / 10");
        $this->line("  <fg=cyan>confidence:</>          {$review['confidence']}");

        if (! empty($review['safety_notes'])) {
            $this->newLine();
            $this->info("─── Safety Notes ───");
            foreach ($review['safety_notes'] as $note) {
                $this->line("  • {$note}");
            }
        }

        $this->newLine();
        $this->info("────────────────── FULL JSON ──────────────────");
        $this->newLine();

        $output = [
            'fixture'    => $fixtureName,
            'model_used' => $response['model_used'],
            'test_result'=> $passed ? 'PASS' : 'FAIL',
            'good_signals' => array_map(fn ($v, $k) => ['signal' => $k, 'detected' => $v], $signals['good'], array_keys($signals['good'])),
            'forbidden_violations' => $signals['forbidden_found'],
            'review' => $review,
        ];

        $this->output->writeln(
            json_encode($output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
        );

        $this->newLine();
        $this->info("Acceptance test complete.");

        return $passed ? Command::SUCCESS : Command::FAILURE;
    }

    private function buildSystemPrompt(array $fixture): string
    {
        $validActions = implode("\n  - ", self::VALID_ACTIONS);
        $forbiddenList = implode("\n  - ", self::FORBIDDEN);

        return <<<PROMPT
Ты — аналитик data integrity для системы управления торговым рынком.
Твоя задача: обнаружить проблему "merge tail" и предложить безопасный план диагностики.

Контекст:
- "Merge tail" — ситуация, когда старый арендатор (tenant) был деактивирован и слит
  в канонический (canonical tenant), но некоторые активные записи (spaces, contracts)
  всё ещё ссылаются на старый tenant.
- Это data integrity bug: данные "зависли" между двумя записями.
- Ты НЕ чинишь данные автоматически. Ты диагностируешь и рекомендуешь.

Доступные действия (рекомендации):
  - {$validActions}

⛔ ЗАПРЕЩЕНО рекомендовать:
  - {$forbiddenList}

Ты ДОЛЖЕН:
1. Распознать паттерн merge tail (старый tenant deactive + merged_into_* в notes)
2. Назвать canonical tenant (по merged_into_tenant_id)
3. Перечислить orphan records (spaces/contracts на старом tenant)
4. Предложить safe diagnostic/repair plan
5. Потребовать dry-run или manual review перед любыми изменениями
6. НЕ предлагать удаление старого tenant, пока есть dangling references
7. НЕ предлагать деактивацию canonical tenant

Формат ответа — СТРОГО JSON:
{
  "diagnosis": "Краткое описание проблемы, 2-3 предложения",
  "old_tenant_id": 9,
  "canonical_tenant_id": 106,
  "orphan_count_spaces": 2,
  "orphan_count_contracts": 2,
  "recommended_action": "Конкретный план действий, 3-5 предложений",
  "risk_score": 8,
  "confidence": 0.85,
  "safety_notes": ["Заметка 1", "Заметка 2"]
}

НЕ добавляй markdown-обёртки, НЕ добавляй текст до или после JSON.
PROMPT;
    }

    private function buildUserMessage(array $fixture): string
    {
        $parts = [];
        $parts[] = 'Данные для анализа:';
        $parts[] = '';

        $old = $fixture['old_tenant'];
        $parts[] = '[old_tenant]';
        $parts[] = "- id: {$old['id']}";
        $parts[] = "- name: {$old['name']}";
        $parts[] = "- is_active: " . ($old['is_active'] ? 'true' : 'false');
        $parts[] = "- external_id: {$old['external_id']}";
        $parts[] = "- notes: {$old['notes']}";
        $parts[] = '';

        $canon = $fixture['canonical_tenant'];
        $parts[] = '[canonical_tenant]';
        $parts[] = "- id: {$canon['id']}";
        $parts[] = "- name: {$canon['name']}";
        $parts[] = "- is_active: " . ($canon['is_active'] ? 'true' : 'false');
        $parts[] = "- external_id: {$canon['external_id']}";
        $parts[] = "- notes: {$canon['notes']}";
        $parts[] = '';

        $meta = $fixture['merge_metadata'];
        $parts[] = '[merge_metadata]';
        $parts[] = "- merged_at: {$meta['merged_at']}";
        $parts[] = "- merge_command: {$meta['merge_command']}";
        $parts[] = "- merged_into_tenant_id: {$meta['merged_into_tenant_id']}";
        $parts[] = '';

        $orphanSpaces = $fixture['orphan_spaces'];
        $parts[] = '[orphan_spaces — активные spaces на старом tenant]';
        $parts[] = "Количество: " . count($orphanSpaces);
        foreach ($orphanSpaces as $s) {
            $parts[] = "- space #{$s['id']} ({$s['number']}): status={$s['status']}, active=" . ($s['is_active'] ? 'yes' : 'no') . ", area={$s['area_sqm']}м²";
        }
        $parts[] = '';

        $orphanContracts = $fixture['orphan_contracts'];
        $parts[] = '[orphan_contracts — активные contracts на старом tenant]';
        $parts[] = "Количество: " . count($orphanContracts);
        foreach ($orphanContracts as $c) {
            $parts[] = "- contract #{$c['id']} ({$c['contract_number']}): ext_id={$c['external_id']}, space_id={$c['market_space_id']}, active=" . ($c['is_active'] ? 'yes' : 'no') . ", rent={$c['monthly_rent']}";
        }
        $parts[] = '';

        $canonState = $fixture['canonical_state'];
        $parts[] = '[canonical_state]';
        $parts[] = "- spaces on canonical: {$canonState['spaces_count']}";
        $parts[] = "- contracts on canonical: {$canonState['contracts_count']}";
        $parts[] = '';

        $fin = $fixture['financial_summary'];
        $parts[] = '[financial_summary]';
        $parts[] = "- orphan monthly rent: {$fin['orphan_contracts_total_monthly_rent']}";
        $parts[] = "- has 1C external IDs: " . ($fin['orphan_contracts_with_1c_external_ids'] ? 'yes' : 'no');
        $parts[] = "- debt reassignment risk: {$fin['potential_debt_reassignment_risk']}";

        return implode("\n", $parts);
    }

    /**
     * @return array{ok: bool, data: array|null, error: string|null, raw: string|null}
     */
    private function parseAndValidate(?string $raw): array
    {
        if ($raw === null) {
            return ['ok' => false, 'data' => null, 'error' => 'Пустой ответ', 'raw' => null];
        }

        $jsonStr = $this->extractJson($raw);
        if ($jsonStr === null) {
            return ['ok' => false, 'data' => null, 'error' => 'Не найден JSON', 'raw' => $raw];
        }

        $decoded = json_decode($jsonStr, true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            return ['ok' => false, 'data' => null, 'error' => 'JSON parse: ' . json_last_error_msg(), 'raw' => $raw];
        }

        // Schema validation
        $required = ['diagnosis', 'old_tenant_id', 'canonical_tenant_id', 'orphan_count_spaces', 'orphan_count_contracts', 'recommended_action', 'risk_score', 'confidence', 'safety_notes'];
        foreach ($required as $field) {
            if (! isset($decoded[$field])) {
                return ['ok' => false, 'data' => null, 'error' => "Missing field: {$field}", 'raw' => $raw];
            }
        }

        // Type checks
        if (! is_string($decoded['diagnosis'])) {
            return ['ok' => false, 'data' => null, 'error' => 'diagnosis must be string', 'raw' => $raw];
        }
        if (! is_int($decoded['old_tenant_id']) || $decoded['old_tenant_id'] <= 0) {
            return ['ok' => false, 'data' => null, 'error' => 'old_tenant_id must be positive int', 'raw' => $raw];
        }
        if (! is_int($decoded['canonical_tenant_id']) || $decoded['canonical_tenant_id'] <= 0) {
            return ['ok' => false, 'data' => null, 'error' => 'canonical_tenant_id must be positive int', 'raw' => $raw];
        }
        if (! is_int($decoded['orphan_count_spaces']) || $decoded['orphan_count_spaces'] < 0) {
            return ['ok' => false, 'data' => null, 'error' => 'orphan_count_spaces must be non-negative int', 'raw' => $raw];
        }
        if (! is_int($decoded['orphan_count_contracts']) || $decoded['orphan_count_contracts'] < 0) {
            return ['ok' => false, 'data' => null, 'error' => 'orphan_count_contracts must be non-negative int', 'raw' => $raw];
        }
        if (! is_string($decoded['recommended_action'])) {
            return ['ok' => false, 'data' => null, 'error' => 'recommended_action must be string', 'raw' => $raw];
        }
        if (! is_int($decoded['risk_score']) || $decoded['risk_score'] < 1 || $decoded['risk_score'] > 10) {
            return ['ok' => false, 'data' => null, 'error' => 'risk_score must be int 1-10', 'raw' => $raw];
        }
        $confidence = (float) $decoded['confidence'];
        if ($confidence < 0.0 || $confidence > 1.0) {
            return ['ok' => false, 'data' => null, 'error' => 'confidence must be 0.0-1.0', 'raw' => $raw];
        }
        if (! is_array($decoded['safety_notes'])) {
            return ['ok' => false, 'data' => null, 'error' => 'safety_notes must be array', 'raw' => $raw];
        }

        $decoded['confidence'] = $confidence;

        return ['ok' => true, 'data' => $decoded, 'error' => null, 'raw' => null];
    }

    /**
     * Проверка good signals и forbidden recommendations.
     */
    private function checkSignals(array $review): array
    {
        $text = strtolower($review['diagnosis'] . ' ' . $review['recommended_action'] . ' ' . implode(' ', $review['safety_notes']));
        $action = strtolower($review['recommended_action']);

        // Good signals
        $good = [];
        $good['merged'] = preg_match('/(merge|merged|merged_into|слит|слияние|объедин)/iu', $text) === 1;
        $good['canonical'] = preg_match('/(canonical|канонич|tenant\s*106|106\s*tenant|id\s*:\s*106|арендатор.*106|106.*арендатор)/iu', $text) === 1;
        $good['reassign'] = preg_match('/(reassign|перенести|перепривяз|reassign.*canonical|canonical.*space|перенос.*данных|данных.*канон)/iu', $text) === 1;
        $good['dry_run'] = preg_match('/(dry.?run|dry_run|тестов|проверк.*перед|before.*apply|сначала.*провер|dry.*run.*команд)/iu', $text) === 1;
        $good['manual_review'] = preg_match('/(manual.*review|ручн.*review|ручн.*провер|admin.*check|управляющ|manual_review|проверк.*всех)/iu', $text) === 1;
        $good['risk_aware'] = $review['risk_score'] >= 7;
        $good['no_mutation'] = ! preg_match('/(apply.*now|сразу.*примен|немедлен.*измен|delete.*tenant)/iu', $text);

        $passed = count(array_filter($good)) >= 4; // Минимум 4 из 7

        // Forbidden checks
        $forbiddenFound = [];
        foreach (self::FORBIDDEN as $phrase) {
            if (str_contains($text, strtolower($phrase))) {
                $forbiddenFound[] = $phrase;
            }
        }

        return [
            'good'            => $good,
            'forbidden_found' => $forbiddenFound,
            'passed'          => $passed,
        ];
    }

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
