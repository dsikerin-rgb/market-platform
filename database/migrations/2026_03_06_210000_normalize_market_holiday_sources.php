<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // promo aliases -> promotion
        DB::statement("UPDATE market_holidays SET source = 'promotion' WHERE source IS NOT NULL AND lower(btrim(source)) IN ('promo', 'promotion')");

        // sanitary aliases -> sanitary_auto
        DB::statement("UPDATE market_holidays SET source = 'sanitary_auto' WHERE source IS NOT NULL AND lower(btrim(source)) LIKE '%sanitary%'");

        // holiday legacy values -> national_holiday
        DB::statement("UPDATE market_holidays SET source = 'national_holiday' WHERE source IS NOT NULL AND lower(btrim(source)) IN ('file', 'holiday', 'national-holiday', 'national_holiday')");
    }

    public function down(): void
    {
        // Irreversible normalization.
    }
};