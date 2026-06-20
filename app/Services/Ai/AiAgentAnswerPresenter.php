<?php

declare(strict_types=1);

namespace App\Services\Ai;

use Illuminate\Support\Str;

class AiAgentAnswerPresenter
{
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
        if (! is_string($path) || ! str_starts_with($path, '/admin')) {
            return null;
        }

        return [
            'label' => $this->humanLabel($path, $label),
            'url' => $url,
        ];
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

    private function cleanWhitespace(string $answer): string
    {
        $answer = preg_replace('/[ \t]+/u', ' ', $answer) ?? $answer;
        $answer = preg_replace('/\n{3,}/u', "\n\n", $answer) ?? $answer;
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
            $url = trim((string) ($chip['url'] ?? ''));
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
