<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('market_holidays', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('market_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->date('starts_at');
            $table->date('ends_at')->nullable();
            $table->boolean('all_day')->default(true);
            $table->text('description')->nullable();
            $table->unsignedInteger('notify_before_days')->nullable();
            $table->dateTime('notify_at')->nullable();
            $table->dateTime('notified_at')->nullable();
            $table->string('source')->nullable();
            $table->timestamps();

            $table->index(['market_id', 'starts_at']);
            $table->unique(['market_id', 'title', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_holidays');
    }
};
