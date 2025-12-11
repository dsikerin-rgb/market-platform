<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_contracts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('market_id')->constrained('markets')->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('market_space_id')->nullable()->constrained('market_spaces')->nullOnDelete();
            $table->string('number', 50);
            $table->string('status', 20);
            $table->date('starts_at');
            $table->date('ends_at')->nullable();
            $table->date('signed_at')->nullable();
            $table->decimal('monthly_rent', 12, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_contracts');
    }
};
