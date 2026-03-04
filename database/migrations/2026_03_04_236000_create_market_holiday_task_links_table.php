<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_holiday_task_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('market_id')->constrained()->cascadeOnDelete();
            $table->foreignId('market_holiday_id')->constrained('market_holidays')->cascadeOnDelete();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->string('scenario_key', 64);
            $table->timestamps();

            $table->unique(['market_holiday_id', 'scenario_key'], 'mhtl_holiday_scenario_unique');
            $table->unique(['task_id'], 'mhtl_task_unique');
            $table->index(['market_id', 'scenario_key'], 'mhtl_market_scenario_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_holiday_task_links');
    }
};

