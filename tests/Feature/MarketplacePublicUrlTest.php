<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Market;
use App\Models\User;
use App\Support\MarketplacePublicUrl;
use Filament\Facades\Filament;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MarketplacePublicUrlTest extends TestCase
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

        session()->flush();
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Schema::dropIfExists('markets');
        Schema::create('markets', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->nullable()->unique();
            $table->string('timezone')->default('Europe/Moscow');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function test_public_url_uses_market_slug_instead_of_marketplace_entry(): void
    {
        $market = Market::query()->create([
            'name' => 'Демо-рынок Центральный',
            'slug' => 'demo-market',
            'timezone' => 'Asia/Novosibirsk',
            'is_active' => true,
        ]);

        self::assertSame(
            route('marketplace.home', ['marketSlug' => 'demo-market']),
            app(MarketplacePublicUrl::class)->forMarket($market),
        );
    }

    public function test_super_admin_topbar_url_uses_selected_demo_market_context(): void
    {
        Market::query()->create([
            'name' => 'Эко Ярмарка',
            'slug' => 'ekoiarmarka-vdnx',
            'timezone' => 'Asia/Novosibirsk',
            'is_active' => true,
        ]);

        $demoMarket = Market::query()->create([
            'name' => 'Демо-рынок Центральный',
            'slug' => 'demo-market',
            'timezone' => 'Asia/Novosibirsk',
            'is_active' => true,
        ]);

        $user = new class extends User {
            public function isSuperAdmin(): bool
            {
                return true;
            }
        };

        session(['filament.admin.selected_market_id' => (int) $demoMarket->id]);

        self::assertSame(
            route('marketplace.home', ['marketSlug' => 'demo-market']),
            app(MarketplacePublicUrl::class)->forCurrentAdmin($user),
        );
    }

    public function test_market_without_slug_falls_back_to_market_id(): void
    {
        $market = Market::query()->create([
            'name' => 'Market without slug',
            'slug' => null,
            'timezone' => 'Asia/Novosibirsk',
            'is_active' => true,
        ]);

        self::assertSame(
            route('marketplace.home', ['marketSlug' => (string) $market->id]),
            app(MarketplacePublicUrl::class)->forMarket($market),
        );
    }
}
