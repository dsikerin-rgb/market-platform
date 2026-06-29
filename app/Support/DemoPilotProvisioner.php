<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Schema;
use Throwable;

class DemoPilotProvisioner
{
    /**
     * @var array<string, array{table:string, required_columns:list<string>}>
     */
    private const SECTION_TABLES = [
        'market' => [
            'table' => 'markets',
            'required_columns' => ['name', 'slug', 'code', 'address', 'timezone', 'is_active', 'settings', 'features'],
        ],
        'users' => [
            'table' => 'users',
            'required_columns' => ['name', 'email', 'password', 'market_id', 'tenant_id'],
        ],
        'locations' => [
            'table' => 'market_locations',
            'required_columns' => ['market_id', 'name', 'code', 'type', 'sort_order', 'is_active'],
        ],
        'spaces' => [
            'table' => 'market_spaces',
            'required_columns' => [
                'market_id',
                'location_id',
                'tenant_id',
                'number',
                'code',
                'display_name',
                'activity_type',
                'area_sqm',
                'rent_rate_value',
                'rent_rate_unit',
                'type',
                'status',
                'is_active',
            ],
        ],
        'tenants' => [
            'table' => 'tenants',
            'required_columns' => [
                'market_id',
                'name',
                'short_name',
                'slug',
                'type',
                'external_id',
                'phone',
                'email',
                'contact_person',
                'status',
                'is_active',
                'one_c_data',
                'debt_status',
            ],
        ],
        'contracts' => [
            'table' => 'tenant_contracts',
            'required_columns' => [
                'market_id',
                'tenant_id',
                'market_space_id',
                'external_id',
                'number',
                'status',
                'starts_at',
                'ends_at',
                'signed_at',
                'monthly_rent',
                'currency',
                'is_active',
                'space_mapping_mode',
            ],
        ],
        'accruals' => [
            'table' => 'tenant_accruals',
            'required_columns' => [
                'market_id',
                'tenant_id',
                'tenant_contract_id',
                'market_space_id',
                'period',
                'document_date',
                'rent_amount',
                'management_fee',
                'utilities_amount',
                'electricity_amount',
                'total_no_vat',
                'vat_rate',
                'total_with_vat',
                'cash_amount',
                'source',
                'source_row_hash',
                'payload',
                'imported_at',
            ],
        ],
        'payments' => [
            'table' => 'tenant_payments',
            'required_columns' => [
                'market_id',
                'tenant_id',
                'tenant_contract_id',
                'tenant_external_id',
                'contract_external_id',
                'payment_external_id',
                'payment_date',
                'period',
                'amount',
                'currency',
                'source',
                'source_file',
                'payload',
                'imported_at',
                'source_row_hash',
            ],
        ],
        'marketplace_categories' => [
            'table' => 'marketplace_categories',
            'required_columns' => ['market_id', 'parent_id', 'name', 'slug', 'sort_order', 'is_active'],
        ],
        'marketplace_products' => [
            'table' => 'marketplace_products',
            'required_columns' => [
                'market_id',
                'tenant_id',
                'market_space_id',
                'category_id',
                'title',
                'slug',
                'description',
                'price',
                'currency',
                'stock_qty',
                'sku',
                'unit',
                'images',
                'attributes',
                'is_active',
                'is_featured',
                'is_demo',
                'published_at',
            ],
        ],
        'announcements' => [
            'table' => 'marketplace_announcements',
            'required_columns' => [
                'market_id',
                'author_user_id',
                'kind',
                'title',
                'slug',
                'excerpt',
                'content',
                'starts_at',
                'ends_at',
                'is_active',
                'published_at',
            ],
        ],
    ];

    /**
     * @param array<string, mixed> $dataSet
     * @return array{status:string, writes_enabled:bool, sections:list<array{section:string, table:string, records:int, status:string, details:string}>, issues:list<string>}
     */
    public function preflight(array $dataSet): array
    {
        $issues = $this->referenceIssues($dataSet);
        $sections = [];

        foreach (self::SECTION_TABLES as $section => $definition) {
            [$status, $details] = $this->schemaStatus($definition['table'], $definition['required_columns']);

            if ($status !== 'ready') {
                $issues[] = $section . ': ' . $details;
            }

            $sections[] = [
                'section' => $section,
                'table' => $definition['table'],
                'records' => count($this->recordsForSection($dataSet, $section)),
                'status' => $status,
                'details' => $details,
            ];
        }

        return [
            'status' => $issues === [] ? 'ready' : 'blocked',
            'writes_enabled' => false,
            'sections' => $sections,
            'issues' => array_values(array_unique($issues)),
        ];
    }

    /**
     * @param array<string, mixed> $dataSet
     * @return array{status:string, writes_enabled:bool, sections:list<array{section:string, table:string, records:int, status:string, details:string}>, issues:list<string>}
     */
    public function executePreflight(array $dataSet): array
    {
        $report = $this->preflight($dataSet);
        $report['status'] = 'blocked';
        $report['writes_enabled'] = false;
        $report['issues'][] = 'Demo/pilot write phase is intentionally disabled in this package; no database rows were changed.';

        return $report;
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
     * @return list<string>
     */
    private function referenceIssues(array $dataSet): array
    {
        $issues = [];
        $tenantKeys = $this->keys($dataSet, 'tenants', $issues);
        $spaceKeys = $this->keys($dataSet, 'spaces', $issues);
        $contractKeys = $this->keys($dataSet, 'contracts', $issues);
        $categoryKeys = $this->keys($dataSet, 'marketplace_categories', $issues);

        $this->assertReferences($this->recordsForSection($dataSet, 'users'), 'tenant_key', $tenantKeys, 'users', $issues, true);
        $this->assertReferences($this->recordsForSection($dataSet, 'spaces'), 'tenant_key', $tenantKeys, 'spaces', $issues, true);
        $this->assertReferences($this->recordsForSection($dataSet, 'contracts'), 'tenant_key', $tenantKeys, 'contracts', $issues);
        $this->assertReferences($this->recordsForSection($dataSet, 'contracts'), 'market_space_key', $spaceKeys, 'contracts', $issues);
        $this->assertReferences($this->recordsForSection($dataSet, 'accruals'), 'tenant_key', $tenantKeys, 'accruals', $issues);
        $this->assertReferences($this->recordsForSection($dataSet, 'accruals'), 'tenant_contract_key', $contractKeys, 'accruals', $issues);
        $this->assertReferences($this->recordsForSection($dataSet, 'accruals'), 'market_space_key', $spaceKeys, 'accruals', $issues);
        $this->assertReferences($this->recordsForSection($dataSet, 'payments'), 'tenant_key', $tenantKeys, 'payments', $issues);
        $this->assertReferences($this->recordsForSection($dataSet, 'payments'), 'tenant_contract_key', $contractKeys, 'payments', $issues);
        $this->assertReferences($this->recordsForSection($dataSet, 'marketplace_products'), 'tenant_key', $tenantKeys, 'marketplace_products', $issues);
        $this->assertReferences($this->recordsForSection($dataSet, 'marketplace_products'), 'market_space_key', $spaceKeys, 'marketplace_products', $issues);
        $this->assertReferences($this->recordsForSection($dataSet, 'marketplace_products'), 'category_key', $categoryKeys, 'marketplace_products', $issues);

        return $issues;
    }

    /**
     * @param array<string, mixed> $dataSet
     * @param list<string> $issues
     * @return list<string>
     */
    private function keys(array $dataSet, string $section, array &$issues): array
    {
        $keys = [];

        foreach ($this->recordsForSection($dataSet, $section) as $index => $record) {
            $key = trim((string) ($record['key'] ?? ''));

            if ($key === '') {
                $issues[] = $section . '[' . $index . '] is missing key.';

                continue;
            }

            if (in_array($key, $keys, true)) {
                $issues[] = $section . ' has duplicate key [' . $key . '].';

                continue;
            }

            $keys[] = $key;
        }

        return $keys;
    }

    /**
     * @param list<array<string, mixed>> $records
     * @param list<string> $targetKeys
     * @param list<string> $issues
     */
    private function assertReferences(
        array $records,
        string $field,
        array $targetKeys,
        string $section,
        array &$issues,
        bool $nullable = false,
    ): void {
        foreach ($records as $record) {
            $value = $record[$field] ?? null;

            if ($value === null && $nullable) {
                continue;
            }

            $value = trim((string) $value);

            if ($value === '' && $nullable) {
                continue;
            }

            if ($value === '' || ! in_array($value, $targetKeys, true)) {
                $issues[] = $section . ' record [' . ($record['key'] ?? 'unknown') . '] has invalid ' . $field . ' [' . $value . '].';
            }
        }
    }

    /**
     * @param array<string, mixed> $dataSet
     * @return list<array<string, mixed>>
     */
    private function recordsForSection(array $dataSet, string $section): array
    {
        if ($section === 'market') {
            return isset($dataSet['market']) && is_array($dataSet['market'])
                ? [$dataSet['market']]
                : [];
        }

        $records = $dataSet[$section] ?? [];

        if (! is_array($records) || ! array_is_list($records)) {
            return [];
        }

        return array_values(array_filter(
            $records,
            static fn (mixed $record): bool => is_array($record),
        ));
    }
}
