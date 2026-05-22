<?php
# app/Services/Ai/GigaChatClient.php

declare(strict_types=1);

namespace App\Services\Ai;

use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Cache;

/**
 * Minimal HTTP client for GigaChat API.
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
     * @param  array<int, array{role: string, content: string}>  $messages
     * @return array{
     *   ok: bool,
     *   content: string|null,
     *   error: string|null,
     *   model_used: string|null,
     *   status: int|null,
     *   failure_kind: 'billing'|'rate_limit'|'auth'|'provider_http'|'network'|'empty_content'|null
     * }
     */
    public function chat(array $messages, ?float $temperature = 0.0, ?int $maxTokens = 2000): array
    {
        if (empty($this->authKey)) {
            return [
                'ok' => false,
                'content' => null,
                'error' => 'GIGACHAT_AUTH_KEY is not set',
                'model_used' => null,
                'status' => null,
                'failure_kind' => 'auth',
            ];
        }

        $tokenResult = $this->getAccessToken();
        if (! $tokenResult['ok']) {
            return [
                'ok' => false,
                'content' => null,
                'error' => $tokenResult['error'] ?: 'Failed to get access token from GigaChat',
                'model_used' => null,
                'status' => $tokenResult['status'],
                'failure_kind' => $tokenResult['failure_kind'],
            ];
        }

        $payload = [
            'model' => $this->model,
            'messages' => $messages,
            'temperature' => $temperature,
        ];

        if ($maxTokens !== null) {
            $payload['max_tokens'] = $maxTokens;
        }

        try {
            $response = $this->http
                ->timeout(60)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $tokenResult['token'],
                    'Content-Type' => 'application/json',
                ])
                ->withOptions([
                    'verify' => $this->verifySsl,
                ])
                ->post(self::chatUrl(), $payload);

            if (! $response->successful()) {
                return [
                    'ok' => false,
                    'content' => null,
                    'error' => sprintf(
                        'GigaChat HTTP %d: %s',
                        $response->status(),
                        $response->body()
                    ),
                    'model_used' => null,
                    'status' => $response->status(),
                    'failure_kind' => $this->failureKindForStatus($response->status()),
                ];
            }

            $body = $response->json();
            $content = $body['choices'][0]['message']['content'] ?? null;
            $modelUsed = $body['model'] ?? $this->model;

            if (! is_string($content) || $content === '') {
                return [
                    'ok' => false,
                    'content' => null,
                    'error' => 'GigaChat returned empty content',
                    'model_used' => $modelUsed,
                    'status' => $response->status(),
                    'failure_kind' => 'empty_content',
                ];
            }

            return [
                'ok' => true,
                'content' => $content,
                'error' => null,
                'model_used' => $modelUsed,
                'status' => $response->status(),
                'failure_kind' => null,
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'content' => null,
                'error' => 'GigaChat request error: ' . $e->getMessage(),
                'model_used' => null,
                'status' => null,
                'failure_kind' => 'network',
            ];
        }
    }

    /**
     * @return array{
     *   ok: bool,
     *   token: string|null,
     *   error: string|null,
     *   status: int|null,
     *   failure_kind: 'billing'|'rate_limit'|'auth'|'provider_http'|'network'|'empty_content'|null
     * }
     */
    private function getAccessToken(): array
    {
        $cacheKey = 'gigachat_access_token';
        $cached = Cache::get($cacheKey);

        if (is_string($cached) && $cached !== '') {
            return [
                'ok' => true,
                'token' => $cached,
                'error' => null,
                'status' => null,
                'failure_kind' => null,
            ];
        }

        $result = $this->fetchAccessToken();

        if ($result['ok'] && is_string($result['token']) && $result['token'] !== '') {
            Cache::put($cacheKey, $result['token'], now()->addMinutes(25));
        }

        return $result;
    }

    /**
     * @return array{
     *   ok: bool,
     *   token: string|null,
     *   error: string|null,
     *   status: int|null,
     *   failure_kind: 'billing'|'rate_limit'|'auth'|'provider_http'|'network'|'empty_content'|null
     * }
     */
    private function fetchAccessToken(): array
    {
        try {
            $response = $this->http
                ->timeout(15)
                ->withHeaders([
                    'Authorization' => 'Basic ' . $this->authKey,
                    'Accept' => 'application/json',
                    'RqUID' => (string) \Str::uuid(),
                ])
                ->withOptions([
                    'verify' => $this->verifySsl,
                ])
                ->asForm()
                ->post(self::authUrl(), [
                    'scope' => $this->scope,
                ]);

            if (! $response->successful()) {
                logger()->error('GigaChat auth failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return [
                    'ok' => false,
                    'token' => null,
                    'error' => sprintf(
                        'GigaChat auth HTTP %d: %s',
                        $response->status(),
                        $response->body()
                    ),
                    'status' => $response->status(),
                    'failure_kind' => $this->failureKindForStatus($response->status()),
                ];
            }

            $body = $response->json();
            $token = $body['access_token'] ?? null;

            if (! is_string($token) || $token === '') {
                logger()->error('GigaChat auth empty token response', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return [
                    'ok' => false,
                    'token' => null,
                    'error' => 'GigaChat auth returned empty access token',
                    'status' => $response->status(),
                    'failure_kind' => 'provider_http',
                ];
            }

            return [
                'ok' => true,
                'token' => $token,
                'error' => null,
                'status' => $response->status(),
                'failure_kind' => null,
            ];
        } catch (\GuzzleHttp\Exception\ConnectException $e) {
            logger()->error('GigaChat auth connection error', [
                'message' => $e->getMessage(),
                'url' => self::authUrl(),
            ]);

            return [
                'ok' => false,
                'token' => null,
                'error' => 'GigaChat auth connection error: ' . $e->getMessage(),
                'status' => null,
                'failure_kind' => 'network',
            ];
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            logger()->error('GigaChat auth request error', [
                'message' => $e->getMessage(),
                'response' => $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : null,
            ]);

            return [
                'ok' => false,
                'token' => null,
                'error' => 'GigaChat auth request error: ' . $e->getMessage(),
                'status' => null,
                'failure_kind' => 'network',
            ];
        } catch (\Throwable $e) {
            logger()->error('GigaChat auth unknown error', ['message' => $e->getMessage()]);

            return [
                'ok' => false,
                'token' => null,
                'error' => 'GigaChat auth unknown error: ' . $e->getMessage(),
                'status' => null,
                'failure_kind' => 'network',
            ];
        }
    }

    public function clearTokenCache(): void
    {
        Cache::forget('gigachat_access_token');
    }

    private function failureKindForStatus(int $status): string
    {
        return match ($status) {
            402 => 'billing',
            429 => 'rate_limit',
            401, 403 => 'auth',
            default => 'provider_http',
        };
    }
}
