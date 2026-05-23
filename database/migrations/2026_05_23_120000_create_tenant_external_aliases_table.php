<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_external_aliases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('market_id')->constrained()->cascadeOnDelete();
            $table->foreignId('canonical_tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->unsignedBigInteger('source_tenant_id')->nullable();
            $table->string('alias_type', 32);
            $table->string('alias_value');
            $table->string('source', 64)->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->unique(['market_id', 'alias_type', 'alias_value'], 'tenant_external_aliases_unique_alias');
            $table->index(['canonical_tenant_id', 'alias_type'], 'tenant_external_aliases_canonical_type_idx');
            $table->index(['source_tenant_id'], 'tenant_external_aliases_source_tenant_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_external_aliases');
    }
};
