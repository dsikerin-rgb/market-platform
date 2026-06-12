<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_accruals', function (Blueprint $table): void {
            if (! Schema::hasColumn('tenant_accruals', 'document_external_id')) {
                $table->string('document_external_id', 255)
                    ->nullable()
                    ->after('account');
            }

            if (! Schema::hasColumn('tenant_accruals', 'document_number')) {
                $table->string('document_number', 255)
                    ->nullable()
                    ->after('document_external_id');
            }

            if (! Schema::hasColumn('tenant_accruals', 'document_date')) {
                $table->date('document_date')
                    ->nullable()
                    ->after('document_number');
            }

            if (! Schema::hasColumn('tenant_accruals', 'document_name')) {
                $table->text('document_name')
                    ->nullable()
                    ->after('document_date');
            }

            if (! Schema::hasColumn('tenant_accruals', 'service_name')) {
                $table->string('service_name', 255)
                    ->nullable()
                    ->after('document_name');
            }

            if (! Schema::hasColumn('tenant_accruals', 'line_description')) {
                $table->text('line_description')
                    ->nullable()
                    ->after('service_name');
            }

            if (! Schema::hasColumn('tenant_accruals', 'purpose')) {
                $table->text('purpose')
                    ->nullable()
                    ->after('line_description');
            }
        });

        Schema::table('tenant_accruals', function (Blueprint $table): void {
            if (Schema::hasColumn('tenant_accruals', 'document_external_id')) {
                $table->index(['market_id', 'document_external_id'], 'tenant_accruals_market_document_external_id_idx');
            }

            if (Schema::hasColumn('tenant_accruals', 'document_date')) {
                $table->index(['market_id', 'document_date'], 'tenant_accruals_market_document_date_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tenant_accruals', function (Blueprint $table): void {
            if (Schema::hasColumn('tenant_accruals', 'document_external_id')) {
                $table->dropIndex('tenant_accruals_market_document_external_id_idx');
            }

            if (Schema::hasColumn('tenant_accruals', 'document_date')) {
                $table->dropIndex('tenant_accruals_market_document_date_idx');
            }
        });

        Schema::table('tenant_accruals', function (Blueprint $table): void {
            $drop = [];

            foreach ([
                'document_external_id',
                'document_number',
                'document_date',
                'document_name',
                'service_name',
                'line_description',
                'purpose',
            ] as $column) {
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
