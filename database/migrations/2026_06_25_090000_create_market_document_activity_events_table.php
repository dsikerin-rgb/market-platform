<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_document_activity_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('market_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('market_document_id')->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('target_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('folder_id')->nullable();
            $table->string('action', 64);
            $table->string('visibility', 32)->nullable();
            $table->string('document_name')->nullable();
            $table->string('file_path')->nullable();
            $table->string('ip_address', 64)->nullable();
            $table->string('user_agent')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['market_id', 'created_at'], 'market_doc_activity_market_created_idx');
            $table->index(['market_document_id', 'created_at'], 'market_doc_activity_document_created_idx');
            $table->index(['actor_user_id', 'created_at'], 'market_doc_activity_actor_created_idx');
            $table->index(['action', 'created_at'], 'market_doc_activity_action_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_document_activity_events');
    }
};
