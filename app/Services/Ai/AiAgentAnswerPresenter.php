<?php

declare(strict_types=1);

namespace App\Services\Ai;

use Illuminate\Support\Str;

class AiAgentAnswerPresenter
{
    /**
     * @param  list<string>  $trustedHosts
     */
    public function __construct(
        private readonly array $trustedHosts = [],
    ) {}

    /**
     * @param  list<array{label:string,url:string}>  $chips
     * @return array{answer:string,chips:list<array{label:string,url:string}>}
     */
    public function present(string $answer, array $chips = []): array
    {
        $answer = trim($answer);
        $chips = $this->normalizeChips($chips);

        [$answer, $extractedChips] = $this->extractInternalLinks($answer);
        $chips = $this->normalizeChips([...$chips, ...$extractedChips]);
        $answer = $this->removeTechnicalIdentifiers($answer);
        $answer = $this->cleanWhitespace($answer);

        if ($answer === '' && $chips !== []) {
            $answer = 'Готово, ссылку добавил ниже.';
        }

        return [
            'answer' => $answer,
            'chips' => $chips,
        ];
    }

    /**
     * @return array{0:string,1:list<array{label:string,url:string}>}
     */
    private function extractInternalLinks(string $answer): array
    {
        $chips = [];

        $answer = preg_replace_callback(
            '/\[(?<label>[^\]]{1,160})\]\((?<url>https?:\/\/[^\s)]+|\/admin\/[^\s)]+)\)/iu',
            function (array $match) use (&$chips): string {
                $chip = $this->chipFromUrl($match['url'], $match['label']);
                if ($chip) {
                    $chips[] = $chip;

                    return '';
                }

                return $match[0];
            },
            $answer,
        ) ?? $answer;

        $answer = preg_replace_callback(
            '/(?<url>https?:\/\/[^\s<>()]+|\/admin\/[^\s<>()]+)/iu',
            function (array $match) use (&$chips): string {
                $chip = $this->chipFromUrl($match['url']);
                if ($chip) {
                    $chips[] = $chip;

                    return '';
                }

                return $match[0];
            },
            $answer,
        ) ?? $answer;

        return [$answer, $chips];
    }

    /**
     * @return array{label:string,url:string}|null
     */
    private function chipFromUrl(string $url, ?string $label = null): ?array
    {
        $url = trim($url, " \t\n\r\0\x0B.,;\"'`*");
        $path = parse_url($url, PHP_URL_PATH);
        if (! is_string($path) || ! str_starts_with($path, '/admin') || ! $this->isAllowedInternalUrl($url)) {
            return null;
        }

        $url = $this->normalizeInternalUrl($url);
        $path = (string) parse_url($url, PHP_URL_PATH);

        return [
            'label' => $this->humanLabel($path, $label),
            'url' => $url,
        ];
    }

    private function isAllowedInternalUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if ($host === null || $host === false || trim((string) $host) === '') {
            return str_starts_with($url, '/admin/');
        }

        $allowedHosts = $this->allowedHosts();

        return in_array(Str::lower((string) $host), $allowedHosts, true);
    }

    /**
     * @return list<string>
     */
    private function allowedHosts(): array
    {
        $hosts = collect($this->trustedHosts)
            ->map(static fn (string $host): string => Str::lower(trim($host)))
            ->filter()
            ->values()
            ->all();

        try {
            if (function_exists('config')) {
                $configuredHost = parse_url((string) config('app.url'), PHP_URL_HOST);
                if (is_string($configuredHost) && $configuredHost !== '') {
                    $hosts[] = Str::lower($configuredHost);
                }
            }

            if (function_exists('request')) {
                $requestHost = request()->getHost();
                if (is_string($requestHost) && $requestHost !== '') {
                    $hosts[] = Str::lower($requestHost);
                }
            }
        } catch (\Throwable) {
            // If the presenter is used outside Laravel bootstrap, keep only relative links eligible.
        }

        return array_values(array_unique($hosts));
    }

    private function humanLabel(string $path, ?string $label): string
    {
        $label = trim((string) $label);
        if ($label !== '' && ! str_contains($label, '://') && ! str_starts_with($label, '/admin')) {
            return Str::limit($label, 120, '');
        }

        return match (true) {
            preg_match('#^/admin/tenants(?:/(?:view|edit))?/\d+(?:/(?:view|edit))?$#', $path) === 1,
            preg_match('#^/admin/tenants/\d+(?:/(?:view|edit))?$#', $path) === 1 => 'Открыть арендатора',
            preg_match('#^/admin/market-spaces/\d+(?:/(?:view|edit))?$#', $path) === 1 => 'Открыть место',
            preg_match('#^/admin/tasks/\d+(?:/(?:view|edit))?$#', $path) === 1 => 'Открыть задачу',
            str_starts_with($path, '/admin/requests') => 'Открыть обращение',
            str_starts_with($path, '/admin/ai-agent-settings') => 'Настройки ИИ-агента',
            default => 'Открыть страницу',
        };
    }

    private function removeTechnicalIdentifiers(string $answer): string
    {
        $answer = preg_replace('/\s*\(?\b(?:id|ID)\s*[:#-]?\s*[`"\']?\d+[`"\']?\)?/u', '', $answer) ?? $answer;
        $answer = preg_replace('/\s*(?:с\s+)?идентификатор(?:ом|а)?\s*[`"\']?\d+[`"\']?/iu', '', $answer) ?? $answer;

        return preg_replace('/\s+([,.!?])/u', '$1', $answer) ?? $answer;
    }

    private function normalizeInternalUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (! is_string($path) || $path === '') {
            return $url;
        }

        $normalizedPath = $this->normalizeInternalPath($path);
        if ($normalizedPath === $path) {
            return $url;
        }

        if (str_starts_with($url, '/')) {
            $query = parse_url($url, PHP_URL_QUERY);

            return $normalizedPath.($query ? '?'.$query : '');
        }

        return preg_replace('#'.preg_quote($path, '#').'#', $normalizedPath, $url, 1) ?? $url;
    }

    private function normalizeInternalPath(string $path): string
    {
        if (preg_match('#^/admin/tenants/view/(\d+)$#', $path, $match) === 1) {
            return '/admin/tenants/'.$match[1].'/edit';
        }

        if (preg_match('#^/admin/tenants/(\d+)/view$#', $path, $match) === 1) {
            return '/admin/tenants/'.$match[1].'/edit';
        }

        return $path;
    }

    private function cleanWhitespace(string $answer): string
    {
        $answer = preg_replace('/[ \t]+/u', ' ', $answer) ?? $answer;
        $answer = preg_replace('/\n{3,}/u', "\n\n", $answer) ?? $answer;
        $answer = preg_replace('/^\s*[*_`~]{1,6}\s*$/m', '', $answer) ?? $answer;
        $answer = preg_replace('/^\s*(?:Откройте?|Открой)\s+(?:эту\s+)?ссылку\s+(?:в\s+браузере)?[:.]?\s*$/imu', '', $answer) ?? $answer;
        $answer = preg_replace('/^\s*(?:Даю|Вот)\s+ссылку[:.]?\s*$/imu', '', $answer) ?? $answer;

        return trim($answer);
    }

    /**
     * @param  list<array{label:string,url:string}>  $chips
     * @return list<array{label:string,url:string}>
     */
    private function normalizeChips(array $chips): array
    {
        $result = [];
        $seen = [];

        foreach ($chips as $chip) {
            $label = Str::limit(trim((string) ($chip['label'] ?? '')), 120, '');
            $url = $this->normalizeInternalUrl(trim((string) ($chip['url'] ?? '')));
            $key = $label.'|'.$url;

            if ($label === '' || $url === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $result[] = ['label' => $label, 'url' => $url];
        }

        return $result;
    }
}
