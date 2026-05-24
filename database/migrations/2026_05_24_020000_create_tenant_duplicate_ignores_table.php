<?php
# database/migrations/2026_05_24_020000_create_tenant_duplicate_ignores_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_duplicate_ignores', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('market_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_left_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('tenant_right_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('reason', 64)->default('different_tenants');
            $table->text('comment')->nullable();
            $table->foreignId('ignored_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['market_id', 'tenant_left_id', 'tenant_right_id'], 'tenant_duplicate_ignores_pair_unique');
            $table->index(['market_id', 'created_at'], 'tenant_duplicate_ignores_market_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_duplicate_ignores');
    }
};
