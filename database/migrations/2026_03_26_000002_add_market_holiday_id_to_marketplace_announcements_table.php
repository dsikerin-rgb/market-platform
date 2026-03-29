<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_announcements', function (Blueprint $table): void {
            if (! Schema::hasColumn('marketplace_announcements', 'market_holiday_id')) {
                $table->foreignId('market_holiday_id')
                    ->nullable()
                    ->after('market_id')
                    ->constrained('market_holidays')
                    ->nullOnDelete();

                $table->unique('market_holiday_id', 'marketplace_announcements_market_holiday_unique');
            }
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_announcements', function (Blueprint $table): void {
            if (Schema::hasColumn('marketplace_announcements', 'market_holiday_id')) {
                $table->dropUnique('marketplace_announcements_market_holiday_unique');
                $table->dropConstrainedForeignId('market_holiday_id');
            }
        });
    }
};
