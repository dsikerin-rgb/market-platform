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
        Schema::create('market_spaces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('market_id')->constrained('markets')->cascadeOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('market_locations')->nullOnDelete();
            $table->string('number')->nullable();
            $table->string('code')->nullable();
            $table->decimal('area_sqm', 10, 2)->nullable();
            $table->string('type')->nullable();
            $table->string('status')->default('free');
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('market_spaces');
    }
};
