<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tenant_space_showcases')) {
            return;
        }

        Schema::create('tenant_space_showcases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('market_id')->nullable()->constrained('markets')->nullOnDelete();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('market_space_id')->constrained('market_spaces')->cascadeOnDelete();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->text('assortment')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('telegram')->nullable();
            $table->string('website')->nullable();
            $table->json('photos')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'market_space_id'], 'tenant_space_showcases_tenant_space_unique');
            $table->index(['tenant_id', 'is_active'], 'tenant_space_showcases_tenant_active_idx');
            $table->index(['market_id', 'created_at'], 'tenant_space_showcases_market_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_space_showcases');
    }
};

