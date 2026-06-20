<?php

declare(strict_types=1);

namespace App\Services\Ai;

use Illuminate\Support\Str;

class AiContextBudgeter
{
    /**
     * @param  array<string,mixed>  $context
     * @param  array<string,mixed>  $settings
     * @return array<string,mixed>
     */
    public function compact(array $context, array $settings): array
    {
        $budgetChars = $this->tokenBudgetToChars((int) ($settings['context_budget_tokens'] ?? 1800));
        $itemLimit = max(1, min((int) ($settings['context_item_limit'] ?? 5), 20));

        $compact = $this->compactValue($context, $itemLimit, 420);
        $compact['_context_budget'] = [
            'mode' => 'compact',
            'budget_tokens' => (int) ($settings['context_budget_tokens'] ?? 1800),
            'item_limit' => $itemLimit,
            'note' => 'Контекст урезан до самых релевантных фрагментов; подробности можно проверить отдельным действием.',
        ];

        if ($this->jsonLength($compact) <= $budgetChars) {
            return $compact;
        }

        foreach ([3, 1] as $limit) {
            $compact = $this->compactValue($context, min($itemLimit, $limit), 260);
            $compact['_context_budget'] = [
                'mode' => 'tight',
                'budget_tokens' => (int) ($settings['context_budget_tokens'] ?? 1800),
                'item_limit' => min($itemLimit, $limit),
                'note' => 'Контекст сильно сокращён; для точного ответа агент должен запросить нужные данные отдельно.',
            ];

            if ($this->jsonLength($compact) <= $budgetChars) {
                return $compact;
            }
        }

        return [
            'scope' => $this->compactValue($context['scope'] ?? [], 1, 180),
            'current_page' => $this->compactValue($context['current_page'] ?? [], 1, 180),
            'question_terms' => $this->compactValue($context['question_terms'] ?? [], 1, 180),
            'overview' => $this->compactValue($context['overview'] ?? [], 1, 180),
            '_context_budget' => [
                'mode' => 'minimal',
                'budget_tokens' => (int) ($settings['context_budget_tokens'] ?? 1800),
                'note' => 'Передан минимальный контекст. Для ответа по деталям нужно выполнить проверку данных.',
            ],
        ];
    }

    /**
     * @param  list<array{role:string,content:string}>  $history
     * @param  array<string,mixed>  $settings
     * @return list<array{role:string,content:string}>
     */
    public function compactHistory(array $history, array $settings): array
    {
        $messageLimit = max(0, min((int) ($settings['history_messages'] ?? 8), 20));
        if ($messageLimit <= 0) {
            return [];
        }

        $budgetChars = $this->tokenBudgetToChars((int) ($settings['history_budget_tokens'] ?? 1000));
        $perMessageChars = max(220, (int) floor($budgetChars / max(1, $messageLimit)));
        $used = 0;
        $selected = [];

        $messages = collect($history)
            ->filter(static fn (array $message): bool => in_array($message['role'] ?? '', ['user', 'assistant'], true)
                && trim((string) ($message['content'] ?? '')) !== '')
            ->take(-$messageLimit)
            ->reverse();

        foreach ($messages as $message) {
            $content = Str::limit(trim((string) ($message['content'] ?? '')), $perMessageChars, '...');
            $length = mb_strlen($content);

            if ($selected !== [] && $used + $length > $budgetChars) {
                continue;
            }

            $selected[] = [
                'role' => (string) $message['role'],
                'content' => $content,
            ];
            $used += $length;
        }

        return array_reverse($selected);
    }

    private function compactValue(mixed $value, int $itemLimit, int $stringLimit): mixed
    {
        if (is_string($value)) {
            return Str::limit(trim($value), $stringLimit, '...');
        }

        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return collect($value)
                ->take($itemLimit)
                ->map(fn (mixed $item): mixed => $this->compactValue($item, $itemLimit, $stringLimit))
                ->values()
                ->all();
        }

        $result = [];
        foreach ($value as $key => $child) {
            $result[$key] = $this->compactValue($child, $itemLimit, $stringLimit);
        }

        return $result;
    }

    private function tokenBudgetToChars(int $tokens): int
    {
        return max(1200, min($tokens, 12000) * 4);
    }

    /**
     * @param  array<string,mixed>  $value
     */
    private function jsonLength(array $value): int
    {
        return mb_strlen((string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
