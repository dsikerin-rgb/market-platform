<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('market_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('recipient_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('subject');
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->index(['market_id', 'last_message_at']);
            $table->index(['created_by_user_id', 'recipient_user_id']);
        });

        Schema::create('staff_conversation_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_conversation_id')->constrained('staff_conversations')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->timestamps();

            $table->index(['staff_conversation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_conversation_messages');
        Schema::dropIfExists('staff_conversations');
    }
};
