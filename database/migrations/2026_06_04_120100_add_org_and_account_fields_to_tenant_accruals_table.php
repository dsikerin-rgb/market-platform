<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_accruals', function (Blueprint $table): void {
            if (! Schema::hasColumn('tenant_accruals', 'organization_external_id')) {
                $table->string('organization_external_id', 255)
                    ->nullable()
                    ->after('contract_external_id');
            }

            if (! Schema::hasColumn('tenant_accruals', 'organization_name')) {
                $table->string('organization_name', 255)
                    ->nullable()
                    ->after('organization_external_id');
            }

            if (! Schema::hasColumn('tenant_accruals', 'account')) {
                $table->string('account', 64)
                    ->nullable()
                    ->after('organization_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tenant_accruals', function (Blueprint $table): void {
            $drop = [];

            foreach (['organization_external_id', 'organization_name', 'account'] as $column) {
                if (Schema::hasColumn('tenant_accruals', $column)) {
                    $drop[] = $column;
                }
            }

            if ($drop !== []) {
                $table->dropColumn($drop);
            }
        });
    }
};
