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
            // Добавляем колонку hash для идемпотентной вставки
            $table->string('hash', 64)->nullable()->after('raw_payload');
        });

        // Заполняем hash для существующих записей
        DB::table('contract_debts')->whereNull('hash')->update([
            'hash' => DB::raw("md5(id::text || '-' || created_at::text)")
        ]);

        // Делаем колонку NOT NULL после заполнения
        Schema::table('contract_debts', function (Blueprint $table) {
            $table->string('hash', 64)->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contract_debts', function (Blueprint $table) {
            $table->dropColumn('hash');
        });
    }
};