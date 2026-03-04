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
            if (! Schema::hasColumn('market_holidays', 'audience_scope')) {
                $table->string('audience_scope', 20)
                    ->default('staff')
                    ->after('source');
                $table->index('audience_scope');
            }

            if (! Schema::hasColumn('market_holidays', 'audience_payload')) {
                $table->json('audience_payload')
                    ->nullable()
                    ->after('audience_scope');
            }
        });
    }

    public function down(): void
    {
        Schema::table('market_holidays', function (Blueprint $table): void {
            if (Schema::hasColumn('market_holidays', 'audience_payload')) {
                $table->dropColumn('audience_payload');
            }

            if (Schema::hasColumn('market_holidays', 'audience_scope')) {
                $table->dropIndex(['audience_scope']);
                $table->dropColumn('audience_scope');
            }
        });
    }
};

