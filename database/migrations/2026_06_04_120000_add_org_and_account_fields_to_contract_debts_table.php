<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contract_debts', function (Blueprint $table): void {
            if (! Schema::hasColumn('contract_debts', 'organization_external_id')) {
                $table->string('organization_external_id', 255)
                    ->nullable()
                    ->after('period');
            }

            if (! Schema::hasColumn('contract_debts', 'organization_name')) {
                $table->string('organization_name', 255)
                    ->nullable()
                    ->after('organization_external_id');
            }

            if (! Schema::hasColumn('contract_debts', 'account')) {
                $table->string('account', 64)
                    ->nullable()
                    ->after('organization_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('contract_debts', function (Blueprint $table): void {
            $drop = [];

            foreach (['organization_external_id', 'organization_name', 'account'] as $column) {
                if (Schema::hasColumn('contract_debts', $column)) {
                    $drop[] = $column;
                }
            }

            if ($drop !== []) {
                $table->dropColumn($drop);
            }
        });
    }
};
