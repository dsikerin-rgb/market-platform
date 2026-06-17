<?php
# database/migrations/2026_06_17_190120_add_category_to_market_space_types_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('market_space_types', 'category')) {
            Schema::table('market_space_types', function (Blueprint $table) {
                $table->string('category')->default('commercial')->index();
            });
        }

        if (! Schema::hasColumn('market_space_types', 'category')) {
            return;
        }

        $patterns = [
            '%сануз%',
            '%санитар%',
            '%туалет%',
            '%мооп%',
            '%моп%',
            '%mop%',
            '%общ%польз%',
            '%курил%',
            '%курен%',
            '%common_area%',
            '%common area%',
            '%commonarea%',
        ];

        DB::table('market_space_types')
            ->where(function ($query): void {
                $query->whereNull('category')
                    ->orWhere('category', '');
            })
            ->update(['category' => 'commercial']);

        DB::table('market_space_types')
            ->where('category', 'commercial')
            ->where(function ($query) use ($patterns): void {
                $query->where(function ($query) use ($patterns): void {
                    foreach ($patterns as $pattern) {
                        $query->orWhereRaw('LOWER(name_ru) LIKE ?', [strtolower($pattern)]);
                        $query->orWhereRaw('LOWER(code) LIKE ?', [strtolower($pattern)]);
                    }
                });
            })
            ->update(['category' => 'common_area']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('market_space_types', 'category')) {
            Schema::table('market_space_types', function (Blueprint $table) {
                $table->dropColumn('category');
            });
        }
    }
};
