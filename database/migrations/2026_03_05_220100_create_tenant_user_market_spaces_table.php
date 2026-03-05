<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tenant_user_market_spaces')) {
            return;
        }

        Schema::create('tenant_user_market_spaces', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('market_space_id')->constrained('market_spaces')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'market_space_id'], 'tenant_user_market_spaces_user_space_unique');
            $table->index(['market_space_id', 'user_id'], 'tenant_user_market_spaces_space_user_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_user_market_spaces');
    }
};

