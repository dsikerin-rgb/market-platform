<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('market_spaces', function (Blueprint $table) {
            if (! Schema::hasColumn('market_spaces', 'market_id')) {
                $table->foreignId('market_id')
                    ->nullable()
                    ->constrained('markets')
                    ->cascadeOnDelete()
                    ->after('id');
                $table->index('market_id');
            }
        });

        // Попробуем заполнить market_id из выбранной локации (если она есть)
        if (Schema::hasColumn('market_spaces', 'location_id')) {
            DB::table('market_spaces')
                ->whereNull('market_id')
                ->update([
                    'market_id' => DB::raw('(select market_id from market_locations where market_locations.id = market_spaces.location_id)'),
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('market_spaces', function (Blueprint $table) {
            if (Schema::hasColumn('market_spaces', 'market_id')) {
                $table->dropConstrainedForeignId('market_id');
            }
        });
    }
};
