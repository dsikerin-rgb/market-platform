<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_agent_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('market_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ai_conversation_id')->nullable()->constrained('ai_conversations')->nullOnDelete();
            $table->foreignId('ai_message_id')->nullable()->constrained('ai_messages')->nullOnDelete();
            $table->string('event_type', 64);
            $table->string('tool', 96)->nullable();
            $table->string('status', 32);
            $table->string('title')->nullable();
            $table->json('summary')->nullable();
            $table->json('request_payload')->nullable();
            $table->json('result_payload')->nullable();
            $table->text('result_message')->nullable();
            $table->json('chips')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('error_type', 64)->nullable();
            $table->timestamps();

            $table->index(['market_id', 'status', 'created_at'], 'ai_agent_audit_market_status_created_idx');
            $table->index(['user_id', 'created_at'], 'ai_agent_audit_user_created_idx');
            $table->index(['tool', 'status', 'created_at'], 'ai_agent_audit_tool_status_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_agent_audit_events');
    }
};
