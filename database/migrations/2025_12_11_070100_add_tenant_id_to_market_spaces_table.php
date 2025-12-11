<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('market_spaces', function (Blueprint $table) {
            $table->foreignId('tenant_id')
                ->nullable()
                ->constrained('tenants')
                ->nullOnDelete()
                ->after('location_id');
        });
    }

    public function down(): void
    {
        Schema::table('market_spaces', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tenant_id');
        });
    }
};
