<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_conversations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('market_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title')->nullable();
            $table->text('context_page_url')->nullable();
            $table->string('context_page_label')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'market_id', 'updated_at'], 'ai_conversations_user_market_updated_idx');
        });

        Schema::create('ai_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ai_conversation_id')
                ->constrained('ai_conversations')
                ->cascadeOnDelete();
            $table->string('role', 32);
            $table->text('body');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['ai_conversation_id', 'created_at'], 'ai_messages_conversation_created_idx');
            $table->index('role');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_messages');
        Schema::dropIfExists('ai_conversations');
    }
};
