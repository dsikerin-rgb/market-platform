<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_exchanges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('market_id')->constrained()->cascadeOnDelete();
            $table->string('direction');
            $table->string('entity_type');
            $table->string('status')->default('pending');
            $table->string('file_path')->nullable();
            $table->json('payload')->nullable();
            $table->text('error')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['market_id', 'direction', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_exchanges');
    }
};
