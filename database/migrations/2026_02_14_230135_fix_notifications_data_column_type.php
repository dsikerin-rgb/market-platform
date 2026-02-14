<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Явное преобразование типа с использованием USING clause
        DB::statement("ALTER TABLE notifications ALTER COLUMN data TYPE json USING data::json");
    }

    public function down(): void
    {
        // Возврат к типу text
        DB::statement("ALTER TABLE notifications ALTER COLUMN data TYPE text USING data::text");
    }
};