<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_feed_comments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('staff_feed_post_id')->index();
            $table->foreignId('author_user_id')->index();
            $table->text('body');
            $table->timestamps();

            $table->index(['staff_feed_post_id', 'created_at']);
            $table->foreign('staff_feed_post_id', 'staff_feed_comments_post_id_foreign')
                ->references('id')
                ->on('staff_feed_posts')
                ->cascadeOnDelete();
            $table->foreign('author_user_id', 'staff_feed_comments_author_user_id_foreign')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_feed_comments');
    }
};
