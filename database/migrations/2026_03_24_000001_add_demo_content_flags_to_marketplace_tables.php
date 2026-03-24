<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('marketplace_products') && ! Schema::hasColumn('marketplace_products', 'is_demo')) {
            Schema::table('marketplace_products', function (Blueprint $table): void {
                $table->boolean('is_demo')->default(false)->after('is_featured');
                $table->index(['market_id', 'is_demo', 'is_active'], 'marketplace_products_market_demo_active_idx');
            });
        }

        if (Schema::hasTable('tenant_showcases') && ! Schema::hasColumn('tenant_showcases', 'is_demo')) {
            Schema::table('tenant_showcases', function (Blueprint $table): void {
                $table->boolean('is_demo')->default(false)->after('photos');
                $table->index(['tenant_id', 'is_demo'], 'tenant_showcases_tenant_demo_idx');
            });
        }

        if (Schema::hasTable('tenant_space_showcases') && ! Schema::hasColumn('tenant_space_showcases', 'is_demo')) {
            Schema::table('tenant_space_showcases', function (Blueprint $table): void {
                $table->boolean('is_demo')->default(false)->after('is_active');
                $table->index(['tenant_id', 'market_space_id', 'is_demo'], 'tenant_space_showcases_tenant_space_demo_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('marketplace_products') && Schema::hasColumn('marketplace_products', 'is_demo')) {
            Schema::table('marketplace_products', function (Blueprint $table): void {
                $table->dropIndex('marketplace_products_market_demo_active_idx');
                $table->dropColumn('is_demo');
            });
        }

        if (Schema::hasTable('tenant_showcases') && Schema::hasColumn('tenant_showcases', 'is_demo')) {
            Schema::table('tenant_showcases', function (Blueprint $table): void {
                $table->dropIndex('tenant_showcases_tenant_demo_idx');
                $table->dropColumn('is_demo');
            });
        }

        if (Schema::hasTable('tenant_space_showcases') && Schema::hasColumn('tenant_space_showcases', 'is_demo')) {
            Schema::table('tenant_space_showcases', function (Blueprint $table): void {
                $table->dropIndex('tenant_space_showcases_tenant_space_demo_idx');
                $table->dropColumn('is_demo');
            });
        }
    }
};
