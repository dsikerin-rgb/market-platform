<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\User;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Str;

class AiConsultantService
{
    /**
     * @param list<array{role:string,content:string}> $history
     * @return array{answer:string,error_type:'provider'|'auth'|'connectivity'|null}
     */
    public function answer(User $user, int $marketId, string $question, array $history = []): array
    {
        $question = trim($question);
        $settings = app(AiAgentSettings::class)->get();
        $settings['_market_id'] = $marketId;

        if (! (bool) $settings['enabled']) {
            return [
                'answer' => 'ИИ-консультант отключён в настройках.',
                'error_type' => null,
            ];
        }

        if ($question === '') {
            return [
                'answer' => 'Напишите вопрос по рынку, арендатору, месту, договору, задолженности или 1С-сверке.',
                'error_type' => null,
            ];
        }

        if (! filled(config('gigachat.auth_key'))) {
            return [
                'answer' => 'ИИ-консультант отключён: в окружении не задан GIGACHAT_AUTH_KEY.',
                'error_type' => 'auth',
            ];
        }

        $context = (bool) $settings['context_pack_enabled']
            ? app(AiConsultantContextBuilder::class)->build($user, $marketId, $question)
            : [
                'scope' => [
                    'market_id' => $marketId > 0 ? $marketId : null,
                    'user_id' => (int) $user->id,
                ],
            ];

        $client = new GigaChatClient(
            http: app(Http::class),
            authKey: config('gigachat.auth_key'),
            scope: config('gigachat.scope'),
            model: config('gigachat.model'),
            verifySsl: (bool) config('gigachat.verify_ssl', true),
        );

        $messages = [
            ['role' => 'system', 'content' => $this->systemPrompt($settings, $marketId)],
            ...$this->historyMessages($history, (int) $settings['history_messages']),
            ['role' => 'user', 'content' => $this->userPrompt($question, $context)],
        ];

        $response = $this->chatWithTools($client, $messages, $settings);

        if (! $response['ok']) {
            logger()->warning('AI consultant request failed', [
                'user_id' => (int) $user->id,
                'market_id' => $marketId,
                'status' => $response['status'] ?? null,
                'failure_kind' => $response['failure_kind'] ?? null,
            ]);

            return [
                'answer' => $this->providerFallbackMessage($response['failure_kind'] ?? null),
                'error_type' => ($response['failure_kind'] ?? null) === 'auth' ? 'auth' : 'provider',
            ];
        }

        $answer = trim((string) $response['content']);
        if ($answer === '') {
            return [
                'answer' => 'ИИ-консультант вернул пустой ответ. Попробуйте уточнить вопрос.',
                'error_type' => 'provider',
            ];
        }

        return [
            'answer' => Str::limit($answer, 6000, '...'),
            'error_type' => null,
        ];
    }

    /**
     * @param array<int, array{role: string, content: string}> $messages
     * @param array<string, mixed> $settings
     * @return array{
     *   ok: bool,
     *   content: string|null,
     *   error: string|null,
     *   model_used: string|null,
     *   status: int|null,
     *   failure_kind: 'billing'|'rate_limit'|'auth'|'provider_http'|'network'|'empty_content'|null
     * }
     */
    private function chatWithTools(GigaChatClient $client, array $messages, array $settings): array
    {
        $maxRounds = (bool) $settings['read_only_sql_enabled']
            ? (int) $settings['max_tool_rounds']
            : 0;

        for ($round = 0; $round <= $maxRounds; $round++) {
            $response = $client->chat(
                $messages,
                temperature: (float) $settings['temperature'],
                maxTokens: (int) $settings['max_tokens'],
            );

            if (! $response['ok']) {
                return $response;
            }

            $content = trim((string) $response['content']);
            $toolRequest = $round < $maxRounds ? $this->extractReadSqlToolRequest($content) : null;

            if ($toolRequest === null) {
                return $response;
            }

            $toolResult = app(AiReadOnlySqlTool::class)->run(
                marketId: (int) data_get($settings, '_market_id', 0),
                sql: $toolRequest,
                settings: $settings,
            );

            $messages[] = ['role' => 'assistant', 'content' => $content];
            $messages[] = [
                'role' => 'user',
                'content' => "Результат проверки данных read_sql:\n"
                    . json_encode($toolResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
                    . "\n\nСформулируй ответ для сотрудника простым русским языком. Не показывай JSON и не упоминай SQL, если это не нужно.",
            ];
        }

        return [
            'ok' => false,
            'content' => null,
            'error' => 'AI consultant reached tool round limit',
            'model_used' => null,
            'status' => null,
            'failure_kind' => 'provider_http',
        ];
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function systemPrompt(array $settings, int $marketId): string
    {
        $settings['_market_id'] = $marketId;
        $prompt = trim((string) $settings['system_prompt']);

        if ((bool) $settings['read_only_sql_enabled']) {
            $prompt .= "\n\n" . app(AiReadOnlySqlTool::class)->schemaHint($marketId, $settings);
        }

        return $prompt;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function userPrompt(string $question, array $context): string
    {
        return "Вопрос сотрудника:\n{$question}\n\nКонтекст из БД:\n"
            . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    /**
     * @param list<array{role:string,content:string}> $history
     * @return list<array{role:string,content:string}>
     */
    private function historyMessages(array $history, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        return collect($history)
            ->filter(static fn (array $message): bool => in_array($message['role'] ?? '', ['user', 'assistant'], true)
                && trim((string) ($message['content'] ?? '')) !== '')
            ->take(-$limit)
            ->map(static fn (array $message): array => [
                'role' => (string) $message['role'],
                'content' => Str::limit(trim((string) $message['content']), 1800, '...'),
            ])
            ->values()
            ->all();
    }

    private function extractReadSqlToolRequest(string $content): ?string
    {
        $payload = $this->decodeToolPayload($content);
        if (! is_array($payload)) {
            return null;
        }

        $tool = strtolower(trim((string) ($payload['tool'] ?? $payload['name'] ?? '')));
        $sql = trim((string) ($payload['sql'] ?? ''));

        return $tool === 'read_sql' && $sql !== '' ? $sql : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function decodeToolPayload(string $content): ?array
    {
        $candidates = [trim($content)];

        if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/is', $content, $match) === 1) {
            $candidates[] = trim($match[1]);
        }

        if (preg_match('/(\{.*\})/s', $content, $match) === 1) {
            $candidates[] = trim($match[1]);
        }

        foreach ($candidates as $candidate) {
            try {
                $decoded = json_decode($candidate, true, 8, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    private function providerFallbackMessage(?string $failureKind): string
    {
        return match ($failureKind) {
            'billing' => 'ИИ-консультант недоступен: провайдер сообщил о проблеме оплаты или лимита.',
            'rate_limit' => 'ИИ-консультант временно недоступен: превышен лимит запросов провайдера.',
            'auth' => 'ИИ-консультант недоступен: провайдер отклонил авторизацию.',
            default => 'ИИ-консультант временно недоступен. Попробуйте повторить вопрос позже.',
        };
    }
}
