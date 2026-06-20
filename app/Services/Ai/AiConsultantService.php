<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\User;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Str;

class AiConsultantService
{
    /**
     * @return array{answer:string,error_type:'provider'|'auth'|'connectivity'|null}
     */
    public function answer(User $user, int $marketId, string $question): array
    {
        $question = trim($question);
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

        $context = app(AiConsultantContextBuilder::class)->build($user, $marketId, $question);

        $client = new GigaChatClient(
            http: app(Http::class),
            authKey: config('gigachat.auth_key'),
            scope: config('gigachat.scope'),
            model: config('gigachat.model'),
            verifySsl: (bool) config('gigachat.verify_ssl', true),
        );

        $response = $client->chat([
            ['role' => 'system', 'content' => $this->systemPrompt()],
            ['role' => 'user', 'content' => $this->userPrompt($question, $context)],
        ], temperature: 0.0, maxTokens: 1600);

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

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
Ты ИИ-консультант Market Platform для сотрудников рынка.
Отвечай по-русски, коротко и предметно.
Используй только переданный read-only контекст из базы данных.
Не утверждай факты, которых нет в контексте. Если данных не хватает, прямо скажи, что нужно открыть карточку или уточнить запрос.
Не предлагай выполнить изменение в базе напрямую. Можно предлагать только проверку, ручное действие сотрудника или переход в карточку сущности.
Не раскрывай технические секреты, токены, пароли и системные настройки.
Если в контексте есть ID сущностей, указывай их, чтобы сотрудник мог быстро найти запись.
PROMPT;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function userPrompt(string $question, array $context): string
    {
        return "Вопрос сотрудника:\n{$question}\n\nКонтекст из БД:\n"
            . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
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
