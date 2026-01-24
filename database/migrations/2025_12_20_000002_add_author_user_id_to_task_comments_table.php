<?php
# database/migrations/2025_12_20_000002_add_author_user_id_to_task_comments_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_comments', function (Blueprint $table) {
            $table->foreignId('author_user_id')
                ->nullable()
                ->constrained('users')
                ->cascadeOnDelete()
                ->after('user_id');
        });

        DB::table('task_comments')
            ->whereNull('author_user_id')
            ->update([
                'author_user_id' => DB::raw('user_id'),
            ]);
    }

    public function down(): void
    {
        Schema::table('task_comments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('author_user_id');
        });
    }
};
