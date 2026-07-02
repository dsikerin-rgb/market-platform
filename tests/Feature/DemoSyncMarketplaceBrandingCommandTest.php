<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Market;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DemoSyncMarketplaceBrandingCommandTest extends TestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();

        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite.database', ':memory:');
        $app['config']->set('database.connections.sqlite.foreign_key_constraints', false);

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('markets');
        Schema::create('markets', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->nullable()->unique();
            $table->string('timezone')->default('Europe/Moscow');
            $table->boolean('is_active')->default(true);
            $table->json('settings')->nullable();
            $table->json('features')->nullable();
            $table->timestamps();
        });
    }

    public function test_execute_updates_only_marketplace_settings_for_synthetic_demo_market(): void
    {
        $market = Market::query()->create([
            'name' => 'Демо-рынок Центральный',
            'slug' => 'demo-market',
            'timezone' => 'Asia/Novosibirsk',
            'is_active' => true,
            'settings' => [
                'demo_pilot' => [
                    'synthetic_source' => 'demo_pilot',
                ],
                'marketplace' => [
                    'brand_name' => 'Эко Ярмарка',
                    'custom_key' => 'keep-me',
                ],
            ],
        ]);

        $this->artisan('demo:sync-marketplace-branding', ['--execute' => true])
            ->assertExitCode(0);

        $market->refresh();

        self::assertSame('demo_pilot', data_get($market->settings, 'demo_pilot.synthetic_source'));
        self::assertSame('keep-me', data_get($market->settings, 'marketplace.custom_key'));
        self::assertSame('Демо-рынок Центральный', data_get($market->settings, 'marketplace.brand_name'));
        self::assertSame('Покупки на демо-рынке в одном месте', data_get($market->settings, 'marketplace.hero_title'));
        self::assertSame('marketplace/brand/demo-market-logo.svg', data_get($market->settings, 'marketplace.logo_path'));
    }

    public function test_execute_blocks_unmarked_market_even_when_slug_matches_demo_slug(): void
    {
        $market = Market::query()->create([
            'name' => 'Демо-рынок Центральный',
            'slug' => 'demo-market',
            'timezone' => 'Asia/Novosibirsk',
            'is_active' => true,
            'settings' => [],
        ]);

        $this->artisan('demo:sync-marketplace-branding', ['--execute' => true])
            ->assertExitCode(1);

        $market->refresh();

        self::assertSame([], $market->settings ?? []);
    }
}
