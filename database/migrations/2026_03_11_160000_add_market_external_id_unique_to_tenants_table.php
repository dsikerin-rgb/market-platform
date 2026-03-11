<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX_NAME = 'tenants_market_external_id_unique';

    public function up(): void
    {
        if (! Schema::hasTable('tenants') || ! Schema::hasColumn('tenants', 'external_id')) {
            return;
        }

        if ($this->indexExists(self::INDEX_NAME)) {
            return;
        }

        $duplicates = DB::table('tenants')
            ->selectRaw('market_id, external_id, COUNT(*) as cnt')
            ->whereNotNull('external_id')
            ->where('external_id', '<>', '')
            ->groupBy('market_id', 'external_id')
            ->havingRaw('COUNT(*) > 1')
            ->limit(5)
            ->get();

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException('Cannot create unique tenant external_id index: duplicate rows exist.');
        }

        DB::statement(sprintf(
            'CREATE UNIQUE INDEX %s ON tenants (market_id, external_id) WHERE external_id IS NOT NULL AND external_id <> \'\'',
            self::INDEX_NAME
        ));
    }

    public function down(): void
    {
        if (! Schema::hasTable('tenants') || ! $this->indexExists(self::INDEX_NAME)) {
            return;
        }

        DB::statement(sprintf('DROP INDEX %s', self::INDEX_NAME));
    }

    private function indexExists(string $name): bool
    {
        $driver = DB::getDriverName();

        return match ($driver) {
            'pgsql' => DB::table('pg_indexes')
                ->where('schemaname', 'public')
                ->where('indexname', $name)
                ->exists(),
            'sqlite' => (bool) DB::selectOne(
                "SELECT 1 FROM sqlite_master WHERE type = 'index' AND name = ? LIMIT 1",
                [$name]
            ),
            default => false,
        };
    }
};
