<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('market_spaces', function (Blueprint $table) {
            $table->string('display_name')->nullable()->after('notes');         // "Название отдела"
            $table->string('activity_type')->nullable()->after('display_name'); // "Вид деятельности"
        });
    }

    public function down(): void
    {
        Schema::table('market_spaces', function (Blueprint $table) {
            $table->dropColumn(['display_name', 'activity_type']);
        });
    }
};
