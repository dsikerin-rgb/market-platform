<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_space_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('market_id')->constrained()->cascadeOnDelete();
            $table->string('name_ru');
            $table->string('code');
            $table->string('unit')->default('sqm');
            $table->decimal('price', 12, 2)->nullable();
            $table->string('currency')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['market_id', 'code']);
            $table->index(['market_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_space_types');
    }
};
