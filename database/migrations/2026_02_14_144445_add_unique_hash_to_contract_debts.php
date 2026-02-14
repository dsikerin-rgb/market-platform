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
        Schema::table('contract_debts', function (Blueprint $table) {
            // Добавляем уникальный индекс на поле hash для защиты от дубликатов
            $table->unique('hash', 'contract_debts_hash_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contract_debts', function (Blueprint $table) {
            // Удаляем уникальный индекс при откате
            $table->dropUnique('contract_debts_hash_unique');
        });
    }
};
