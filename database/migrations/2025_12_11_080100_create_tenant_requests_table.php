<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('market_id')->constrained('markets')->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('market_space_id')->nullable()->constrained('market_spaces')->nullOnDelete();
            $table->foreignId('tenant_contract_id')->nullable()->constrained('tenant_contracts')->nullOnDelete();
            $table->string('subject', 255);
            $table->text('description');
            $table->string('category', 50);
            $table->string('priority', 20);
            $table->string('status', 20);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('resolved_at')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->text('internal_notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_requests');
    }
};
