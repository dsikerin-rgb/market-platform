<?php

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
        Schema::create('market_space_tenant_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('market_space_id')->constrained('market_spaces')->cascadeOnDelete();
            $table->foreignId('old_tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->foreignId('new_tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->dateTime('changed_at')->useCurrent();
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('market_space_tenant_histories');
    }
};
