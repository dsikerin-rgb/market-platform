<?php
# database/migrations/2026_06_08_120100_create_market_space_group_episode_children_table.php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('market_space_group_episode_children')) {
            return;
        }

        Schema::create('market_space_group_episode_children', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('market_space_group_episode_id')
                ->constrained('market_space_group_episodes')
                ->cascadeOnDelete();
            $table->foreignId('child_market_space_id')->constrained('market_spaces')->cascadeOnDelete();
            $table->string('slot', 64)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->decimal('area_sqm', 10, 2)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(
                ['market_space_group_episode_id', 'child_market_space_id'],
                'msg_episode_children_episode_child_unique'
            );
            $table->index(['child_market_space_id'], 'msg_episode_children_child_idx');
            $table->index(['market_space_group_episode_id', 'sort_order'], 'msg_episode_children_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_space_group_episode_children');
    }
};
