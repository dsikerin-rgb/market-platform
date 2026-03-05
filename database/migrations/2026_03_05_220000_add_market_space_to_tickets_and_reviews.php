<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tickets') && ! Schema::hasColumn('tickets', 'market_space_id')) {
            Schema::table('tickets', function (Blueprint $table): void {
                $table->foreignId('market_space_id')
                    ->nullable()
                    ->after('tenant_id')
                    ->constrained('market_spaces')
                    ->nullOnDelete();

                $table->index(['tenant_id', 'market_space_id', 'created_at'], 'tickets_tenant_space_created_idx');
            });
        }

        if (Schema::hasTable('tenant_reviews') && ! Schema::hasColumn('tenant_reviews', 'market_space_id')) {
            Schema::table('tenant_reviews', function (Blueprint $table): void {
                $table->foreignId('market_space_id')
                    ->nullable()
                    ->after('tenant_id')
                    ->constrained('market_spaces')
                    ->nullOnDelete();

                $table->index(['tenant_id', 'market_space_id', 'status', 'created_at'], 'tenant_reviews_tenant_space_status_created_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('tickets') && Schema::hasColumn('tickets', 'market_space_id')) {
            Schema::table('tickets', function (Blueprint $table): void {
                $table->dropIndex('tickets_tenant_space_created_idx');
                $table->dropConstrainedForeignId('market_space_id');
            });
        }

        if (Schema::hasTable('tenant_reviews') && Schema::hasColumn('tenant_reviews', 'market_space_id')) {
            Schema::table('tenant_reviews', function (Blueprint $table): void {
                $table->dropIndex('tenant_reviews_tenant_space_status_created_idx');
                $table->dropConstrainedForeignId('market_space_id');
            });
        }
    }
};

