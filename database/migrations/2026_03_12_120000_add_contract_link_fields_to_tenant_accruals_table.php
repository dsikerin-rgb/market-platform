<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_accruals', function (Blueprint $table): void {
            if (! Schema::hasColumn('tenant_accruals', 'contract_external_id')) {
                $table->string('contract_external_id', 255)
                    ->nullable()
                    ->after('tenant_id');
            }

            if (! Schema::hasColumn('tenant_accruals', 'contract_link_status')) {
                $table->string('contract_link_status', 32)
                    ->nullable()
                    ->after('tenant_contract_id');
            }

            if (! Schema::hasColumn('tenant_accruals', 'contract_link_source')) {
                $table->string('contract_link_source', 64)
                    ->nullable()
                    ->after('contract_link_status');
            }

            if (! Schema::hasColumn('tenant_accruals', 'contract_link_note')) {
                $table->string('contract_link_note', 255)
                    ->nullable()
                    ->after('contract_link_source');
            }
        });

        Schema::table('tenant_accruals', function (Blueprint $table): void {
            $table->index(['market_id', 'contract_link_status'], 'tenant_accruals_market_contract_link_status_idx');
            $table->index(['market_id', 'contract_external_id'], 'tenant_accruals_market_contract_external_id_idx');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_accruals', function (Blueprint $table): void {
            $table->dropIndex('tenant_accruals_market_contract_link_status_idx');
            $table->dropIndex('tenant_accruals_market_contract_external_id_idx');

            $table->dropColumn([
                'contract_external_id',
                'contract_link_status',
                'contract_link_source',
                'contract_link_note',
            ]);
        });
    }
};
