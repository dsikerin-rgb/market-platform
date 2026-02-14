<?php
# database/migrations/2026_02_06_000000_create_market_integrations_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('market_integrations', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('market_id');
            $table->string('type'); // e.g. '1c'
            $table->string('name');
            $table->string('auth_token')->unique();

            $table->string('status')->default('active'); // active | disabled
            $table->timestamp('last_sync_at')->nullable();

            $table->timestamps();

            $table->index('market_id');
            $table->index('type');

            $table
                ->foreign('market_id')
                ->references('id')
                ->on('markets')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('market_integrations');
    }
};
