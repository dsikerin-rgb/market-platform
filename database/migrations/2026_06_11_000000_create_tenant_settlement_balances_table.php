<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_settlement_balances', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('market_id')->constrained('markets');
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->foreignId('tenant_contract_id')->nullable()->constrained('tenant_contracts');

            $table->date('period_from');
            $table->date('period_to');

            $table->string('tenant_external_id', 255);
            $table->string('tenant_name', 255)->nullable();
            $table->string('inn', 32)->nullable();
            $table->string('kpp', 32)->nullable();

            $table->string('contract_external_id', 255)->nullable();
            $table->string('contract_name', 255)->nullable();

            $table->string('settlement_document_external_id', 255)->nullable();
            $table->text('settlement_document_name')->nullable();

            $table->string('organization_external_id', 255)->nullable();
            $table->string('organization_name', 255)->nullable();
            $table->string('account', 64);
            $table->string('currency', 3)->default('RUB');

            $table->decimal('opening_debit', 14, 2)->default(0);
            $table->decimal('opening_credit', 14, 2)->default(0);
            $table->decimal('turnover_debit', 14, 2)->default(0);
            $table->decimal('turnover_credit', 14, 2)->default(0);
            $table->decimal('closing_debit', 14, 2)->default(0);
            $table->decimal('closing_credit', 14, 2)->default(0);

            $table->string('source', 32)->default('1c');
            $table->string('source_file', 255)->default('1c:settlements');
            $table->json('payload')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->char('source_row_hash', 64);
            $table->timestamps();

            $table->index(['market_id', 'account', 'period_from', 'period_to'], 'tenant_settlement_balances_period_idx');
            $table->index(['market_id', 'tenant_id', 'period_to'], 'tenant_settlement_balances_tenant_idx');
            $table->index(['market_id', 'contract_external_id'], 'tenant_settlement_balances_contract_idx');
            $table->index(['market_id', 'settlement_document_external_id'], 'tenant_settlement_balances_doc_idx');
            $table->unique(['market_id', 'account', 'period_from', 'period_to', 'source_row_hash'], 'tenant_settlement_balances_period_hash_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_settlement_balances');
    }
};
