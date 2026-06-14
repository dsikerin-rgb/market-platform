<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_feed_posts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('market_id')->nullable()->index();
            $table->foreignId('author_user_id')->index();
            $table->string('type', 32)->default('message')->index();
            $table->text('body');
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['market_id', 'created_at']);
            $table->foreign('market_id', 'staff_feed_posts_market_id_foreign')
                ->references('id')
                ->on('markets')
                ->nullOnDelete();
            $table->foreign('author_user_id', 'staff_feed_posts_author_user_id_foreign')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_feed_posts');
    }
};
