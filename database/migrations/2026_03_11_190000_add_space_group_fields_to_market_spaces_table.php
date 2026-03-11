<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('market_spaces')) {
            return;
        }

        Schema::table('market_spaces', function (Blueprint $table): void {
            if (! Schema::hasColumn('market_spaces', 'space_group_token')) {
                $table->string('space_group_token', 32)->nullable()->after('code');
            }

            if (! Schema::hasColumn('market_spaces', 'space_group_slot')) {
                $table->string('space_group_slot', 32)->nullable()->after('space_group_token');
            }
        });

        Schema::table('market_spaces', function (Blueprint $table): void {
            $table->index(['market_id', 'space_group_token'], 'market_spaces_market_group_idx');
            $table->index(['market_id', 'space_group_token', 'space_group_slot'], 'market_spaces_market_group_slot_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('market_spaces')) {
            return;
        }

        Schema::table('market_spaces', function (Blueprint $table): void {
            $table->dropIndex('market_spaces_market_group_slot_idx');
            $table->dropIndex('market_spaces_market_group_idx');
        });

        Schema::table('market_spaces', function (Blueprint $table): void {
            if (Schema::hasColumn('market_spaces', 'space_group_slot')) {
                $table->dropColumn('space_group_slot');
            }

            if (Schema::hasColumn('market_spaces', 'space_group_token')) {
                $table->dropColumn('space_group_token');
            }
        });
    }
};
