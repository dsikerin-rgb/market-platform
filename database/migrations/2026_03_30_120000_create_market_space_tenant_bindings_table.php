<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_space_tenant_bindings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('market_id')->constrained('markets')->cascadeOnDelete();
            $table->foreignId('market_space_id')->constrained('market_spaces')->cascadeOnDelete();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->foreignId('tenant_contract_id')->nullable()->constrained('tenant_contracts')->nullOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->string('binding_type', 32);
            $table->string('confidence', 16)->default('medium');
            $table->string('source', 64);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('resolution_reason', 128)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['market_space_id', 'ended_at'], 'mstb_space_active_idx');
            $table->index(['tenant_contract_id', 'ended_at'], 'mstb_contract_active_idx');
            $table->index(['tenant_id', 'ended_at'], 'mstb_tenant_active_idx');
            $table->index(['market_id', 'binding_type', 'ended_at'], 'mstb_market_type_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_space_tenant_bindings');
    }
};
