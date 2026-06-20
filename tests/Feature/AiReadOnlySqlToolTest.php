<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Market;
use App\Models\Tenant;
use App\Services\Ai\AiAgentSettings;
use App\Services\Ai\AiReadOnlySqlTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiReadOnlySqlToolTest extends TestCase
{
    use RefreshDatabase;

    public function test_read_only_sql_tool_executes_select_for_current_market(): void
    {
        $market = Market::query()->create([
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        Tenant::query()->create([
            'market_id' => (int) $market->id,
            'name' => 'Главный должник',
            'external_id' => 'tenant-ai-sql-001',
            'is_active' => true,
        ]);

        $result = app(AiReadOnlySqlTool::class)->run(
            (int) $market->id,
            'select id, name from tenants where market_id = ' . (int) $market->id . ' order by id',
            app(AiAgentSettings::class)->get(),
        );

        $this->assertTrue($result['ok'], (string) $result['error']);
        $this->assertSame('Главный должник', $result['rows'][0]['name'] ?? null);
    }

    public function test_read_only_sql_tool_rejects_mutations_and_queries_without_market_scope(): void
    {
        $settings = app(AiAgentSettings::class)->get();
        $tool = app(AiReadOnlySqlTool::class);

        $update = $tool->run(1, "update tenants set name = 'bad' where market_id = 1", $settings);
        $withoutMarket = $tool->run(1, 'select id, name from tenants', $settings);

        $this->assertFalse($update['ok']);
        $this->assertFalse($withoutMarket['ok']);
        $this->assertStringContainsString('чтения', (string) $update['error']);
        $this->assertStringContainsString('текущим рынком', (string) $withoutMarket['error']);
    }
}
