<?php
# database/migrations/2026_06_08_120000_create_market_space_group_episodes_table.php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('market_space_group_episodes')) {
            return;
        }

        Schema::create('market_space_group_episodes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('market_id')->constrained('markets')->cascadeOnDelete();
            $table->foreignId('parent_market_space_id')->constrained('market_spaces')->cascadeOnDelete();
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->string('source', 64)->default('manual');
            $table->foreignId('source_contract_id')->nullable()->constrained('tenant_contracts')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['market_id', 'parent_market_space_id'], 'msg_episodes_market_parent_idx');
            $table->index(['market_id', 'valid_from', 'valid_to'], 'msg_episodes_market_period_idx');
            $table->index(['source_contract_id'], 'msg_episodes_source_contract_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_space_group_episodes');
    }
};
