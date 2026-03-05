<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('market_holidays', function (Blueprint $table): void {
            if (! Schema::hasColumn('market_holidays', 'cover_image')) {
                $table->string('cover_image', 2048)->nullable()->after('source');
            }
        });
    }

    public function down(): void
    {
        Schema::table('market_holidays', function (Blueprint $table): void {
            if (Schema::hasColumn('market_holidays', 'cover_image')) {
                $table->dropColumn('cover_image');
            }
        });
    }
};
