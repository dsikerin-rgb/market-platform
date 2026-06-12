<?php

use App\Models\MarketSpace;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('market_spaces', function (Blueprint $table): void {
            if (! Schema::hasColumn('market_spaces', 'shared_use_financial_mode')) {
                $table->string('shared_use_financial_mode', 64)
                    ->default(MarketSpace::SHARED_USE_FINANCIAL_MODE_SEPARATE_CONTRACT)
                    ->after('status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('market_spaces', function (Blueprint $table): void {
            if (Schema::hasColumn('market_spaces', 'shared_use_financial_mode')) {
                $table->dropColumn('shared_use_financial_mode');
            }
        });
    }
};
