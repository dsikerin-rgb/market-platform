<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class MarketAttentionWidgetBladeTest extends TestCase
{
    public function test_empty_attention_state_is_readable_inline_status(): void
    {
        $blade = file_get_contents(__DIR__.'/../../resources/views/filament/widgets/market-attention-widget.blade.php');

        self::assertIsString($blade);
        self::assertStringContainsString('background: rgba(240, 253, 244, 0.96);', $blade);
        self::assertStringContainsString('border: 1px solid rgba(34, 197, 94, 0.24);', $blade);
        self::assertStringContainsString('role="status"', $blade);
        self::assertStringContainsString('aria-live="polite"', $blade);
        self::assertStringContainsString("\$useToastStack && \$items !== [] ? 'market-attention-widget__toast-layout' : 'space-y-5'", $blade);
        self::assertStringNotContainsString('.market-attention-widget__toast-empty {', $blade);
        self::assertStringNotContainsString("class=\"market-attention-widget__empty relative z-10{{ \$useToastStack ? ' market-attention-widget__toast-empty' : '' }}\"", $blade);
    }
}
