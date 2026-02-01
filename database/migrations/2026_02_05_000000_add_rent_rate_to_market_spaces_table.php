<?php

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
        Schema::table('market_spaces', function (Blueprint $table) {
            $table->decimal('rent_rate_value', 12, 2)->nullable()->after('area_sqm');
            $table->string('rent_rate_unit', 32)->nullable()->after('rent_rate_value');
            $table->dateTime('rent_rate_updated_at')->nullable()->after('rent_rate_unit');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('market_spaces', function (Blueprint $table) {
            $table->dropColumn(['rent_rate_value', 'rent_rate_unit', 'rent_rate_updated_at']);
        });
    }
};
