<?php
# database/migrations/2026_03_13_000000_increase_contract_number_length.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('tenant_contracts') && Schema::hasColumn('tenant_contracts', 'number')) {
            Schema::table('tenant_contracts', function (Blueprint $table) {
                $table->string('number', 255)->change();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('tenant_contracts') && Schema::hasColumn('tenant_contracts', 'number')) {
            Schema::table('tenant_contracts', function (Blueprint $table) {
                $table->string('number', 50)->change();
            });
        }
    }
};
