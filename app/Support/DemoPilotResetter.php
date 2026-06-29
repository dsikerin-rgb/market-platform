<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Market;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Throwable;

class DemoPilotResetter
{
    /**
     * @var array<string, array{table:string, required_columns:list<string>}>
     */
    private const SECTION_TABLES = [
        'market' => [
            'table' => 'markets',
            'required_columns' => ['slug', 'settings'],
        ],
        'users' => [
            'table' => 'users',
            'required_columns' => ['email', 'market_id', 'tenant_id'],
        ],
        'locations' => [
            'table' => 'market_locations',
            'required_columns' => ['market_id', 'code'],
        ],
        'spaces' => [
            'table' => 'market_spaces',
            'required_columns' => ['market_id', 'code'],
        ],
        'map_shapes' => [
            'table' => 'market_space_map_shapes',
            'required_columns' => ['market_id', 'market_space_id', 'page', 'version', 'meta'],
        ],
        'tenants' => [
            'table' => 'tenants',
            'required_columns' => ['market_id', 'external_id'],
        ],
        'contracts' => [
            'table' => 'tenant_contracts',
            'required_columns' => ['market_id', 'external_id'],
        ],
        'accruals' => [
            'table' => 'tenant_accruals',
            'required_columns' => ['market_id', 'source', 'source_row_hash'],
        ],
        'payments' => [
            'table' => 'tenant_payments',
            'required_columns' => ['market_id', 'source', 'source_row_hash'],
        ],
        'marketplace_categories' => [
            'table' => 'marketplace_categories',
            'required_columns' => ['market_id', 'slug'],
        ],
        'marketplace_products' => [
            'table' => 'marketplace_products',
            'required_columns' => ['market_id', 'slug', 'is_demo'],
        ],
        'announcements' => [
            'table' => 'marketplace_announcements',
            'required_columns' => ['market_id', 'slug'],
        ],
    ];

    /**
     * @param array<string, mixed> $dataSet
     * @return array{status:string, writes_enabled:bool, market_id:int|null, sections:list<array{section:string, table:string, records:int, status:string, details:string}>, issues:list<string>}
     */
    public function plan(array $dataSet): array
    {
        $issues = [];
        $schema = $this->schemaStatuses();
        $market = null;
        $integrationGuard = app(DemoPilotExternalIntegrationGuard::class)->check($dataSet);

        if ($integrationGuard['status'] !== 'ready') {
            $issues = array_merge($issues, $integrationGuard['issues']);
        }

        try {
            $market = $this->demoMarket($dataSet);
        } catch (Throwable $exception) {
            $issues[] = 'market lookup failed: ' . $this->exceptionMessage($exception);
        }

        $marketId = $market instanceof Market ? (int) $market->getKey() : null;

        if ($market instanceof Market && ! $this->isSyntheticMarket($market, $this->source($dataSet))) {
            $issues[] = 'existing market [' . $this->marketSlug($dataSet) . '] is not marked as demo/pilot synthetic data';
            $marketId = null;
        }

        $sections = [];

        foreach (self::SECTION_TABLES as $section => $definition) {
            [$status, $details] = $schema[$section];
            $records = $marketId === null || $status !== 'ready'
                ? 0
                : $this->targetCount($dataSet, $section, $marketId);

            if ($status !== 'ready') {
                $issues[] = $section . ': ' . $details;
            }

            $sections[] = [
                'section' => $section,
                'table' => $definition['table'],
                'records' => $records,
                'status' => $status,
                'details' => $details,
            ];
        }

        $sections[] = [
            'section' => 'integrations',
            'table' => $integrationGuard['table'],
            'records' => $integrationGuard['records'],
            'status' => $integrationGuard['status'],
            'details' => $integrationGuard['details'],
        ];

        if ($market === null && $issues === []) {
            $sections[0]['details'] = 'demo market [' . $this->marketSlug($dataSet) . '] does not exist';
        }

        return [
            'status' => $issues === [] ? 'ready' : 'blocked',
            'writes_enabled' => false,
            'market_id' => $marketId,
            'sections' => $sections,
            'issues' => array_values(array_unique($issues)),
        ];
    }

    /**
     * @param array<string, mixed> $dataSet
     * @return array{status:string, writes_enabled:bool, market_id:int|null, sections:list<array{section:string, table:string, records:int, status:string, details:string}>, issues:list<string>}
     */
    public function execute(array $dataSet): array
    {
        $report = $this->plan($dataSet);

        if ($report['issues'] !== []) {
            $report['status'] = 'blocked';
            $report['writes_enabled'] = false;

            return $report;
        }

        try {
            app(DemoPilotSettings::class)->assertDataWriteAllowed(DemoPilotSettings::OPERATION_RESET);
        } catch (LogicException $exception) {
            $report['status'] = 'blocked';
            $report['writes_enabled'] = false;
            $report['issues'][] = $exception->getMessage();

            return $report;
        }

        if ($report['market_id'] === null) {
            $report['status'] = 'unchanged';
            $report['writes_enabled'] = true;

            return $report;
        }

        $marketId = $report['market_id'];
        $deleted = DB::transaction(function () use ($dataSet, $marketId): array {
            $counts = [];
            $userIds = $this->targetUserIds($dataSet, $marketId);

            $counts['announcements'] = $this->deleteBySlugs('marketplace_announcements', $marketId, $this->slugs($dataSet, 'announcements'));
            $counts['marketplace_products'] = $this->deleteDemoProducts($dataSet, $marketId);
            $counts['marketplace_categories'] = $this->deleteBySlugs('marketplace_categories', $marketId, $this->slugs($dataSet, 'marketplace_categories'));
            $counts['payments'] = $this->deleteFinanceRows('tenant_payments', $marketId, $this->sourceHashes($dataSet, 'payments'));
            $counts['accruals'] = $this->deleteFinanceRows('tenant_accruals', $marketId, $this->sourceHashes($dataSet, 'accruals'));
            $counts['contracts'] = $this->deleteByExternalIds('tenant_contracts', $marketId, $this->externalIds($dataSet, 'contracts'));
            $counts['map_shapes'] = $this->deleteMapShapes($dataSet, $marketId);
            $counts['spaces'] = $this->deleteByCodes('market_spaces', $marketId, $this->codes($dataSet, 'spaces'));
            $this->deleteUserPermissionPivots($userIds);
            $counts['users'] = $this->deleteUsers($userIds);
            $counts['tenants'] = $this->deleteByExternalIds('tenants', $marketId, $this->externalIds($dataSet, 'tenants'));
            $counts['locations'] = $this->deleteByCodes('market_locations', $marketId, $this->codes($dataSet, 'locations'));
            $counts['market'] = 0;

            return $counts;
        });

        $sections = [];

        foreach ($report['sections'] as $section) {
            $name = $section['section'];
            $section['records'] = $deleted[$name] ?? 0;
            $section['status'] = ($deleted[$name] ?? 0) > 0 ? 'deleted' : 'unchanged';
            $section['details'] = 'deleted [' . ($deleted[$name] ?? 0) . '] records';

            if ($name === 'integrations') {
                $section['status'] = 'unchanged';
                $section['details'] = 'external integrations disabled; no outbound adapters called';
            } elseif ($name === 'market') {
                $section['status'] = 'retained';
                $section['details'] = 'demo market shell is retained by this safe reset package';
            }

            $sections[] = $section;
        }

        $report['status'] = 'reset';
        $report['writes_enabled'] = true;
        $report['sections'] = $sections;
        $report['issues'] = [];

        return $report;
    }

    /**
     * @param list<string> $issues
     * @return array<string, array{0:string, 1:string}>
     */
    private function schemaStatuses(): array
    {
        $statuses = [];

        foreach (self::SECTION_TABLES as $section => $definition) {
            $statuses[$section] = $this->schemaStatus($definition['table'], $definition['required_columns']);
        }

        return $statuses;
    }

    /**
     * @param list<string> $requiredColumns
     * @return array{0:string, 1:string}
     */
    private function schemaStatus(string $table, array $requiredColumns): array
    {
        try {
            if (! Schema::hasTable($table)) {
                return ['blocked', 'missing table [' . $table . ']'];
            }

            $missingColumns = [];

            foreach ($requiredColumns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    $missingColumns[] = $column;
                }
            }

            if ($missingColumns !== []) {
                return ['blocked', 'missing columns [' . implode(', ', $missingColumns) . ']'];
            }

            return ['ready', 'all required columns exist'];
        } catch (Throwable $exception) {
            return ['blocked', 'schema check failed for [' . $table . ']: ' . $this->exceptionMessage($exception)];
        }
    }

    private function exceptionMessage(Throwable $exception): string
    {
        $message = $exception->getPrevious()?->getMessage() ?: $exception->getMessage();

        return preg_replace('/\s+/', ' ', $message) ?? $message;
    }

    /**
     * @param array<string, mixed> $dataSet
     */
    private function demoMarket(array $dataSet): ?Market
    {
        return Market::query()
            ->where('slug', $this->marketSlug($dataSet))
            ->first();
    }

    private function isSyntheticMarket(Market $market, string $source): bool
    {
        return data_get($market->settings, 'demo_pilot.synthetic_source') === $source;
    }

    /**
     * @param array<string, mixed> $dataSet
     */
    private function marketSlug(array $dataSet): string
    {
        return trim((string) data_get($dataSet, 'metadata.market_slug', 'demo-market')) ?: 'demo-market';
    }

    /**
     * @param array<string, mixed> $dataSet
     */
    private function source(array $dataSet): string
    {
        return trim((string) data_get($dataSet, 'metadata.synthetic_source', 'demo_pilot')) ?: 'demo_pilot';
    }

    /**
     * @param array<string, mixed> $dataSet
     */
    private function targetCount(array $dataSet, string $section, int $marketId): int
    {
        return match ($section) {
            'market' => 1,
            'users' => $this->targetUsersQuery($dataSet, $marketId)->count(),
            'locations' => $this->countByCodes('market_locations', $marketId, $this->codes($dataSet, 'locations')),
            'spaces' => $this->countByCodes('market_spaces', $marketId, $this->codes($dataSet, 'spaces')),
            'tenants' => $this->countByExternalIds('tenants', $marketId, $this->externalIds($dataSet, 'tenants')),
            'contracts' => $this->countByExternalIds('tenant_contracts', $marketId, $this->externalIds($dataSet, 'contracts')),
            'accruals' => $this->financeRowsQuery('tenant_accruals', $marketId, $this->sourceHashes($dataSet, 'accruals'))->count(),
            'payments' => $this->financeRowsQuery('tenant_payments', $marketId, $this->sourceHashes($dataSet, 'payments'))->count(),
            'map_shapes' => $this->targetMapShapesQuery($dataSet, $marketId)->count(),
            'marketplace_categories' => $this->countBySlugs('marketplace_categories', $marketId, $this->slugs($dataSet, 'marketplace_categories')),
            'marketplace_products' => $this->demoProductsQuery($dataSet, $marketId)->count(),
            'announcements' => $this->countBySlugs('marketplace_announcements', $marketId, $this->slugs($dataSet, 'announcements')),
            default => 0,
        };
    }

    /**
     * @param array<string, mixed> $dataSet
     * @return list<string>
     */
    private function emails(array $dataSet): array
    {
        return $this->stringValues($dataSet, 'users', 'email');
    }

    /**
     * @param array<string, mixed> $dataSet
     * @return list<string>
     */
    private function codes(array $dataSet, string $section): array
    {
        return $this->stringValues($dataSet, $section, 'code');
    }

    /**
     * @param array<string, mixed> $dataSet
     * @return list<string>
     */
    private function slugs(array $dataSet, string $section): array
    {
        return $this->stringValues($dataSet, $section, 'slug');
    }

    /**
     * @param array<string, mixed> $dataSet
     * @return list<string>
     */
    private function externalIds(array $dataSet, string $section): array
    {
        return $this->stringValues($dataSet, $section, 'external_id');
    }

    /**
     * @param array<string, mixed> $dataSet
     * @return list<string>
     */
    private function sourceHashes(array $dataSet, string $section): array
    {
        $hashes = [];

        foreach ($this->recordsForSection($dataSet, $section) as $record) {
            $key = trim((string) ($record['key'] ?? ''));

            if ($key !== '') {
                $hashes[] = hash('sha256', 'demo_pilot:' . $section . ':' . $key);
            }
        }

        return array_values(array_unique($hashes));
    }

    /**
     * @param array<string, mixed> $dataSet
     * @return list<string>
     */
    private function stringValues(array $dataSet, string $section, string $field): array
    {
        $values = [];

        foreach ($this->recordsForSection($dataSet, $section) as $record) {
            $value = trim((string) ($record[$field] ?? ''));

            if ($value !== '') {
                $values[] = $value;
            }
        }

        return array_values(array_unique($values));
    }

    /**
     * @param array<string, mixed> $dataSet
     * @return list<array<string, mixed>>
     */
    private function recordsForSection(array $dataSet, string $section): array
    {
        $records = $dataSet[$section] ?? [];

        if (! is_array($records) || ! array_is_list($records)) {
            return [];
        }

        return array_values(array_filter(
            $records,
            static fn (mixed $record): bool => is_array($record),
        ));
    }

    /**
     * @param list<string> $codes
     */
    private function countByCodes(string $table, int $marketId, array $codes): int
    {
        return $this->whereInOrNone(DB::table($table)->where('market_id', $marketId), 'code', $codes)->count();
    }

    /**
     * @param list<string> $codes
     */
    private function deleteByCodes(string $table, int $marketId, array $codes): int
    {
        return $this->whereInOrNone(DB::table($table)->where('market_id', $marketId), 'code', $codes)->delete();
    }

    /**
     * @param array<string, mixed> $dataSet
     */
    private function deleteMapShapes(array $dataSet, int $marketId): int
    {
        return $this->targetMapShapesQuery($dataSet, $marketId)->delete();
    }

    /**
     * @param array<string, mixed> $dataSet
     */
    private function targetMapShapesQuery(array $dataSet, int $marketId): Builder
    {
        $query = DB::table('market_space_map_shapes')
            ->where('market_id', $marketId)
            ->where('meta->demo_pilot->synthetic_source', $this->source($dataSet));

        $query = $this->whereInOrNone($query, 'market_space_id', $this->targetSpaceIds($dataSet, $marketId));

        return $this->whereInOrNone($query, 'meta->demo_pilot->key', $this->mapShapeKeys($dataSet));
    }

    /**
     * @param array<string, mixed> $dataSet
     * @return list<int>
     */
    private function targetSpaceIds(array $dataSet, int $marketId): array
    {
        return $this->whereInOrNone(
            DB::table('market_spaces')->where('market_id', $marketId),
            'code',
            $this->codes($dataSet, 'spaces'),
        )
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->values()
            ->all();
    }

    /**
     * @param array<string, mixed> $dataSet
     * @return list<string>
     */
    private function mapShapeKeys(array $dataSet): array
    {
        return $this->stringValues($dataSet, 'map_shapes', 'key');
    }

    /**
     * @param list<string> $externalIds
     */
    private function countByExternalIds(string $table, int $marketId, array $externalIds): int
    {
        return $this->whereInOrNone(DB::table($table)->where('market_id', $marketId), 'external_id', $externalIds)->count();
    }

    /**
     * @param list<string> $externalIds
     */
    private function deleteByExternalIds(string $table, int $marketId, array $externalIds): int
    {
        return $this->whereInOrNone(DB::table($table)->where('market_id', $marketId), 'external_id', $externalIds)->delete();
    }

    /**
     * @param list<string> $slugs
     */
    private function countBySlugs(string $table, int $marketId, array $slugs): int
    {
        return $this->whereInOrNone(DB::table($table)->where('market_id', $marketId), 'slug', $slugs)->count();
    }

    /**
     * @param list<string> $slugs
     */
    private function deleteBySlugs(string $table, int $marketId, array $slugs): int
    {
        return $this->whereInOrNone(DB::table($table)->where('market_id', $marketId), 'slug', $slugs)->delete();
    }

    /**
     * @param list<string> $sourceHashes
     */
    private function deleteFinanceRows(string $table, int $marketId, array $sourceHashes): int
    {
        return $this->financeRowsQuery($table, $marketId, $sourceHashes)->delete();
    }

    /**
     * @param list<string> $sourceHashes
     */
    private function financeRowsQuery(string $table, int $marketId, array $sourceHashes): Builder
    {
        return $this->whereInOrNone(
            DB::table($table)
                ->where('market_id', $marketId)
                ->where('source', 'demo_pilot'),
            'source_row_hash',
            $sourceHashes,
        );
    }

    /**
     * @param array<string, mixed> $dataSet
     */
    private function deleteDemoProducts(array $dataSet, int $marketId): int
    {
        return $this->demoProductsQuery($dataSet, $marketId)->delete();
    }

    /**
     * @param array<string, mixed> $dataSet
     */
    private function demoProductsQuery(array $dataSet, int $marketId): Builder
    {
        return $this->whereInOrNone(
            DB::table('marketplace_products')
                ->where('market_id', $marketId)
                ->where('is_demo', true),
            'slug',
            $this->slugs($dataSet, 'marketplace_products'),
        );
    }

    /**
     * @param array<string, mixed> $dataSet
     */
    private function targetUsersQuery(array $dataSet, int $marketId): Builder
    {
        return $this->whereInOrNone(
            DB::table('users')->where('market_id', $marketId),
            'email',
            $this->emails($dataSet),
        );
    }

    /**
     * @param array<string, mixed> $dataSet
     * @return list<int>
     */
    private function targetUserIds(array $dataSet, int $marketId): array
    {
        return $this->targetUsersQuery($dataSet, $marketId)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->values()
            ->all();
    }

    /**
     * @param list<int> $userIds
     */
    private function deleteUsers(array $userIds): int
    {
        if ($userIds === []) {
            return 0;
        }

        return DB::table('users')->whereIn('id', $userIds)->delete();
    }

    /**
     * @param list<int> $userIds
     */
    private function deleteUserPermissionPivots(array $userIds): void
    {
        if ($userIds === []) {
            return;
        }

        foreach (['model_has_roles', 'model_has_permissions'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            DB::table($table)
                ->where('model_type', User::class)
                ->whereIn('model_id', $userIds)
                ->delete();
        }
    }

    /**
     * @param list<mixed> $values
     */
    private function whereInOrNone(Builder $query, string $column, array $values): Builder
    {
        if ($values === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn($column, $values);
    }
}
