<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff_conversation_messages', function (Blueprint $table): void {
            if (! Schema::hasColumn('staff_conversation_messages', 'attachments')) {
                $table->json('attachments')->nullable()->after('body');
            }
        });

        Schema::table('ticket_comments', function (Blueprint $table): void {
            if (! Schema::hasColumn('ticket_comments', 'attachments')) {
                $table->json('attachments')->nullable()->after('body');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ticket_comments', function (Blueprint $table): void {
            if (Schema::hasColumn('ticket_comments', 'attachments')) {
                $table->dropColumn('attachments');
            }
        });

        Schema::table('staff_conversation_messages', function (Blueprint $table): void {
            if (Schema::hasColumn('staff_conversation_messages', 'attachments')) {
                $table->dropColumn('attachments');
            }
        });
    }
};
