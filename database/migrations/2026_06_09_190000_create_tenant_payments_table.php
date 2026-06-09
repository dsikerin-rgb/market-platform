<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_payments', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('market_id')->constrained('markets');
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->foreignId('tenant_contract_id')->nullable()->constrained('tenant_contracts');

            $table->string('tenant_external_id', 255);
            $table->string('contract_external_id', 255)->nullable();
            $table->string('payment_external_id', 255)->nullable();
            $table->string('document_number', 255)->nullable();
            $table->date('payment_date');
            $table->date('period');

            $table->string('organization_external_id', 255)->nullable();
            $table->string('organization_name', 255)->nullable();
            $table->string('account', 64)->nullable();
            $table->string('debit_account', 64)->nullable();

            $table->decimal('amount', 14, 2);
            $table->string('currency', 3)->default('RUB');
            $table->text('purpose')->nullable();
            $table->string('source', 32)->default('1c');
            $table->string('source_file', 255)->default('1c:payments');
            $table->json('payload')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->char('source_row_hash', 64);
            $table->timestamps();

            $table->index(['market_id', 'period']);
            $table->index(['market_id', 'tenant_id', 'payment_date']);
            $table->index(['market_id', 'contract_external_id']);
            $table->index(['market_id', 'payment_external_id']);
            $table->unique(['market_id', 'source_row_hash'], 'tenant_payments_market_hash_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_payments');
    }
};
