<?php
# database/migrations/2026_05_25_152500_add_shared_use_fields_to_market_space_tenant_bindings_table.php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('market_space_tenant_bindings', function (Blueprint $table): void {
            $table->decimal('area_sqm', 10, 2)->nullable()->after('ended_at');
            $table->decimal('rent_rate', 12, 2)->nullable()->after('area_sqm');
            $table->text('share_note')->nullable()->after('rent_rate');
        });
    }

    public function down(): void
    {
        Schema::table('market_space_tenant_bindings', function (Blueprint $table): void {
            $table->dropColumn(['area_sqm', 'rent_rate', 'share_note']);
        });
    }
};
