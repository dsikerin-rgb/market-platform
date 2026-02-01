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
        Schema::create('operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('market_id')->constrained('markets')->cascadeOnDelete();
            $table->string('entity_type', 32)->index();
            $table->unsignedBigInteger('entity_id')->nullable()->index();
            $table->string('type', 64)->index();
            $table->dateTime('effective_at')->index();
            $table->string('effective_tz', 64)->nullable();
            $table->date('effective_month')->index();
            $table->string('status', 16)->default('applied');
            $table->json('payload');
            $table->text('comment')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancels_operation_id')->nullable()->constrained('operations')->nullOnDelete();
            $table->timestamps();

            $table->index(['market_id', 'effective_month']);
            $table->index(['entity_type', 'entity_id', 'effective_at']);
            $table->index(['market_id', 'type', 'effective_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('operations');
    }
};
