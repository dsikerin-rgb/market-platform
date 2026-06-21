<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_knowledge_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('market_id')->nullable()->constrained()->nullOnDelete();
            $table->string('dictionary', 80);
            $table->string('key');
            $table->string('label');
            $table->json('value')->nullable();
            $table->unsignedTinyInteger('confidence')->default(70);
            $table->foreignId('source_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['market_id', 'dictionary', 'key'], 'ai_knowledge_entries_scope_unique');
            $table->index(['market_id', 'dictionary', 'updated_at'], 'ai_knowledge_entries_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_knowledge_entries');
    }
};
