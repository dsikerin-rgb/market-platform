<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\User;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Str;

class AiConsultantService
{
    /**
     * @param  list<array{role:string,content:string}>  $history
     * @param  array<string,mixed>  $pageContext
     * @return array{answer:string,error_type:'provider'|'auth'|'connectivity'|null,chips:list<array{label:string,url:string}>}
     */
    public function answer(User $user, int $marketId, string $question, array $history = [], array $pageContext = []): array
    {
        $question = trim($question);
        $settings = app(AiAgentSettings::class)->get();
        $settings['_market_id'] = $marketId;

        if (! (bool) $settings['enabled']) {
            return [
                'answer' => 'ИИ-консультант отключён в настройках.',
                'error_type' => null,
                'chips' => [],
            ];
        }

        if ($question === '') {
            return [
                'answer' => 'Напишите вопрос по рынку, арендатору, месту, договору, задолженности или 1С-сверке.',
                'error_type' => null,
                'chips' => [],
            ];
        }

        if (! filled(config('gigachat.auth_key'))) {
            return [
                'answer' => 'ИИ-консультант отключён: в окружении не задан GIGACHAT_AUTH_KEY.',
                'error_type' => 'auth',
                'chips' => [],
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

        if ((bool) $settings['page_context_enabled']) {
            $context['current_page'] = $this->pageContext($pageContext);
        }

        $budgeter = app(AiContextBudgeter::class);
        $context = $budgeter->compact($context, $settings);

        $client = new GigaChatClient(
            http: app(Http::class),
            authKey: config('gigachat.auth_key'),
            scope: config('gigachat.scope'),
            model: config('gigachat.model'),
            verifySsl: (bool) config('gigachat.verify_ssl', true),
        );

        $messages = [
            ['role' => 'system', 'content' => $this->systemPrompt($settings, $marketId, $user)],
            ...$budgeter->compactHistory($history, $settings),
            ['role' => 'user', 'content' => $this->userPrompt($question, $context)],
        ];

        $response = $this->chatWithTools($client, $messages, $settings, $user);

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
                'chips' => $response['chips'] ?? [],
            ];
        }

        $answer = trim((string) $response['content']);
        if ($answer === '') {
            return [
                'answer' => 'ИИ-консультант вернул пустой ответ. Попробуйте уточнить вопрос.',
                'error_type' => 'provider',
                'chips' => $response['chips'] ?? [],
            ];
        }

        $presented = app(AiAgentAnswerPresenter::class)->present($answer, $response['chips'] ?? []);

        return [
            'answer' => Str::limit($presented['answer'], 6000, '...'),
            'error_type' => null,
            'chips' => $presented['chips'],
        ];
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $settings
     * @return array{
     *   ok: bool,
     *   content: string|null,
     *   error: string|null,
     *   model_used: string|null,
     *   status: int|null,
     *   failure_kind: 'billing'|'rate_limit'|'auth'|'provider_http'|'network'|'empty_content'|null,
     *   chips?: list<array{label:string,url:string}>
     * }
     */
    private function chatWithTools(GigaChatClient $client, array $messages, array $settings, User $user): array
    {
        $toolsEnabled = (bool) $settings['read_only_sql_enabled'] || (bool) $settings['action_tools_enabled'];
        $maxRounds = $toolsEnabled
            ? (int) $settings['max_tool_rounds']
            : 0;
        $chips = [];

        for ($round = 0; $round <= $maxRounds; $round++) {
            $response = $client->chat(
                $messages,
                temperature: (float) $settings['temperature'],
                maxTokens: (int) $settings['max_tokens'],
            );

            if (! $response['ok']) {
                $response['chips'] = $chips;

                return $response;
            }

            $content = trim((string) $response['content']);
            $toolRequest = $round < $maxRounds ? $this->extractToolRequest($content) : null;

            if ($toolRequest === null) {
                $response['chips'] = $chips;

                return $response;
            }

            $toolResult = $this->runTool($user, $toolRequest, $settings);
            $chips = [
                ...$chips,
                ...$this->normalizeChips((array) ($toolResult['chips'] ?? [])),
            ];

            $messages[] = ['role' => 'assistant', 'content' => $content];
            $messages[] = [
                'role' => 'user',
                'content' => "Результат действия приложения:\n"
                    .json_encode($toolResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
                    ."\n\nЕсли действие уже выполнено успешно, не вызывай его повторно. Сформулируй ответ для сотрудника простым русским языком. Не показывай JSON, названия инструментов и технические детали.",
            ];
        }

        return [
            'ok' => false,
            'content' => null,
            'error' => 'AI consultant reached tool round limit',
            'model_used' => null,
            'status' => null,
            'failure_kind' => 'provider_http',
            'chips' => $chips,
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function systemPrompt(array $settings, int $marketId, User $user): string
    {
        $settings['_market_id'] = $marketId;
        $prompt = trim((string) $settings['system_prompt']);
        $friendlyName = $this->friendlyUserName($user);

        if ((bool) $settings['read_only_sql_enabled']) {
            $prompt .= "\n\n".app(AiReadOnlySqlTool::class)->schemaHint($marketId, $settings);
        }

        if ((bool) $settings['action_tools_enabled']) {
            $prompt .= "\n\n".app(AiAgentActionTool::class)->schemaHint();
        }

        if ($friendlyName !== '') {
            $prompt .= "\n\nСотрудника зовут {$friendlyName}. Обращайся к нему по имени, дружелюбно и спокойно, без полного ФИО и без официального канцелярского тона. Не начинай каждое сообщение с имени, используй имя естественно, когда это уместно.";
        }

        $prompt .= "\n\nКонтекст может быть сокращён для экономии. Если деталей не хватает, сам проверь нужные данные доступным действием и не выдумывай ответ. Не говори, что база недоступна, если инструмент чтения данных не вернул явную ошибку.";
        $prompt .= "\n\nНе упоминай пользователю идентификаторы, ID, названия таблиц, адреса страниц и сырые ссылки. Если нужно дать переход на арендатора, место, задачу, обращение, событие или настройки, используй действие resource_link/make_link, чтобы приложение показало ссылку отдельным чипом.";

        return $prompt;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function userPrompt(string $question, array $context): string
    {
        return "Вопрос сотрудника:\n{$question}\n\nКонтекст из БД:\n"
            .json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    private function friendlyUserName(User $user): string
    {
        $name = trim((string) ($user->name ?? ''));

        if ($name === '') {
            return '';
        }

        $parts = preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return trim((string) ($parts[0] ?? ''));
    }

    /**
     * @return array<string,mixed>|null
     */
    private function extractToolRequest(string $content): ?array
    {
        $payload = $this->decodeToolPayload($content);
        if (! is_array($payload)) {
            return null;
        }

        $tool = strtolower(trim((string) ($payload['tool'] ?? $payload['name'] ?? '')));

        return $tool !== '' ? $payload : null;
    }

    /**
     * @param  array<string,mixed>  $toolRequest
     * @param  array<string,mixed>  $settings
     * @return array<string,mixed>
     */
    private function runTool(User $user, array $toolRequest, array $settings): array
    {
        $tool = strtolower(trim((string) ($toolRequest['tool'] ?? $toolRequest['name'] ?? '')));

        if ($tool === 'read_sql') {
            if (! (bool) $settings['read_only_sql_enabled']) {
                return [
                    'ok' => false,
                    'message' => 'Проверка данных отключена в настройках.',
                    'chips' => [],
                ];
            }

            $sql = trim((string) ($toolRequest['sql'] ?? ''));

            return app(AiReadOnlySqlTool::class)->run(
                marketId: (int) data_get($settings, '_market_id', 0),
                sql: $sql,
                settings: $settings,
            );
        }

        if (! (bool) $settings['action_tools_enabled']) {
            return [
                'ok' => false,
                'message' => 'Рабочие действия отключены в настройках.',
                'chips' => [],
            ];
        }

        return app(AiAgentActionTool::class)->run(
            actor: $user,
            marketId: (int) data_get($settings, '_market_id', 0),
            payload: $toolRequest,
        );
    }

    /**
     * @param  array<int, mixed>  $chips
     * @return list<array{label:string,url:string}>
     */
    private function normalizeChips(array $chips): array
    {
        return collect($chips)
            ->filter(static fn (mixed $chip): bool => is_array($chip))
            ->map(static fn (array $chip): array => [
                'label' => Str::limit(trim((string) ($chip['label'] ?? '')), 120, ''),
                'url' => trim((string) ($chip['url'] ?? '')),
            ])
            ->filter(static fn (array $chip): bool => $chip['label'] !== '' && $chip['url'] !== '')
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $pageContext
     * @return array<string,string>
     */
    private function pageContext(array $pageContext): array
    {
        return [
            'url' => Str::limit(trim((string) ($pageContext['url'] ?? '')), 500, ''),
            'path' => Str::limit(trim((string) ($pageContext['path'] ?? '')), 300, ''),
            'title' => Str::limit(trim((string) ($pageContext['title'] ?? '')), 160, ''),
            'heading' => Str::limit(trim((string) ($pageContext['heading'] ?? '')), 160, ''),
        ];
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
