<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Market;
use App\Models\MarketLocation;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
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
    public function execute(array $dataSet): array
    {
        $report = $this->preflight($dataSet);

        if ($report['issues'] !== []) {
            $report['status'] = 'blocked';
            $report['writes_enabled'] = false;

            return $report;
        }

        try {
            app(DemoPilotSettings::class)->assertDataWriteAllowed(DemoPilotSettings::OPERATION_PROVISION);
        } catch (LogicException $exception) {
            $report['status'] = 'blocked';
            $report['writes_enabled'] = false;
            $report['issues'][] = $exception->getMessage();

            return $report;
        }

        $marketWrite = $this->writeMarket($dataSet);
        $locationWrite = $marketWrite['status'] === 'blocked'
            ? ['status' => 'skipped', 'details' => 'market write was blocked']
            : $this->writeLocations($dataSet, (int) $marketWrite['market_id']);
        $tenantWrite = $marketWrite['status'] === 'blocked' || $locationWrite['status'] === 'blocked'
            ? ['status' => 'skipped', 'details' => 'market or location write was blocked']
            : $this->writeTenants($dataSet, (int) $marketWrite['market_id']);
        $sections = [];
        $issues = [];

        foreach ($report['sections'] as $section) {
            if ($section['section'] === 'market') {
                $section['status'] = $marketWrite['status'];
                $section['details'] = $marketWrite['details'];
            } elseif ($section['section'] === 'locations') {
                $section['status'] = $locationWrite['status'];
                $section['details'] = $locationWrite['details'];
            } elseif ($section['section'] === 'tenants') {
                $section['status'] = $tenantWrite['status'];
                $section['details'] = $tenantWrite['details'];
            } else {
                $section['status'] = 'skipped';
                $section['details'] = 'write adapter is not implemented in this package';
            }

            $sections[] = $section;
        }

        if ($marketWrite['status'] === 'blocked') {
            $issues[] = $marketWrite['details'];
        }

        if ($locationWrite['status'] === 'blocked') {
            $issues[] = $locationWrite['details'];
        }

        if ($tenantWrite['status'] === 'blocked') {
            $issues[] = $tenantWrite['details'];
        }

        $report['status'] = $issues === [] ? 'partial' : 'blocked';
        $report['writes_enabled'] = $issues === [];
        $report['sections'] = $sections;
        $report['issues'] = $issues;

        return $report;
    }

    /**
     * @param array<string, mixed> $dataSet
     * @return array{status:string, details:string, market_id?:int}
     */
    private function writeMarket(array $dataSet): array
    {
        $marketPayload = $dataSet['market'] ?? null;

        if (! is_array($marketPayload)) {
            return ['status' => 'blocked', 'details' => 'market payload is missing'];
        }

        $attributes = $this->marketAttributes($marketPayload);
        $slug = $attributes['slug'];
        $code = $attributes['code'];
        $source = (string) data_get($marketPayload, 'settings.demo_pilot.synthetic_source', 'demo_pilot');

        if ($slug === '' || $code === '') {
            return ['status' => 'blocked', 'details' => 'market slug and code are required'];
        }

        return DB::transaction(function () use ($attributes, $slug, $code, $source): array {
            $market = Market::query()->where('slug', $slug)->first();
            $codeConflict = Market::query()
                ->where('code', $code)
                ->when($market !== null, static fn ($query) => $query->whereKeyNot($market->getKey()))
                ->first();

            if ($codeConflict !== null) {
                return [
                    'status' => 'blocked',
                    'details' => 'market code [' . $code . '] already belongs to another market',
                ];
            }

            if ($market !== null && ! $this->isSyntheticMarket($market, $source)) {
                return [
                    'status' => 'blocked',
                    'details' => 'existing market [' . $slug . '] is not marked as demo/pilot synthetic data',
                ];
            }

            if ($market === null) {
                $market = Market::query()->create($attributes);

                return [
                    'status' => 'created',
                    'details' => 'created market id [' . $market->getKey() . '] for slug [' . $slug . ']',
                    'market_id' => (int) $market->getKey(),
                ];
            }

            $market->fill($attributes);

            if (! $market->isDirty()) {
                return [
                    'status' => 'unchanged',
                    'details' => 'market id [' . $market->getKey() . '] already matches demo payload',
                    'market_id' => (int) $market->getKey(),
                ];
            }

            $dirty = array_keys($market->getDirty());
            $market->save();

            return [
                'status' => 'updated',
                'details' => 'updated market id [' . $market->getKey() . '] fields [' . implode(', ', $dirty) . ']',
                'market_id' => (int) $market->getKey(),
            ];
        });
    }

    /**
     * @param array<string, mixed> $dataSet
     * @return array{status:string, details:string}
     */
    private function writeLocations(array $dataSet, int $marketId): array
    {
        $records = $this->recordsForSection($dataSet, 'locations');

        if ($records === []) {
            return ['status' => 'blocked', 'details' => 'locations payload is empty'];
        }

        return DB::transaction(function () use ($records, $marketId): array {
            $created = 0;
            $updated = 0;
            $unchanged = 0;

            foreach ($records as $record) {
                $attributes = $this->locationAttributes($record, $marketId);
                $name = $attributes['name'];
                $code = $attributes['code'];

                if ($name === '' || $code === '') {
                    return ['status' => 'blocked', 'details' => 'location name and code are required'];
                }

                $location = MarketLocation::query()
                    ->where('market_id', $marketId)
                    ->where('code', $code)
                    ->first();

                if ($location === null) {
                    MarketLocation::query()->create($attributes);
                    $created++;

                    continue;
                }

                $location->fill($attributes);

                if (! $location->isDirty()) {
                    $unchanged++;

                    continue;
                }

                $location->save();
                $updated++;
            }

            $status = $updated > 0 ? 'updated' : ($created > 0 ? 'created' : 'unchanged');

            return [
                'status' => $status,
                'details' => 'created [' . $created . '], updated [' . $updated . '], unchanged [' . $unchanged . '] locations for market id [' . $marketId . ']',
            ];
        });
    }

    /**
     * @param array<string, mixed> $marketPayload
     * @return array{name:string, slug:string, code:string, address:string, timezone:string, is_active:bool, settings:array<string, mixed>, features:array<string, mixed>}
     */
    private function marketAttributes(array $marketPayload): array
    {
        return [
            'name' => trim((string) ($marketPayload['name'] ?? 'Demo Market')) ?: 'Demo Market',
            'slug' => trim((string) ($marketPayload['slug'] ?? '')),
            'code' => trim((string) ($marketPayload['code'] ?? '')),
            'address' => trim((string) ($marketPayload['address'] ?? '')),
            'timezone' => trim((string) ($marketPayload['timezone'] ?? 'Asia/Novosibirsk')) ?: 'Asia/Novosibirsk',
            'is_active' => (bool) ($marketPayload['is_active'] ?? true),
            'settings' => is_array($marketPayload['settings'] ?? null) ? $marketPayload['settings'] : [],
            'features' => is_array($marketPayload['features'] ?? null) ? $marketPayload['features'] : [],
        ];
    }

    /**
     * @param array<string, mixed> $locationPayload
     * @return array{market_id:int, name:string, code:string, type:string, parent_id:null, sort_order:int, is_active:bool}
     */
    private function locationAttributes(array $locationPayload, int $marketId): array
    {
        return [
            'market_id' => $marketId,
            'name' => trim((string) ($locationPayload['name'] ?? '')),
            'code' => trim((string) ($locationPayload['code'] ?? '')),
            'type' => trim((string) ($locationPayload['type'] ?? '')),
            'parent_id' => null,
            'sort_order' => (int) ($locationPayload['sort_order'] ?? 0),
            'is_active' => (bool) ($locationPayload['is_active'] ?? true),
        ];
    }

    /**
     * @param array<string, mixed> $dataSet
     * @return array{status:string, details:string}
     */
    private function writeTenants(array $dataSet, int $marketId): array
    {
        $records = $this->recordsForSection($dataSet, 'tenants');

        if ($records === []) {
            return ['status' => 'blocked', 'details' => 'tenants payload is empty'];
        }

        return DB::transaction(function () use ($records, $marketId): array {
            $planned = [];
            $created = 0;
            $updated = 0;
            $unchanged = 0;

            foreach ($records as $record) {
                $attributes = $this->tenantAttributes($record, $marketId);
                $externalId = $attributes['external_id'];
                $slug = $attributes['slug'];

                if ($attributes['name'] === '' || $externalId === '' || $slug === '') {
                    return ['status' => 'blocked', 'details' => 'tenant name, slug, and external_id are required'];
                }

                $tenant = Tenant::query()
                    ->where('market_id', $marketId)
                    ->where('external_id', $externalId)
                    ->first();

                $slugConflict = Tenant::query()
                    ->where('slug', $slug)
                    ->when($tenant !== null, static fn ($query) => $query->whereKeyNot($tenant->getKey()))
                    ->first();

                if ($slugConflict !== null) {
                    return [
                        'status' => 'blocked',
                        'details' => 'tenant slug [' . $slug . '] already belongs to another tenant',
                    ];
                }

                $planned[] = [$attributes, $tenant];
            }

            foreach ($planned as [$attributes, $tenant]) {
                if ($tenant === null) {
                    Tenant::withoutEvents(static function () use ($attributes): void {
                        Tenant::query()->create($attributes);
                    });

                    $created++;

                    continue;
                }

                $tenant->fill($attributes);

                if (! $tenant->isDirty()) {
                    $unchanged++;

                    continue;
                }

                Tenant::withoutEvents(static function () use ($tenant): void {
                    $tenant->save();
                });

                $updated++;
            }

            $status = $updated > 0 ? 'updated' : ($created > 0 ? 'created' : 'unchanged');

            return [
                'status' => $status,
                'details' => 'created [' . $created . '], updated [' . $updated . '], unchanged [' . $unchanged . '] tenants for market id [' . $marketId . ']',
            ];
        });
    }

    /**
     * @param array<string, mixed> $tenantPayload
     * @return array{market_id:int, name:string, short_name:string, slug:string, type:string, external_id:string, inn:null, phone:string, email:string, contact_person:string, status:string, is_active:bool, notes:string, one_c_data:array<string, mixed>, debt_status:string|null}
     */
    private function tenantAttributes(array $tenantPayload, int $marketId): array
    {
        $source = trim((string) ($tenantPayload['synthetic_source'] ?? 'demo_pilot')) ?: 'demo_pilot';
        $oneCData = is_array($tenantPayload['one_c_data'] ?? null) ? $tenantPayload['one_c_data'] : [];
        $oneCData['synthetic_source'] = $source;
        $oneCData['live_1c'] = false;

        return [
            'market_id' => $marketId,
            'name' => trim((string) ($tenantPayload['name'] ?? '')),
            'short_name' => trim((string) ($tenantPayload['short_name'] ?? '')),
            'slug' => trim((string) ($tenantPayload['slug'] ?? '')),
            'type' => trim((string) ($tenantPayload['type'] ?? 'llc')) ?: 'llc',
            'external_id' => trim((string) ($tenantPayload['external_id'] ?? '')),
            'inn' => null,
            'phone' => trim((string) ($tenantPayload['phone'] ?? '')),
            'email' => trim((string) ($tenantPayload['email'] ?? '')),
            'contact_person' => trim((string) ($tenantPayload['contact_person'] ?? '')),
            'status' => trim((string) ($tenantPayload['status'] ?? 'active')) ?: 'active',
            'is_active' => (bool) ($tenantPayload['is_active'] ?? true),
            'notes' => 'Synthetic demo tenant. Source: ' . $source,
            'one_c_data' => $oneCData,
            'debt_status' => in_array($tenantPayload['debt_status'] ?? null, ['green', 'orange', 'red'], true)
                ? (string) $tenantPayload['debt_status']
                : null,
        ];
    }

    private function isSyntheticMarket(Market $market, string $source): bool
    {
        return data_get($market->settings, 'demo_pilot.synthetic_source') === $source;
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
