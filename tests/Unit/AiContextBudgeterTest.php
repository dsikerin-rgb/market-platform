<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Ai\AiContextBudgeter;
use PHPUnit\Framework\TestCase;

class AiContextBudgeterTest extends TestCase
{
    public function test_compacts_context_to_configured_budget(): void
    {
        $budgeter = new AiContextBudgeter;
        $longText = str_repeat('large context fragment ', 120);

        $context = [
            'scope' => ['market_id' => 1],
            'current_page' => ['path' => '/admin/tenants/15', 'title' => $longText],
            'question_terms' => [$longText, $longText, $longText],
            'overview' => ['summary' => $longText],
            'tenants' => array_fill(0, 25, [
                'name' => $longText,
                'comment' => $longText,
            ]),
        ];

        $result = $budgeter->compact($context, [
            'context_budget_tokens' => 400,
            'context_item_limit' => 2,
        ]);

        $encoded = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        self::assertIsString($encoded);
        self::assertLessThanOrEqual(1600, mb_strlen($encoded));
        self::assertArrayHasKey('_context_budget', $result);
        self::assertContains($result['_context_budget']['mode'], ['compact', 'tight', 'minimal']);
    }

    public function test_compacts_history_by_message_count_and_budget(): void
    {
        $budgeter = new AiContextBudgeter;
        $history = [];

        for ($i = 1; $i <= 10; $i++) {
            $history[] = [
                'role' => $i % 2 === 0 ? 'assistant' : 'user',
                'content' => "message {$i} ".str_repeat('details ', 120),
            ];
        }

        $result = $budgeter->compactHistory($history, [
            'history_messages' => 4,
            'history_budget_tokens' => 300,
        ]);

        $encoded = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        self::assertIsString($encoded);
        self::assertLessThanOrEqual(1200, mb_strlen($encoded));
        self::assertLessThanOrEqual(4, count($result));
        self::assertSame('assistant', $result[array_key_last($result)]['role']);
        self::assertStringContainsString('message 10', $result[array_key_last($result)]['content']);
    }
}
