<?php

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
            if (! Schema::hasColumn('market_spaces', 'map_review_status')) {
                $table->string('map_review_status', 32)->nullable()->after('status');
                $table->index(['market_id', 'map_review_status'], 'market_spaces_market_review_status_idx');
            }

            if (! Schema::hasColumn('market_spaces', 'map_reviewed_at')) {
                $table->timestamp('map_reviewed_at')->nullable()->after('map_review_status');
            }

            if (! Schema::hasColumn('market_spaces', 'map_reviewed_by')) {
                $table->foreignId('map_reviewed_by')->nullable()->after('map_reviewed_at')
                    ->constrained('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('market_spaces')) {
            return;
        }

        Schema::table('market_spaces', function (Blueprint $table): void {
            if (Schema::hasColumn('market_spaces', 'map_reviewed_by')) {
                $table->dropConstrainedForeignId('map_reviewed_by');
            }

            if (Schema::hasColumn('market_spaces', 'map_reviewed_at')) {
                $table->dropColumn('map_reviewed_at');
            }

            if (Schema::hasColumn('market_spaces', 'map_review_status')) {
                $table->dropIndex('market_spaces_market_review_status_idx');
                $table->dropColumn('map_review_status');
            }
        });
    }
};
