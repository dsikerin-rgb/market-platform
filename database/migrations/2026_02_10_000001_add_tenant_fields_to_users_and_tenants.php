<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'tenant_id')) {
                $table->foreignId('tenant_id')
                    ->nullable()
                    ->after('market_id')
                    ->constrained('tenants')
                    ->nullOnDelete();
            }
        });

        Schema::table('tenants', function (Blueprint $table) {
            if (! Schema::hasColumn('tenants', 'slug')) {
                $table->string('slug')->nullable()->after('short_name');
            }
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->unique('slug', 'tenants_slug_unique');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'tenant_id')) {
                $table->dropConstrainedForeignId('tenant_id');
            }
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropUnique('tenants_slug_unique');

            if (Schema::hasColumn('tenants', 'slug')) {
                $table->dropColumn('slug');
            }
        });
    }
};
