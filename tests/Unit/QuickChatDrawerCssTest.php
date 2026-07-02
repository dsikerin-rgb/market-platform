<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class QuickChatDrawerCssTest extends TestCase
{
    public function test_dialog_list_hides_horizontal_overflow(): void
    {
        $blade = file_get_contents(__DIR__.'/../../resources/views/livewire/admin/quick-chat-drawer.blade.php');

        self::assertIsString($blade);
        self::assertStringContainsString('overflow-x: hidden;', $blade);
        self::assertStringContainsString('overflow-y: auto;', $blade);
        self::assertStringNotContainsString("max-height: calc(100vh - 7rem);\n            overflow: auto;", $blade);
    }

    public function test_message_composer_autogrows_after_livewire_updates(): void
    {
        $blade = file_get_contents(__DIR__.'/../../resources/views/livewire/admin/quick-chat-drawer.blade.php');

        self::assertIsString($blade);
        self::assertStringContainsString('box-sizing: border-box;', $blade);
        self::assertStringContainsString('max-height: 10rem;', $blade);
        self::assertStringContainsString('overflow-y: hidden;', $blade);
        self::assertStringContainsString("this.\$el.style.height = 'auto';", $blade);
        self::assertStringContainsString("this.\$el.style.overflowY = this.\$el.scrollHeight > maxHeight ? 'auto' : 'hidden';", $blade);
        self::assertStringContainsString('x-effect="$wire.messageBody; $nextTick(() => resize())"', $blade);
        self::assertStringContainsString('x-on:quick-chat-updated.window="$nextTick(() => resize())"', $blade);
    }
}
