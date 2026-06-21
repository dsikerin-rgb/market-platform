<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_knowledge_entries', function (Blueprint $table): void {
            $table->string('status', 24)->default('draft');
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();

            $table->index(['market_id', 'status', 'updated_at'], 'ai_knowledge_entries_status_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::table('ai_knowledge_entries', function (Blueprint $table): void {
            $table->dropIndex('ai_knowledge_entries_status_lookup_idx');
            $table->dropConstrainedForeignId('reviewed_by_user_id');
            $table->dropColumn(['status', 'reviewed_at', 'review_note']);
        });
    }
};
