<?php
# database/migrations/2026_03_01_000000_add_external_id_to_tenant_contracts_table.php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('tenant_contracts', 'external_id')) {
            return;
        }

        Schema::table('tenant_contracts', function (Blueprint $table): void {
            $table->string('external_id', 255)->nullable()->after('id');

            // Ключ для связки: (market_id + contract_external_id из 1С)
            $table->unique(['market_id', 'external_id'], 'tenant_contracts_market_external_id_unique');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('tenant_contracts', 'external_id')) {
            return;
        }

        Schema::table('tenant_contracts', function (Blueprint $table): void {
            $table->dropUnique('tenant_contracts_market_external_id_unique');
            $table->dropColumn('external_id');
        });
    }
};