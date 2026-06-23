<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_document_shares', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('market_document_id')->constrained('market_documents')->cascadeOnDelete();
            $table->foreignId('shared_with_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('shared_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('access_level', 32)->default('view');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(['market_document_id', 'shared_with_user_id'], 'market_document_shares_unique_recipient');
            $table->index(['shared_with_user_id', 'revoked_at']);
            $table->index(['market_document_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_document_shares');
    }
};
