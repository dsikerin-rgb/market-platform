<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('market_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('visibility', 32)->default('personal');
            $table->string('category', 64)->default('general');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('file_path', 2048);
            $table->string('original_name')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('file_size')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['market_id', 'visibility']);
            $table->index(['owner_user_id', 'archived_at']);
            $table->index(['uploaded_by_user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_documents');
    }
};
