<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tenant_contracts')) {
            return;
        }

        Schema::table('tenant_contracts', function (Blueprint $table): void {
            if (! Schema::hasColumn('tenant_contracts', 'space_mapping_mode')) {
                $table->string('space_mapping_mode', 20)->default('auto');
            }

            if (! Schema::hasColumn('tenant_contracts', 'space_mapping_updated_at')) {
                $table->timestamp('space_mapping_updated_at')->nullable();
            }

            if (! Schema::hasColumn('tenant_contracts', 'space_mapping_updated_by_user_id')) {
                $table->foreignId('space_mapping_updated_by_user_id')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('tenant_contracts')) {
            return;
        }

        Schema::table('tenant_contracts', function (Blueprint $table): void {
            if (Schema::hasColumn('tenant_contracts', 'space_mapping_updated_by_user_id')) {
                $table->dropConstrainedForeignId('space_mapping_updated_by_user_id');
            }

            if (Schema::hasColumn('tenant_contracts', 'space_mapping_updated_at')) {
                $table->dropColumn('space_mapping_updated_at');
            }

            if (Schema::hasColumn('tenant_contracts', 'space_mapping_mode')) {
                $table->dropColumn('space_mapping_mode');
            }
        });
    }
};
