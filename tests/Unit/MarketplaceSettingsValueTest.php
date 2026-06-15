<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\MarketplaceSettingsValue;
use PHPUnit\Framework\TestCase;

class MarketplaceSettingsValueTest extends TestCase
{
    public function test_nullable_path_handles_empty_file_upload_state(): void
    {
        self::assertNull(MarketplaceSettingsValue::nullablePath([]));
        self::assertNull(MarketplaceSettingsValue::nullablePath(['']));
    }

    public function test_nullable_path_extracts_file_upload_path(): void
    {
        self::assertSame(
            'marketplace/brand/logo.png',
            MarketplaceSettingsValue::nullablePath(['marketplace/brand/logo.png'])
        );

        self::assertSame(
            'marketplace/brand/logo.png',
            MarketplaceSettingsValue::nullablePath(['upload' => ['marketplace/brand/logo.png']])
        );
    }
}
