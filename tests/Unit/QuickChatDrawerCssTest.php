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
}
