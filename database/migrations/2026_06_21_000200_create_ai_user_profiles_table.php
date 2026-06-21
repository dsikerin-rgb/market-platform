<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_user_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('market_id')->nullable()->constrained()->nullOnDelete();
            $table->string('job_title')->nullable();
            $table->string('department')->nullable();
            $table->date('birth_date')->nullable();
            $table->text('responsibility_scope')->nullable();
            $table->json('regular_tasks')->nullable();
            $table->json('rejected_topics')->nullable();
            $table->json('preferred_contact_channels')->nullable();
            $table->string('communication_status', 40)->default('available');
            $table->timestamp('communication_paused_until')->nullable();
            $table->string('onboarding_status', 40)->default('new');
            $table->timestamp('onboarding_completed_at')->nullable();
            $table->json('facts')->nullable();
            $table->text('profile_summary')->nullable();
            $table->timestamp('inferred_from_messages_at')->nullable();
            $table->timestamps();

            $table->unique('user_id');
            $table->index(['market_id', 'updated_at'], 'ai_user_profiles_market_updated_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_user_profiles');
    }
};
