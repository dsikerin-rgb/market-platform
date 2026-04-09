<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('market_holidays', function (Blueprint $table): void {
            if (! Schema::hasColumn('market_holidays', 'public_payload')) {
                $table->json('public_payload')
                    ->nullable()
                    ->after('audience_payload');
            }
        });
    }

    public function down(): void
    {
        Schema::table('market_holidays', function (Blueprint $table): void {
            if (Schema::hasColumn('market_holidays', 'public_payload')) {
                $table->dropColumn('public_payload');
            }
        });
    }
};
