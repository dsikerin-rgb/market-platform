<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_document_folders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('market_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('market_document_folders')->nullOnDelete();
            $table->string('visibility', 32)->default('personal');
            $table->string('name');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['market_id', 'visibility']);
            $table->index(['owner_user_id', 'archived_at']);
            $table->index(['parent_id', 'sort_order']);
        });

        Schema::table('market_documents', function (Blueprint $table): void {
            $table->foreignId('folder_id')
                ->nullable()
                ->after('uploaded_by_user_id')
                ->constrained('market_document_folders')
                ->nullOnDelete();
            $table->nullableMorphs('related', 'market_documents_related_index');
        });
    }

    public function down(): void
    {
        Schema::table('market_documents', function (Blueprint $table): void {
            $table->dropMorphs('related', 'market_documents_related_index');
            $table->dropConstrainedForeignId('folder_id');
        });

        Schema::dropIfExists('market_document_folders');
    }
};
