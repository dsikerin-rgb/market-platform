<?php
# database/migrations/2025_12_20_000000_add_task_mvp_fields_to_tasks_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->timestamp('completed_at')->nullable()->after('due_at');
            $table->foreignId('created_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete()
                ->after('created_by');

            $table->index('market_id');
            $table->index('status');
            $table->index('assignee_id');
            $table->index('due_at');
            $table->index(['source_type', 'source_id']);
        });

        DB::table('tasks')
            ->whereNull('created_by_user_id')
            ->update([
                'created_by_user_id' => DB::raw('created_by'),
            ]);

        DB::table('tasks')
            ->where('status', 'done')
            ->update(['status' => 'completed']);

        DB::table('tasks')
            ->where('status', 'canceled')
            ->update(['status' => 'cancelled']);
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex(['market_id']);
            $table->dropIndex(['status']);
            $table->dropIndex(['assignee_id']);
            $table->dropIndex(['due_at']);
            $table->dropIndex(['source_type', 'source_id']);

            $table->dropConstrainedForeignId('created_by_user_id');
            $table->dropColumn('completed_at');
        });
    }
};
