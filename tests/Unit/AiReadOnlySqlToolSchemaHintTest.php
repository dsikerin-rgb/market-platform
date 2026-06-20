<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Ai\AiAgentSettings;
use App\Services\Ai\AiReadOnlySqlTool;
use PHPUnit\Framework\TestCase;

class AiReadOnlySqlToolSchemaHintTest extends TestCase
{
    public function test_schema_hint_exposes_rent_rate_sources(): void
    {
        $hint = (new AiReadOnlySqlTool)->schemaHint(7, []);

        self::assertContains('market_space_tenant_bindings', AiAgentSettings::defaultAllowedTables());
        self::assertStringContainsString('rent_rate_value', $hint);
        self::assertStringContainsString('market_space_tenant_bindings', $hint);
        self::assertStringContainsString('tenant_accruals', $hint);
        self::assertStringContainsString('rent_rate', $hint);
    }
}
