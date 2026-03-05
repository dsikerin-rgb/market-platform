<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('market_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->string('reviewer_name', 120)->nullable();
            $table->string('reviewer_contact', 190)->nullable();
            $table->text('review_text');
            $table->string('status', 20)->default('published');
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'created_at'], 'tenant_reviews_tenant_status_created_idx');
            $table->index(['market_id', 'created_at'], 'tenant_reviews_market_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_reviews');
    }
};
