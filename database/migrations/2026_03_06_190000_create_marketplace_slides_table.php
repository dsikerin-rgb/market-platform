<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_slides', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('market_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('badge')->nullable();
            $table->text('description')->nullable();
            $table->string('image_path')->nullable();
            $table->string('theme', 32)->default('info');
            $table->string('cta_label')->nullable();
            $table->string('cta_url')->nullable();
            $table->string('placement', 64)->default('home_info_carousel');
            $table->string('audience', 32)->default('all');
            $table->integer('sort_order')->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['market_id', 'placement', 'is_active', 'sort_order'], 'mp_slides_market_place_active_sort_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_slides');
    }
};
