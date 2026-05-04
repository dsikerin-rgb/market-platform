<?php
# database/migrations/2026_05_01_000000_add_space_group_role_to_market_spaces_table.php

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
        if (!Schema::hasTable('market_spaces')) {
            return;
        }

        if (Schema::hasColumn('market_spaces', 'space_group_role')) {
            return;
        }

        Schema::table('market_spaces', function (Blueprint $table): void {
            $table->string('space_group_role', 16)
                ->after('space_group_slot')
                ->default('none');

            $table->index(['market_id', 'space_group_role'], 'market_spaces_market_group_role_idx');
        });

        // Backfill: token NULL → 'none'
        DB::table('market_spaces')
            ->whereNull('space_group_token')
            ->update(['space_group_role' => 'none']);

        // Backfill: token NOT NULL + slot NULL → 'parent'
        DB::table('market_spaces')
            ->whereNotNull('space_group_token')
            ->whereNull('space_group_slot')
            ->update(['space_group_role' => 'parent']);

        // Backfill: token NOT NULL + slot NOT NULL → 'child'
        DB::table('market_spaces')
            ->whereNotNull('space_group_token')
            ->whereNotNull('space_group_slot')
            ->update(['space_group_role' => 'child']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('market_spaces')) {
            return;
        }

        if (!Schema::hasColumn('market_spaces', 'space_group_role')) {
            return;
        }

        Schema::table('market_spaces', function (Blueprint $table): void {
            $table->dropIndex('market_spaces_market_group_role_idx');
            $table->dropColumn('space_group_role');
        });
    }
};