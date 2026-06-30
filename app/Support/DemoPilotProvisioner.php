<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Market;
use App\Models\MarketLocation;
use App\Models\MarketSpace;
use App\Models\MarketSpaceMapShape;
use App\Models\Tenant;
use App\Models\TenantAccrual;
use App\Models\TenantContract;
use App\Models\TenantPayment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use Spatie\Permission\Models\Role;
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
        'map_shapes' => [
            'table' => 'market_space_map_shapes',
            'required_columns' => [
                'market_id',
                'market_space_id',
                'page',
                'version',
                'polygon',
                'bbox_x1',
                'bbox_y1',
                'bbox_x2',
                'bbox_y2',
                'fill_color',
                'stroke_color',
                'fill_opacity',
                'sort_order',
                'is_active',
                'meta',
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
        $integrationGuard = app(DemoPilotExternalIntegrationGuard::class)->check($dataSet);
        $accessPasswordIssue = app(DemoPilotSettings::class)->accessPasswordIssue();

        if ($accessPasswordIssue !== null) {
            $issues[] = $accessPasswordIssue;
        }

        if ($integrationGuard['status'] !== 'ready') {
            $issues = array_merge($issues, $integrationGuard['issues']);
        }

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

        $sections[] = [
            'section' => 'integrations',
            'table' => $integrationGuard['table'],
            'records' => $integrationGuard['records'],
            'status' => $integrationGuard['status'],
            'details' => $integrationGuard['details'],
        ];

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
        $userWrite = $marketWrite['status'] === 'blocked'
            || $locationWrite['status'] === 'blocked'
            || $tenantWrite['status'] === 'blocked'
            ? ['status' => 'skipped', 'details' => 'market, location, or tenant write was blocked']
            : $this->writeUsers($dataSet, (int) $marketWrite['market_id']);
        $spaceWrite = $marketWrite['status'] === 'blocked'
            || $locationWrite['status'] === 'blocked'
            || $tenantWrite['status'] === 'blocked'
            || $userWrite['status'] === 'blocked'
            ? ['status' => 'skipped', 'details' => 'market, location, tenant, or user write was blocked']
            : $this->writeSpaces($dataSet, (int) $marketWrite['market_id']);
        $mapShapeWrite = $marketWrite['status'] === 'blocked'
            || $locationWrite['status'] === 'blocked'
            || $tenantWrite['status'] === 'blocked'
            || $userWrite['status'] === 'blocked'
            || $spaceWrite['status'] === 'blocked'
            ? ['status' => 'skipped', 'details' => 'market, location, tenant, user, or space write was blocked']
            : $this->writeMapShapes($dataSet, (int) $marketWrite['market_id']);
        $contractWrite = $marketWrite['status'] === 'blocked'
            || $locationWrite['status'] === 'blocked'
            || $tenantWrite['status'] === 'blocked'
            || $userWrite['status'] === 'blocked'
            || $spaceWrite['status'] === 'blocked'
            || $mapShapeWrite['status'] === 'blocked'
            ? ['status' => 'skipped', 'details' => 'market, location, tenant, user, space, or map shape write was blocked']
            : $this->writeContracts($dataSet, (int) $marketWrite['market_id']);
        $accrualWrite = $marketWrite['status'] === 'blocked'
            || $locationWrite['status'] === 'blocked'
            || $tenantWrite['status'] === 'blocked'
            || $userWrite['status'] === 'blocked'
            || $spaceWrite['status'] === 'blocked'
            || $mapShapeWrite['status'] === 'blocked'
            || $contractWrite['status'] === 'blocked'
            ? ['status' => 'skipped', 'details' => 'market, location, tenant, user, space, map shape, or contract write was blocked']
            : $this->writeAccruals($dataSet, (int) $marketWrite['market_id']);
        $paymentWrite = $marketWrite['status'] === 'blocked'
            || $locationWrite['status'] === 'blocked'
            || $tenantWrite['status'] === 'blocked'
            || $userWrite['status'] === 'blocked'
            || $spaceWrite['status'] === 'blocked'
            || $mapShapeWrite['status'] === 'blocked'
            || $contractWrite['status'] === 'blocked'
            || $accrualWrite['status'] === 'blocked'
            ? ['status' => 'skipped', 'details' => 'market, location, tenant, user, space, map shape, contract, or accrual write was blocked']
            : $this->writePayments($dataSet, (int) $marketWrite['market_id']);
        $sections = [];
        $issues = [];

        foreach ($report['sections'] as $section) {
            if ($section['section'] === 'market') {
                $section['status'] = $marketWrite['status'];
                $section['details'] = $marketWrite['details'];
            } elseif ($section['section'] === 'users') {
                $section['status'] = $userWrite['status'];
                $section['details'] = $userWrite['details'];
            } elseif ($section['section'] === 'locations') {
                $section['status'] = $locationWrite['status'];
                $section['details'] = $locationWrite['details'];
            } elseif ($section['section'] === 'tenants') {
                $section['status'] = $tenantWrite['status'];
                $section['details'] = $tenantWrite['details'];
            } elseif ($section['section'] === 'users') {
                $section['status'] = $userWrite['status'];
                $section['details'] = $userWrite['details'];
            } elseif ($section['section'] === 'spaces') {
                $section['status'] = $spaceWrite['status'];
                $section['details'] = $spaceWrite['details'];
            } elseif ($section['section'] === 'map_shapes') {
                $section['status'] = $mapShapeWrite['status'];
                $section['details'] = $mapShapeWrite['details'];
            } elseif ($section['section'] === 'contracts') {
                $section['status'] = $contractWrite['status'];
                $section['details'] = $contractWrite['details'];
            } elseif ($section['section'] === 'accruals') {
                $section['status'] = $accrualWrite['status'];
                $section['details'] = $accrualWrite['details'];
            } elseif ($section['section'] === 'payments') {
                $section['status'] = $paymentWrite['status'];
                $section['details'] = $paymentWrite['details'];
            } elseif ($section['section'] === 'integrations') {
                $section['status'] = 'unchanged';
                $section['details'] = 'external integrations disabled; no outbound adapters called';
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

        if ($userWrite['status'] === 'blocked') {
            $issues[] = $userWrite['details'];
        }

        if ($spaceWrite['status'] === 'blocked') {
            $issues[] = $spaceWrite['details'];
        }

        if ($mapShapeWrite['status'] === 'blocked') {
            $issues[] = $mapShapeWrite['details'];
        }

        if ($contractWrite['status'] === 'blocked') {
            $issues[] = $contractWrite['details'];
        }

        if ($accrualWrite['status'] === 'blocked') {
            $issues[] = $accrualWrite['details'];
        }

        if ($paymentWrite['status'] === 'blocked') {
            $issues[] = $paymentWrite['details'];
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
            'name' => trim((string) ($marketPayload['name'] ?? 'Демо-рынок Центральный')) ?: 'Демо-рынок Центральный',
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

    /**
     * @param array<string, mixed> $dataSet
     * @return array{status:string, details:string}
     */
    private function writeUsers(array $dataSet, int $marketId): array
    {
        $records = $this->recordsForSection($dataSet, 'users');

        if ($records === []) {
            return ['status' => 'blocked', 'details' => 'users payload is empty'];
        }

        if (! Schema::hasTable('roles') || ! Schema::hasTable('model_has_roles')) {
            return ['status' => 'blocked', 'details' => 'roles and model_has_roles tables are required for demo users'];
        }

        return DB::transaction(function () use ($dataSet, $records, $marketId): array {
            $tenantIdsByKey = $this->tenantIdsByKey($dataSet, $marketId);
            $accessPassword = app(DemoPilotSettings::class)->accessPassword();
            $seenEmails = [];
            $planned = [];
            $created = 0;
            $updated = 0;
            $unchanged = 0;

            foreach ($records as $record) {
                $email = mb_strtolower(trim((string) ($record['email'] ?? '')), 'UTF-8');
                $name = trim((string) ($record['name'] ?? ''));
                $roleName = $this->userRoleName($record);
                $payloadRole = trim((string) ($record['role'] ?? ''));
                $tenantKey = $record['tenant_key'] ?? null;
                $tenantKey = $tenantKey === null ? null : trim((string) $tenantKey);

                if ($email === '' || $name === '' || $roleName === '') {
                    return ['status' => 'blocked', 'details' => 'user email, name, and role are required'];
                }

                if (in_array($email, $seenEmails, true)) {
                    return ['status' => 'blocked', 'details' => 'users payload has duplicate email [' . $email . ']'];
                }

                $tenantId = null;

                if ($tenantKey !== null && $tenantKey !== '') {
                    if (! array_key_exists($tenantKey, $tenantIdsByKey)) {
                        return ['status' => 'blocked', 'details' => 'user [' . $email . '] references missing tenant [' . $tenantKey . ']'];
                    }

                    $tenantId = $tenantIdsByKey[$tenantKey];
                }

                if (in_array($payloadRole, ['tenant', 'merchant'], true) && $tenantId === null) {
                    return ['status' => 'blocked', 'details' => 'tenant user [' . $email . '] must reference a tenant'];
                }

                $user = User::query()->where('email', $email)->first();
                $source = trim((string) ($record['synthetic_source'] ?? 'demo_pilot')) ?: 'demo_pilot';

                if ($user !== null && ! $this->isSyntheticUser($user, $source, $marketId)) {
                    return [
                        'status' => 'blocked',
                        'details' => 'user email [' . $email . '] already belongs to a non-demo user',
                    ];
                }

                $seenEmails[] = $email;
                $planned[] = [$this->userAttributes($record, $marketId, $tenantId), $roleName, $user];
            }

            foreach ($planned as [$attributes, $roleName, $user]) {
                $role = Role::findOrCreate($roleName, 'web');
                $roleChanged = false;

                if ($user === null) {
                    $user = User::withoutEvents(static fn (): User => User::query()->create(array_merge($attributes, [
                        'password' => Hash::make($accessPassword ?? Str::random(40)),
                    ])));
                    $created++;
                } else {
                    $user->fill($attributes);

                    if ($accessPassword !== null && ! Hash::check($accessPassword, (string) $user->password)) {
                        $user->password = Hash::make($accessPassword);
                    }

                    if ($user->isDirty()) {
                        User::withoutEvents(static function () use ($user): void {
                            $user->save();
                        });
                        $updated++;
                    } else {
                        $unchanged++;
                    }
                }

                $currentRoles = $user->roles()
                    ->pluck('name')
                    ->map(static fn (mixed $name): string => (string) $name)
                    ->sort()
                    ->values()
                    ->all();

                if ($currentRoles !== [$role->name]) {
                    $user->syncRoles([$role]);
                    $roleChanged = true;
                }

                if ($roleChanged && $user->wasRecentlyCreated === false && ! $user->wasChanged()) {
                    $updated++;
                    $unchanged = max(0, $unchanged - 1);
                }
            }

            $status = $updated > 0 ? 'updated' : ($created > 0 ? 'created' : 'unchanged');

            return [
                'status' => $status,
                'details' => 'created [' . $created . '], updated [' . $updated . '], unchanged [' . $unchanged . '] users for market id [' . $marketId . ']',
            ];
        });
    }

    /**
     * @param array<string, mixed> $userPayload
     * @return array<string, mixed>
     */
    private function userAttributes(array $userPayload, int $marketId, ?int $tenantId): array
    {
        $source = trim((string) ($userPayload['synthetic_source'] ?? 'demo_pilot')) ?: 'demo_pilot';
        $roleName = $this->userRoleName($userPayload);

        return $this->onlyExistingColumns('users', [
            'name' => trim((string) ($userPayload['name'] ?? '')),
            'email' => mb_strtolower(trim((string) ($userPayload['email'] ?? '')), 'UTF-8'),
            'phone' => trim((string) ($userPayload['phone'] ?? '')),
            'job_title' => trim((string) ($userPayload['job_title'] ?? '')),
            'department' => trim((string) ($userPayload['department'] ?? '')),
            'market_id' => $marketId,
            'tenant_id' => $tenantId,
            'notification_preferences' => [
                'demo_pilot' => [
                    'synthetic_source' => $source,
                    'role' => $roleName,
                    'password_strategy' => app(DemoPilotSettings::class)->accessPassword() !== null
                        ? 'configured_shared_password'
                        : 'generated_on_provision',
                ],
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $userPayload
     */
    private function userRoleName(array $userPayload): string
    {
        $role = trim((string) ($userPayload['role'] ?? ''));

        return match ($role) {
            'director' => 'market-owner-director',
            'admin' => 'market-admin',
            'operator' => 'market-operator',
            'tenant' => 'merchant',
            default => $role,
        };
    }

    private function isSyntheticUser(User $user, string $source, int $marketId): bool
    {
        if ((int) ($user->market_id ?? 0) !== $marketId) {
            return false;
        }

        return data_get($user->notification_preferences, 'demo_pilot.synthetic_source') === $source;
    }

    /**
     * @param array<string, mixed> $dataSet
     * @return array{status:string, details:string}
     */
    private function writeSpaces(array $dataSet, int $marketId): array
    {
        $records = $this->recordsForSection($dataSet, 'spaces');

        if ($records === []) {
            return ['status' => 'blocked', 'details' => 'spaces payload is empty'];
        }

        return DB::transaction(function () use ($dataSet, $records, $marketId): array {
            $locationIdsByKey = $this->locationIdsByKey($dataSet, $marketId);
            $tenantIdsByKey = $this->tenantIdsByKey($dataSet, $marketId);
            $seenCodes = [];
            $planned = [];
            $created = 0;
            $updated = 0;
            $unchanged = 0;

            foreach ($records as $record) {
                $code = trim((string) ($record['code'] ?? ''));
                $locationKey = trim((string) ($record['location_key'] ?? ''));
                $tenantKey = $record['tenant_key'] ?? null;
                $tenantKey = $tenantKey === null ? null : trim((string) $tenantKey);

                if (
                    $code === ''
                    || trim((string) ($record['number'] ?? '')) === ''
                    || trim((string) ($record['display_name'] ?? '')) === ''
                ) {
                    return ['status' => 'blocked', 'details' => 'space code, number, and display_name are required'];
                }

                if ($locationKey === '' || ! array_key_exists($locationKey, $locationIdsByKey)) {
                    return ['status' => 'blocked', 'details' => 'space [' . $code . '] references missing location [' . $locationKey . ']'];
                }

                if (in_array($code, $seenCodes, true)) {
                    return ['status' => 'blocked', 'details' => 'spaces payload has duplicate code [' . $code . ']'];
                }

                $seenCodes[] = $code;
                $tenantId = null;

                if ($tenantKey !== null && $tenantKey !== '') {
                    if (! array_key_exists($tenantKey, $tenantIdsByKey)) {
                        return ['status' => 'blocked', 'details' => 'space [' . $code . '] references missing tenant [' . $tenantKey . ']'];
                    }

                    $tenantId = $tenantIdsByKey[$tenantKey];
                }

                $attributes = $this->spaceAttributes($record, $marketId, $locationIdsByKey[$locationKey], $tenantId);

                if (
                    $attributes['area_sqm'] === null
                    || $attributes['rent_rate_value'] === null
                    || $attributes['rent_rate_unit'] === ''
                ) {
                    return ['status' => 'blocked', 'details' => 'space [' . $code . '] area_sqm, rent_rate_value, and rent_rate_unit are required'];
                }

                $matches = MarketSpace::query()
                    ->where('market_id', $marketId)
                    ->where('code', $code)
                    ->get();

                if ($matches->count() > 1) {
                    return ['status' => 'blocked', 'details' => 'space code [' . $code . '] matches multiple existing spaces'];
                }

                $planned[] = [$attributes, $matches->first()];
            }

            foreach ($planned as [$attributes, $space]) {
                if ($space === null) {
                    MarketSpace::withoutEvents(static function () use ($attributes): void {
                        MarketSpace::query()->create($attributes);
                    });

                    $created++;

                    continue;
                }

                $space->fill($attributes);

                if (! $space->isDirty()) {
                    $unchanged++;

                    continue;
                }

                MarketSpace::withoutEvents(static function () use ($space): void {
                    $space->save();
                });

                $updated++;
            }

            $status = $updated > 0 ? 'updated' : ($created > 0 ? 'created' : 'unchanged');

            return [
                'status' => $status,
                'details' => 'created [' . $created . '], updated [' . $updated . '], unchanged [' . $unchanged . '] spaces for market id [' . $marketId . ']',
            ];
        });
    }

    /**
     * @param array<string, mixed> $spacePayload
     * @return array{market_id:int, location_id:int, tenant_id:int|null, number:string, code:string, display_name:string, activity_type:string, area_sqm:string|null, rent_rate_value:string|null, rent_rate_unit:string, type:string, status:string, is_active:bool, notes:string}
     */
    private function spaceAttributes(array $spacePayload, int $marketId, int $locationId, ?int $tenantId): array
    {
        $source = trim((string) ($spacePayload['synthetic_source'] ?? 'demo_pilot')) ?: 'demo_pilot';

        return [
            'market_id' => $marketId,
            'location_id' => $locationId,
            'tenant_id' => $tenantId,
            'number' => trim((string) ($spacePayload['number'] ?? '')),
            'code' => trim((string) ($spacePayload['code'] ?? '')),
            'display_name' => trim((string) ($spacePayload['display_name'] ?? '')),
            'activity_type' => trim((string) ($spacePayload['activity_type'] ?? 'retail')) ?: 'retail',
            'area_sqm' => $this->decimalString($spacePayload['area_sqm'] ?? null),
            'rent_rate_value' => $this->decimalString($spacePayload['rent_rate_value'] ?? null),
            'rent_rate_unit' => trim((string) ($spacePayload['rent_rate_unit'] ?? 'sqm_month')) ?: 'sqm_month',
            'type' => trim((string) ($spacePayload['type'] ?? 'retail')) ?: 'retail',
            'status' => trim((string) ($spacePayload['status'] ?? 'vacant')) ?: 'vacant',
            'is_active' => (bool) ($spacePayload['is_active'] ?? true),
            'notes' => 'Synthetic demo space. Source: ' . $source,
        ];
    }

    /**
     * @param array<string, mixed> $dataSet
     * @return array{status:string, details:string}
     */
    private function writeMapShapes(array $dataSet, int $marketId): array
    {
        $records = $this->recordsForSection($dataSet, 'map_shapes');

        if ($records === []) {
            return ['status' => 'blocked', 'details' => 'map_shapes payload is empty'];
        }

        return DB::transaction(function () use ($dataSet, $records, $marketId): array {
            $spaceIdsByKey = $this->spaceIdsByKey($dataSet, $marketId);
            $seenKeys = [];
            $planned = [];
            $created = 0;
            $updated = 0;
            $unchanged = 0;

            foreach ($records as $record) {
                $key = trim((string) ($record['key'] ?? ''));
                $spaceKey = trim((string) ($record['market_space_key'] ?? ''));
                $page = max(1, (int) ($record['page'] ?? 1));
                $version = max(1, (int) ($record['version'] ?? 1));
                $dedupeKey = $page . ':' . $version . ':' . $spaceKey;

                if ($key === '' || $spaceKey === '') {
                    return ['status' => 'blocked', 'details' => 'map shape key and market_space_key are required'];
                }

                if (! array_key_exists($spaceKey, $spaceIdsByKey)) {
                    return ['status' => 'blocked', 'details' => 'map shape [' . $key . '] references missing market_space [' . $spaceKey . ']'];
                }

                if (in_array($dedupeKey, $seenKeys, true)) {
                    return ['status' => 'blocked', 'details' => 'map_shapes payload has duplicate page/version/space [' . $dedupeKey . ']'];
                }

                $polygon = MarketSpaceMapShape::normalizePolygon($record['polygon'] ?? []);

                if (count($polygon) < 3) {
                    return ['status' => 'blocked', 'details' => 'map shape [' . $key . '] polygon must contain at least 3 points'];
                }

                $attributes = $this->mapShapeAttributes($record, $marketId, $spaceIdsByKey[$spaceKey], $polygon);
                $matches = MarketSpaceMapShape::query()
                    ->where('market_id', $marketId)
                    ->where('market_space_id', $spaceIdsByKey[$spaceKey])
                    ->where('page', $page)
                    ->where('version', $version)
                    ->get();

                if ($matches->count() > 1) {
                    return ['status' => 'blocked', 'details' => 'map shape for space [' . $spaceKey . '] matches multiple existing shapes'];
                }

                $shape = $matches->first();
                $source = (string) data_get($attributes, 'meta.demo_pilot.synthetic_source', 'demo_pilot');

                if ($shape !== null && ! $this->isSyntheticMapShape($shape, $key, $source)) {
                    return ['status' => 'blocked', 'details' => 'map shape for space [' . $spaceKey . '] already exists and is not the expected demo shape [' . $key . ']'];
                }

                $seenKeys[] = $dedupeKey;
                $planned[] = [$attributes, $shape];
            }

            foreach ($planned as [$attributes, $shape]) {
                if ($shape === null) {
                    MarketSpaceMapShape::query()->create($attributes);
                    $created++;

                    continue;
                }

                $shape->fill($attributes);

                if (! $shape->isDirty()) {
                    $unchanged++;

                    continue;
                }

                $shape->save();
                $updated++;
            }

            $status = $updated > 0 ? 'updated' : ($created > 0 ? 'created' : 'unchanged');

            return [
                'status' => $status,
                'details' => 'created [' . $created . '], updated [' . $updated . '], unchanged [' . $unchanged . '] map shapes for market id [' . $marketId . ']',
            ];
        });
    }

    /**
     * @param array<string, mixed> $shapePayload
     * @param list<array{x:float, y:float}> $polygon
     * @return array<string, mixed>
     */
    private function mapShapeAttributes(array $shapePayload, int $marketId, int $spaceId, array $polygon): array
    {
        [$x1, $y1, $x2, $y2] = MarketSpaceMapShape::computeBbox($polygon);
        $source = trim((string) ($shapePayload['synthetic_source'] ?? 'demo_pilot')) ?: 'demo_pilot';
        $key = trim((string) ($shapePayload['key'] ?? ''));
        $spaceKey = trim((string) ($shapePayload['market_space_key'] ?? ''));

        return [
            'market_id' => $marketId,
            'market_space_id' => $spaceId,
            'page' => max(1, (int) ($shapePayload['page'] ?? 1)),
            'version' => max(1, (int) ($shapePayload['version'] ?? 1)),
            'polygon' => $polygon,
            'bbox_x1' => round($x1, 2),
            'bbox_y1' => round($y1, 2),
            'bbox_x2' => round($x2, 2),
            'bbox_y2' => round($y2, 2),
            'fill_color' => trim((string) ($shapePayload['fill_color'] ?? '#00A3FF')) ?: '#00A3FF',
            'stroke_color' => trim((string) ($shapePayload['stroke_color'] ?? '#00A3FF')) ?: '#00A3FF',
            'fill_opacity' => $this->decimalString($shapePayload['fill_opacity'] ?? 0.18),
            'stroke_width' => $this->decimalString($shapePayload['stroke_width'] ?? 2),
            'sort_order' => (int) ($shapePayload['sort_order'] ?? 0),
            'is_active' => (bool) ($shapePayload['is_active'] ?? true),
            'meta' => [
                'demo_pilot' => [
                    'synthetic_source' => $source,
                    'key' => $key,
                    'market_space_key' => $spaceKey,
                ],
            ],
        ];
    }

    private function isSyntheticMapShape(MarketSpaceMapShape $shape, string $key, string $source): bool
    {
        return data_get($shape->meta, 'demo_pilot.key') === $key
            && data_get($shape->meta, 'demo_pilot.synthetic_source') === $source;
    }

    /**
     * @param array<string, mixed> $dataSet
     * @return array<string, int>
     */
    private function locationIdsByKey(array $dataSet, int $marketId): array
    {
        $idsByKey = [];

        foreach ($this->recordsForSection($dataSet, 'locations') as $record) {
            $key = trim((string) ($record['key'] ?? ''));
            $code = trim((string) ($record['code'] ?? ''));

            if ($key === '' || $code === '') {
                continue;
            }

            $id = MarketLocation::query()
                ->where('market_id', $marketId)
                ->where('code', $code)
                ->value('id');

            if ($id !== null) {
                $idsByKey[$key] = (int) $id;
            }
        }

        return $idsByKey;
    }

    /**
     * @param array<string, mixed> $dataSet
     * @return array<string, int>
     */
    private function tenantIdsByKey(array $dataSet, int $marketId): array
    {
        $idsByKey = [];

        foreach ($this->recordsForSection($dataSet, 'tenants') as $record) {
            $key = trim((string) ($record['key'] ?? ''));
            $externalId = trim((string) ($record['external_id'] ?? ''));

            if ($key === '' || $externalId === '') {
                continue;
            }

            $id = Tenant::query()
                ->where('market_id', $marketId)
                ->where('external_id', $externalId)
                ->value('id');

            if ($id !== null) {
                $idsByKey[$key] = (int) $id;
            }
        }

        return $idsByKey;
    }

    private function decimalString(mixed $value): ?string
    {
        if (! is_numeric($value)) {
            return null;
        }

        return number_format((float) $value, 2, '.', '');
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function onlyExistingColumns(string $table, array $attributes): array
    {
        return array_filter(
            $attributes,
            static fn (string $column): bool => Schema::hasColumn($table, $column),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * @param array<string, mixed> $dataSet
     * @return array{status:string, details:string}
     */
    private function writeContracts(array $dataSet, int $marketId): array
    {
        $records = $this->recordsForSection($dataSet, 'contracts');

        if ($records === []) {
            return ['status' => 'blocked', 'details' => 'contracts payload is empty'];
        }

        return DB::transaction(function () use ($dataSet, $records, $marketId): array {
            $tenantIdsByKey = $this->tenantIdsByKey($dataSet, $marketId);
            $spaceIdsByKey = $this->spaceIdsByKey($dataSet, $marketId);
            $seenExternalIds = [];
            $planned = [];
            $created = 0;
            $updated = 0;
            $unchanged = 0;

            foreach ($records as $record) {
                $externalId = trim((string) ($record['external_id'] ?? ''));
                $tenantKey = trim((string) ($record['tenant_key'] ?? ''));
                $spaceKey = trim((string) ($record['market_space_key'] ?? ''));

                if (
                    $externalId === ''
                    || trim((string) ($record['number'] ?? '')) === ''
                    || trim((string) ($record['starts_at'] ?? '')) === ''
                ) {
                    return ['status' => 'blocked', 'details' => 'contract external_id, number, and starts_at are required'];
                }

                if (in_array($externalId, $seenExternalIds, true)) {
                    return ['status' => 'blocked', 'details' => 'contracts payload has duplicate external_id [' . $externalId . ']'];
                }

                if ($tenantKey === '' || ! array_key_exists($tenantKey, $tenantIdsByKey)) {
                    return ['status' => 'blocked', 'details' => 'contract [' . $externalId . '] references missing tenant [' . $tenantKey . ']'];
                }

                if ($spaceKey === '' || ! array_key_exists($spaceKey, $spaceIdsByKey)) {
                    return ['status' => 'blocked', 'details' => 'contract [' . $externalId . '] references missing market_space [' . $spaceKey . ']'];
                }

                $seenExternalIds[] = $externalId;
                $attributes = $this->contractAttributes($record, $marketId, $tenantIdsByKey[$tenantKey], $spaceIdsByKey[$spaceKey]);

                if ($attributes['monthly_rent'] === null || $attributes['currency'] === '') {
                    return ['status' => 'blocked', 'details' => 'contract [' . $externalId . '] monthly_rent and currency are required'];
                }

                $matches = TenantContract::query()
                    ->where('market_id', $marketId)
                    ->where('external_id', $externalId)
                    ->get();

                if ($matches->count() > 1) {
                    return ['status' => 'blocked', 'details' => 'contract external_id [' . $externalId . '] matches multiple existing contracts'];
                }

                $planned[] = [$attributes, $matches->first()];
            }

            foreach ($planned as [$attributes, $contract]) {
                if ($contract === null) {
                    TenantContract::withoutEvents(static function () use ($attributes): void {
                        TenantContract::query()->create($attributes);
                    });

                    $created++;

                    continue;
                }

                $contract->fill($attributes);

                if (! $contract->isDirty()) {
                    $unchanged++;

                    continue;
                }

                TenantContract::withoutEvents(static function () use ($contract): void {
                    $contract->save();
                });

                $updated++;
            }

            $status = $updated > 0 ? 'updated' : ($created > 0 ? 'created' : 'unchanged');

            return [
                'status' => $status,
                'details' => 'created [' . $created . '], updated [' . $updated . '], unchanged [' . $unchanged . '] contracts for market id [' . $marketId . ']',
            ];
        });
    }

    /**
     * @param array<string, mixed> $contractPayload
     * @return array{external_id:string, market_id:int, tenant_id:int, market_space_id:int, number:string, status:string, starts_at:string, ends_at:string|null, signed_at:string|null, monthly_rent:string|null, currency:string, is_active:bool, space_mapping_mode:string, notes:string}
     */
    private function contractAttributes(array $contractPayload, int $marketId, int $tenantId, int $spaceId): array
    {
        $source = trim((string) ($contractPayload['synthetic_source'] ?? 'demo_pilot')) ?: 'demo_pilot';
        $mode = trim((string) ($contractPayload['space_mapping_mode'] ?? TenantContract::SPACE_MAPPING_MODE_MANUAL));

        return [
            'external_id' => trim((string) ($contractPayload['external_id'] ?? '')),
            'market_id' => $marketId,
            'tenant_id' => $tenantId,
            'market_space_id' => $spaceId,
            'number' => trim((string) ($contractPayload['number'] ?? '')),
            'status' => trim((string) ($contractPayload['status'] ?? 'active')) ?: 'active',
            'starts_at' => trim((string) ($contractPayload['starts_at'] ?? '')),
            'ends_at' => filled($contractPayload['ends_at'] ?? null) ? trim((string) $contractPayload['ends_at']) : null,
            'signed_at' => filled($contractPayload['signed_at'] ?? null) ? trim((string) $contractPayload['signed_at']) : null,
            'monthly_rent' => $this->decimalString($contractPayload['monthly_rent'] ?? null),
            'currency' => trim((string) ($contractPayload['currency'] ?? 'RUB')) ?: 'RUB',
            'is_active' => (bool) ($contractPayload['is_active'] ?? true),
            'space_mapping_mode' => in_array($mode, TenantContract::spaceMappingModes(), true)
                ? $mode
                : TenantContract::SPACE_MAPPING_MODE_MANUAL,
            'notes' => 'Synthetic demo contract. Source: ' . $source,
        ];
    }

    /**
     * @param array<string, mixed> $dataSet
     * @return array<string, int>
     */
    private function spaceIdsByKey(array $dataSet, int $marketId): array
    {
        $idsByKey = [];

        foreach ($this->recordsForSection($dataSet, 'spaces') as $record) {
            $key = trim((string) ($record['key'] ?? ''));
            $code = trim((string) ($record['code'] ?? ''));

            if ($key === '' || $code === '') {
                continue;
            }

            $id = MarketSpace::query()
                ->where('market_id', $marketId)
                ->where('code', $code)
                ->value('id');

            if ($id !== null) {
                $idsByKey[$key] = (int) $id;
            }
        }

        return $idsByKey;
    }

    /**
     * @param array<string, mixed> $dataSet
     * @return array{status:string, details:string}
     */
    private function writeAccruals(array $dataSet, int $marketId): array
    {
        $records = $this->recordsForSection($dataSet, 'accruals');

        if ($records === []) {
            return ['status' => 'blocked', 'details' => 'accruals payload is empty'];
        }

        return DB::transaction(function () use ($dataSet, $records, $marketId): array {
            $tenantIdsByKey = $this->tenantIdsByKey($dataSet, $marketId);
            $spaceIdsByKey = $this->spaceIdsByKey($dataSet, $marketId);
            $contractIdsByKey = $this->contractIdsByKey($dataSet, $marketId);
            $contractExternalIdsByKey = $this->contractExternalIdsByKey($dataSet);
            $seenHashes = [];
            $planned = [];
            $created = 0;
            $updated = 0;
            $unchanged = 0;

            foreach ($records as $record) {
                $key = trim((string) ($record['key'] ?? ''));
                $tenantKey = trim((string) ($record['tenant_key'] ?? ''));
                $contractKey = trim((string) ($record['tenant_contract_key'] ?? ''));
                $spaceKey = trim((string) ($record['market_space_key'] ?? ''));
                $period = trim((string) ($record['period'] ?? ''));
                $hash = $this->demoSourceRowHash('accruals', $key);

                if ($key === '' || $period === '' || trim((string) ($record['document_date'] ?? '')) === '') {
                    return ['status' => 'blocked', 'details' => 'accrual key, period, and document_date are required'];
                }

                if (in_array($hash, $seenHashes, true)) {
                    return ['status' => 'blocked', 'details' => 'accruals payload has duplicate source hash for key [' . $key . ']'];
                }

                if ($tenantKey === '' || ! array_key_exists($tenantKey, $tenantIdsByKey)) {
                    return ['status' => 'blocked', 'details' => 'accrual [' . $key . '] references missing tenant [' . $tenantKey . ']'];
                }

                if ($contractKey === '' || ! array_key_exists($contractKey, $contractIdsByKey)) {
                    return ['status' => 'blocked', 'details' => 'accrual [' . $key . '] references missing contract [' . $contractKey . ']'];
                }

                if ($spaceKey === '' || ! array_key_exists($spaceKey, $spaceIdsByKey)) {
                    return ['status' => 'blocked', 'details' => 'accrual [' . $key . '] references missing market_space [' . $spaceKey . ']'];
                }

                $attributes = $this->accrualAttributes(
                    $record,
                    $marketId,
                    $tenantIdsByKey[$tenantKey],
                    $contractIdsByKey[$contractKey],
                    $spaceIdsByKey[$spaceKey],
                    $contractExternalIdsByKey[$contractKey] ?? null,
                    $hash,
                );

                if (
                    $attributes['rent_amount'] === null
                    || $attributes['total_no_vat'] === null
                    || $attributes['total_with_vat'] === null
                ) {
                    return ['status' => 'blocked', 'details' => 'accrual [' . $key . '] rent_amount, total_no_vat, and total_with_vat are required'];
                }

                $matches = TenantAccrual::query()
                    ->where('market_id', $marketId)
                    ->whereDate('period', $period)
                    ->where('source_row_hash', $hash)
                    ->get();

                if ($matches->count() > 1) {
                    return ['status' => 'blocked', 'details' => 'accrual source hash [' . $hash . '] matches multiple existing accruals'];
                }

                $seenHashes[] = $hash;
                $planned[] = [$attributes, $matches->first()];
            }

            foreach ($planned as [$attributes, $accrual]) {
                if ($accrual === null) {
                    TenantAccrual::withoutEvents(static function () use ($attributes): void {
                        TenantAccrual::query()->create($attributes);
                    });

                    $created++;

                    continue;
                }

                $accrual->fill($attributes);

                if (! $accrual->isDirty()) {
                    $unchanged++;

                    continue;
                }

                TenantAccrual::withoutEvents(static function () use ($accrual): void {
                    $accrual->save();
                });

                $updated++;
            }

            $status = $updated > 0 ? 'updated' : ($created > 0 ? 'created' : 'unchanged');

            return [
                'status' => $status,
                'details' => 'created [' . $created . '], updated [' . $updated . '], unchanged [' . $unchanged . '] accruals for market id [' . $marketId . ']',
            ];
        });
    }

    /**
     * @param array<string, mixed> $accrualPayload
     * @return array<string, mixed>
     */
    private function accrualAttributes(
        array $accrualPayload,
        int $marketId,
        int $tenantId,
        int $contractId,
        int $spaceId,
        ?string $contractExternalId,
        string $sourceRowHash,
    ): array {
        $key = trim((string) ($accrualPayload['key'] ?? ''));
        $source = trim((string) ($accrualPayload['synthetic_source'] ?? 'demo_pilot')) ?: 'demo_pilot';

        return [
            'market_id' => $marketId,
            'tenant_id' => $tenantId,
            'tenant_contract_id' => $contractId,
            'market_space_id' => $spaceId,
            'contract_external_id' => $contractExternalId,
            'contract_link_status' => TenantAccrual::CONTRACT_LINK_STATUS_EXACT,
            'contract_link_source' => 'demo_pilot',
            'contract_link_note' => 'Synthetic demo finance link.',
            'period' => trim((string) ($accrualPayload['period'] ?? '')),
            'document_external_id' => 'demo-' . $key,
            'document_number' => 'D-ACCRUAL-' . strtoupper(str_replace('accrual-', '', $key)),
            'document_date' => trim((string) ($accrualPayload['document_date'] ?? '')),
            'document_name' => 'Demo accrual ' . $key,
            'service_name' => 'Rent',
            'line_description' => 'Synthetic demo rent accrual.',
            'purpose' => 'Demo data only. External 1C import is disabled.',
            'currency' => 'RUB',
            'rent_amount' => $this->decimalString($accrualPayload['rent_amount'] ?? null),
            'management_fee' => $this->decimalString($accrualPayload['management_fee'] ?? 0),
            'utilities_amount' => $this->decimalString($accrualPayload['utilities_amount'] ?? 0),
            'electricity_amount' => $this->decimalString($accrualPayload['electricity_amount'] ?? 0),
            'total_no_vat' => $this->decimalString($accrualPayload['total_no_vat'] ?? null),
            'vat_rate' => $this->decimalString($accrualPayload['vat_rate'] ?? 0),
            'total_with_vat' => $this->decimalString($accrualPayload['total_with_vat'] ?? null),
            'cash_amount' => $this->decimalString($accrualPayload['cash_amount'] ?? 0),
            'status' => 'imported',
            'source' => 'demo_pilot',
            'source_file' => 'demo_pilot',
            'source_row_hash' => $sourceRowHash,
            'payload' => $this->jsonPayload([
                'synthetic_source' => $source,
                'live_1c' => false,
                'demo_key' => $key,
                'tenant_key' => $accrualPayload['tenant_key'] ?? null,
                'tenant_contract_key' => $accrualPayload['tenant_contract_key'] ?? null,
                'market_space_key' => $accrualPayload['market_space_key'] ?? null,
            ]),
            'imported_at' => trim((string) ($accrualPayload['imported_at'] ?? '')),
        ];
    }

    /**
     * @param array<string, mixed> $dataSet
     * @return array{status:string, details:string}
     */
    private function writePayments(array $dataSet, int $marketId): array
    {
        $records = $this->recordsForSection($dataSet, 'payments');

        if ($records === []) {
            return ['status' => 'blocked', 'details' => 'payments payload is empty'];
        }

        return DB::transaction(function () use ($dataSet, $records, $marketId): array {
            $tenantIdsByKey = $this->tenantIdsByKey($dataSet, $marketId);
            $tenantExternalIdsByKey = $this->tenantExternalIdsByKey($dataSet);
            $contractIdsByKey = $this->contractIdsByKey($dataSet, $marketId);
            $contractExternalIdsByKey = $this->contractExternalIdsByKey($dataSet);
            $seenHashes = [];
            $planned = [];
            $created = 0;
            $updated = 0;
            $unchanged = 0;

            foreach ($records as $record) {
                $key = trim((string) ($record['key'] ?? ''));
                $tenantKey = trim((string) ($record['tenant_key'] ?? ''));
                $contractKey = trim((string) ($record['tenant_contract_key'] ?? ''));
                $hash = $this->demoSourceRowHash('payments', $key);

                if (
                    $key === ''
                    || trim((string) ($record['payment_date'] ?? '')) === ''
                    || trim((string) ($record['period'] ?? '')) === ''
                ) {
                    return ['status' => 'blocked', 'details' => 'payment key, payment_date, and period are required'];
                }

                if (in_array($hash, $seenHashes, true)) {
                    return ['status' => 'blocked', 'details' => 'payments payload has duplicate source hash for key [' . $key . ']'];
                }

                if ($tenantKey === '' || ! array_key_exists($tenantKey, $tenantIdsByKey)) {
                    return ['status' => 'blocked', 'details' => 'payment [' . $key . '] references missing tenant [' . $tenantKey . ']'];
                }

                if ($contractKey === '' || ! array_key_exists($contractKey, $contractIdsByKey)) {
                    return ['status' => 'blocked', 'details' => 'payment [' . $key . '] references missing contract [' . $contractKey . ']'];
                }

                $attributes = $this->paymentAttributes(
                    $record,
                    $marketId,
                    $tenantIdsByKey[$tenantKey],
                    $contractIdsByKey[$contractKey],
                    $tenantExternalIdsByKey[$tenantKey] ?? '',
                    $contractExternalIdsByKey[$contractKey] ?? null,
                    $hash,
                );

                if ($attributes['tenant_external_id'] === '' || $attributes['amount'] === null) {
                    return ['status' => 'blocked', 'details' => 'payment [' . $key . '] tenant_external_id and amount are required'];
                }

                $matches = TenantPayment::query()
                    ->where('market_id', $marketId)
                    ->where('source_row_hash', $hash)
                    ->get();

                if ($matches->count() > 1) {
                    return ['status' => 'blocked', 'details' => 'payment source hash [' . $hash . '] matches multiple existing payments'];
                }

                $seenHashes[] = $hash;
                $planned[] = [$attributes, $matches->first()];
            }

            foreach ($planned as [$attributes, $payment]) {
                if ($payment === null) {
                    TenantPayment::withoutEvents(static function () use ($attributes): void {
                        TenantPayment::query()->create($attributes);
                    });

                    $created++;

                    continue;
                }

                $payment->fill($attributes);

                if (! $payment->isDirty()) {
                    $unchanged++;

                    continue;
                }

                TenantPayment::withoutEvents(static function () use ($payment): void {
                    $payment->save();
                });

                $updated++;
            }

            $status = $updated > 0 ? 'updated' : ($created > 0 ? 'created' : 'unchanged');

            return [
                'status' => $status,
                'details' => 'created [' . $created . '], updated [' . $updated . '], unchanged [' . $unchanged . '] payments for market id [' . $marketId . ']',
            ];
        });
    }

    /**
     * @param array<string, mixed> $paymentPayload
     * @return array<string, mixed>
     */
    private function paymentAttributes(
        array $paymentPayload,
        int $marketId,
        int $tenantId,
        int $contractId,
        string $tenantExternalId,
        ?string $contractExternalId,
        string $sourceRowHash,
    ): array {
        $key = trim((string) ($paymentPayload['key'] ?? ''));
        $source = trim((string) ($paymentPayload['synthetic_source'] ?? 'demo_pilot')) ?: 'demo_pilot';
        $payload = is_array($paymentPayload['payload'] ?? null) ? $paymentPayload['payload'] : [];

        return [
            'market_id' => $marketId,
            'tenant_id' => $tenantId,
            'tenant_contract_id' => $contractId,
            'tenant_external_id' => $tenantExternalId,
            'contract_external_id' => $contractExternalId,
            'payment_external_id' => 'demo-' . $key,
            'document_number' => 'D-PAYMENT-' . strtoupper(str_replace('payment-', '', $key)),
            'payment_date' => trim((string) ($paymentPayload['payment_date'] ?? '')),
            'period' => trim((string) ($paymentPayload['period'] ?? '')),
            'amount' => $this->decimalString($paymentPayload['amount'] ?? null),
            'currency' => 'RUB',
            'purpose' => 'Demo data only. External 1C import is disabled.',
            'source' => 'demo_pilot',
            'source_file' => 'demo_pilot',
            'payload' => array_merge($payload, [
                'synthetic_source' => $source,
                'live_1c' => false,
                'demo_key' => $key,
                'tenant_key' => $paymentPayload['tenant_key'] ?? null,
                'tenant_contract_key' => $paymentPayload['tenant_contract_key'] ?? null,
            ]),
            'imported_at' => trim((string) ($paymentPayload['imported_at'] ?? '')),
            'source_row_hash' => $sourceRowHash,
        ];
    }

    /**
     * @param array<string, mixed> $dataSet
     * @return array<string, int>
     */
    private function contractIdsByKey(array $dataSet, int $marketId): array
    {
        $idsByKey = [];

        foreach ($this->recordsForSection($dataSet, 'contracts') as $record) {
            $key = trim((string) ($record['key'] ?? ''));
            $externalId = trim((string) ($record['external_id'] ?? ''));

            if ($key === '' || $externalId === '') {
                continue;
            }

            $id = TenantContract::query()
                ->where('market_id', $marketId)
                ->where('external_id', $externalId)
                ->value('id');

            if ($id !== null) {
                $idsByKey[$key] = (int) $id;
            }
        }

        return $idsByKey;
    }

    /**
     * @param array<string, mixed> $dataSet
     * @return array<string, string>
     */
    private function tenantExternalIdsByKey(array $dataSet): array
    {
        $externalIdsByKey = [];

        foreach ($this->recordsForSection($dataSet, 'tenants') as $record) {
            $key = trim((string) ($record['key'] ?? ''));
            $externalId = trim((string) ($record['external_id'] ?? ''));

            if ($key !== '' && $externalId !== '') {
                $externalIdsByKey[$key] = $externalId;
            }
        }

        return $externalIdsByKey;
    }

    /**
     * @param array<string, mixed> $dataSet
     * @return array<string, string>
     */
    private function contractExternalIdsByKey(array $dataSet): array
    {
        $externalIdsByKey = [];

        foreach ($this->recordsForSection($dataSet, 'contracts') as $record) {
            $key = trim((string) ($record['key'] ?? ''));
            $externalId = trim((string) ($record['external_id'] ?? ''));

            if ($key !== '' && $externalId !== '') {
                $externalIdsByKey[$key] = $externalId;
            }
        }

        return $externalIdsByKey;
    }

    private function demoSourceRowHash(string $section, string $key): string
    {
        return hash('sha256', 'demo_pilot:' . $section . ':' . $key);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function jsonPayload(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_SLASHES) ?: '{}';
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
        $this->keys($dataSet, 'map_shapes', $issues);
        $contractKeys = $this->keys($dataSet, 'contracts', $issues);
        $categoryKeys = $this->keys($dataSet, 'marketplace_categories', $issues);

        $this->assertReferences($this->recordsForSection($dataSet, 'users'), 'tenant_key', $tenantKeys, 'users', $issues, true);
        $this->assertReferences($this->recordsForSection($dataSet, 'spaces'), 'tenant_key', $tenantKeys, 'spaces', $issues, true);
        $this->assertReferences($this->recordsForSection($dataSet, 'map_shapes'), 'market_space_key', $spaceKeys, 'map_shapes', $issues);
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
