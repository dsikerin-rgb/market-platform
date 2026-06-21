<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_user_profiles', function (Blueprint $table): void {
            $table->string('preferred_name')->nullable()->after('market_id');
        });
    }

    public function down(): void
    {
        Schema::table('ai_user_profiles', function (Blueprint $table): void {
            $table->dropColumn('preferred_name');
        });
    }
};
