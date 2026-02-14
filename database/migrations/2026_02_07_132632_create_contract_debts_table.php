<?php
# database/migrations/2026_02_07_XXXXXX_create_contract_debts_table.php

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
        Schema::create('contract_debts', function (Blueprint $table) {
            $table->id();

            // Контекст рынка
            $table->unsignedBigInteger('market_id');

            // Наши сущности
            $table->unsignedBigInteger('tenant_id');

            // Идентификаторы из 1С
            $table->string('tenant_external_id');
            $table->string('contract_external_id');

            // Период и суммы
            $table->string('period', 7); // YYYY-MM
            $table->decimal('accrued_amount', 14, 2);
            $table->decimal('paid_amount', 14, 2);
            $table->decimal('debt_amount', 14, 2);

            // Когда 1С посчитала
            $table->timestamp('calculated_at');

            // Источник данных
            $table->string('source')->default('1c');

            // Когда мы приняли snapshot
            $table->timestamp('created_at')->useCurrent();

            // Индексы под основные запросы
            $table->index(['market_id', 'tenant_id']);
            $table->index(['contract_external_id']);
            $table->index(['calculated_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contract_debts');
    }
};
