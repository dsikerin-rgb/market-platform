<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('markets', function (Blueprint $table) {
            if (! Schema::hasColumn('markets', 'features')) {
                $table->json('features')->nullable()->after('settings');
            }
        });

        Schema::table('tenants', function (Blueprint $table) {
            if (! Schema::hasColumn('tenants', 'requisites')) {
                $table->json('requisites')->nullable()->after('notes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('markets', function (Blueprint $table) {
            if (Schema::hasColumn('markets', 'features')) {
                $table->dropColumn('features');
            }
        });

        Schema::table('tenants', function (Blueprint $table) {
            if (Schema::hasColumn('tenants', 'requisites')) {
                $table->dropColumn('requisites');
            }
        });
    }
};
