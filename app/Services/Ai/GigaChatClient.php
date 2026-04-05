<?php

declare(strict_types=1);

namespace App\Services\Ai;

use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Cache;

/**
 * Минимальный HTTP-клиент для GigaChat API.
 *
 * Без SDK — только OAuth-авторизация + chat completions.
 */
class GigaChatClient
{
    private const GIGACHAT_API_BASE = 'https://gigachat.devices.sberbank.ru';
    private const AUTH_API_BASE = 'https://ngw.devices.sberbank.ru:9443';

    public static function chatUrl(): string
    {
        return self::GIGACHAT_API_BASE . '/api/v1/chat/completions';
    }

    public static function authUrl(): string
    {
        return self::AUTH_API_BASE . '/api/v2/oauth';
    }

    public function __construct(
        private readonly Http $http,
        private readonly ?string $authKey,
        private readonly string $scope,
        private readonly string $model,
        private readonly bool $verifySsl = false,
    ) {
    }

    /**
     * Отправить запрос в GigaChat и вернуть ответ.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @return array{ok: bool, content: string|null, error: string|null, model_used: string|null}
     */
    public function chat(array $messages, ?float $temperature = 0.0, ?int $maxTokens = 2000): array
    {
        if (empty($this->authKey)) {
            return [
                'ok'         => false,
                'content'    => null,
                'error'      => 'GIGACHAT_AUTH_KEY не задан',
                'model_used' => null,
            ];
        }

        $token = $this->getAccessToken();
        if ($token === null) {
            return [
                'ok'         => false,
                'content'    => null,
                'error'      => 'Не удалось получить access token от GigaChat',
                'model_used' => null,
            ];
        }

        $payload = [
            'model'       => $this->model,
            'messages'    => $messages,
            'temperature' => $temperature,
        ];

        if ($maxTokens !== null) {
            $payload['max_tokens'] = $maxTokens;
        }

        try {
            $response = $this->http
                ->timeout(60)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type'  => 'application/json',
                ])
                ->withOptions([
                    'verify' => $this->verifySsl,
                ])
                ->post(self::GIGACHAT_API_BASE . '/api/v1/chat/completions', $payload);

            if (! $response->successful()) {
                return [
                    'ok'         => false,
                    'content'    => null,
                    'error'      => sprintf(
                        'GigaChat HTTP %d: %s',
                        $response->status(),
                        $response->body()
                    ),
                    'model_used' => null,
                ];
            }

            $body = $response->json();
            $content = $body['choices'][0]['message']['content'] ?? null;
            $modelUsed = $body['model'] ?? $this->model;

            return [
                'ok'         => true,
                'content'    => $content,
                'error'      => null,
                'model_used' => $modelUsed,
            ];
        } catch (\Throwable $e) {
            return [
                'ok'         => false,
                'content'    => null,
                'error'      => 'GigaChat request error: ' . $e->getMessage(),
                'model_used' => null,
            ];
        }
    }

    /**
     * Получить access token через OAuth.
     * Кэширует токен на 25 минут (токен живёт ~30 мин).
     */
    private function getAccessToken(): ?string
    {
        $cacheKey = 'gigachat_access_token';

        return Cache::remember($cacheKey, now()->addMinutes(25), function (): ?string {
            return $this->fetchAccessToken();
        });
    }

    private function fetchAccessToken(): ?string
    {
        try {
            $response = $this->http
                ->timeout(15)
                ->withHeaders([
                    'Authorization' => 'Basic ' . $this->authKey,
                    'Accept'        => 'application/json',
                    'RqUID'         => (string) \Str::uuid(),
                ])
                ->withOptions([
                    'verify' => $this->verifySsl,
                ])
                ->asForm()
                ->post(self::AUTH_API_BASE . '/api/v2/oauth', [
                    'scope' => $this->scope,
                ]);

            if (! $response->successful()) {
                logger()->error('GigaChat auth failed', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
                return null;
            }

            $body = $response->json();

            return $body['access_token'] ?? null;
        } catch (\GuzzleHttp\Exception\ConnectException $e) {
            logger()->error('GigaChat auth connection error', [
                'message' => $e->getMessage(),
                'url' => self::AUTH_API_BASE . '/api/v2/oauth',
            ]);
            return null;
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            logger()->error('GigaChat auth request error', [
                'message' => $e->getMessage(),
                'response' => $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : null,
            ]);
            return null;
        } catch (\Throwable $e) {
            logger()->error('GigaChat auth unknown error', ['message' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Инвалидировать кэш токена (для отладки).
     */
    public function clearTokenCache(): void
    {
        Cache::forget('gigachat_access_token');
    }
}
