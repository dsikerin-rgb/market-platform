<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contract_debts', function (Blueprint $table) {

            if (!Schema::hasColumn('contract_debts', 'market_id')) {
                $table->unsignedBigInteger('market_id');
            }

            if (!Schema::hasColumn('contract_debts', 'contract_external_id')) {
                $table->string('contract_external_id');
            }

            if (!Schema::hasColumn('contract_debts', 'tenant_external_id')) {
                $table->string('tenant_external_id');
            }

            if (!Schema::hasColumn('contract_debts', 'debt_amount')) {
                $table->decimal('debt_amount', 15, 2);
            }

            if (!Schema::hasColumn('contract_debts', 'currency')) {
                $table->string('currency', 3)->nullable();
            }

            if (!Schema::hasColumn('contract_debts', 'calculated_at')) {
                $table->timestamp('calculated_at');
            }

            if (!Schema::hasColumn('contract_debts', 'raw_payload')) {
                $table->json('raw_payload')->nullable();
            }
        });

        if (!Schema::hasTable('contract_debts') ||
            !Schema::hasColumn('contract_debts', 'market_id')) {
            return;
        }

        Schema::table('contract_debts', function (Blueprint $table) {
            $table->unique(
                ['market_id', 'contract_external_id', 'calculated_at'],
                'contract_debts_market_contract_calculated_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('contract_debts', function (Blueprint $table) {

            if (Schema::hasColumn('contract_debts', 'raw_payload')) {
                $table->dropColumn('raw_payload');
            }

            if (Schema::hasColumn('contract_debts', 'calculated_at')) {
                $table->dropColumn('calculated_at');
            }

            if (Schema::hasColumn('contract_debts', 'currency')) {
                $table->dropColumn('currency');
            }

            if (Schema::hasColumn('contract_debts', 'debt_amount')) {
                $table->dropColumn('debt_amount');
            }

            if (Schema::hasColumn('contract_debts', 'tenant_external_id')) {
                $table->dropColumn('tenant_external_id');
            }

            if (Schema::hasColumn('contract_debts', 'contract_external_id')) {
                $table->dropColumn('contract_external_id');
            }

            if (Schema::hasColumn('contract_debts', 'market_id')) {
                $table->dropColumn('market_id');
            }

            try {
                $table->dropUnique('contract_debts_market_contract_calculated_unique');
            } catch (\Throwable $e) {
                // ignore if not exists
            }
        });
    }
};
