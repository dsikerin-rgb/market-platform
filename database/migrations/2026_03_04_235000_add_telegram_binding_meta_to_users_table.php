<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'telegram_profile')) {
                $table->json('telegram_profile')->nullable()->after('telegram_chat_id');
            }

            if (! Schema::hasColumn('users', 'telegram_linked_at')) {
                $table->timestamp('telegram_linked_at')->nullable()->after('telegram_profile');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (Schema::hasColumn('users', 'telegram_linked_at')) {
                $table->dropColumn('telegram_linked_at');
            }

            if (Schema::hasColumn('users', 'telegram_profile')) {
                $table->dropColumn('telegram_profile');
            }
        });
    }
};

