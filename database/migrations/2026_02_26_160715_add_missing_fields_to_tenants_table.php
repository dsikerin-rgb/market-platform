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
        Schema::table('tenants', function (Blueprint $table) {
            // КПП — нужна для финансовых операций
            $table->string('kpp')->nullable()->after('inn');
            
            // Полная информация контрагента из 1С (JSON)
            // Сюда будут сохраняться: ОГРН, страна, тип, ответственный и т.д.
            $table->json('one_c_data')->nullable()->after('requisites');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['kpp', 'one_c_data']);
        });
    }
};