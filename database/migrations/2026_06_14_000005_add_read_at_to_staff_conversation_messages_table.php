<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff_conversation_messages', function (Blueprint $table): void {
            if (! Schema::hasColumn('staff_conversation_messages', 'read_at')) {
                $table->timestamp('read_at')->nullable()->index();
            }
        });

        if (Schema::hasColumn('staff_conversation_messages', 'read_at')) {
            DB::table('staff_conversation_messages')
                ->whereNull('read_at')
                ->update(['read_at' => DB::raw('created_at')]);
        }
    }

    public function down(): void
    {
        Schema::table('staff_conversation_messages', function (Blueprint $table): void {
            if (Schema::hasColumn('staff_conversation_messages', 'read_at')) {
                $table->dropColumn('read_at');
            }
        });
    }
};
