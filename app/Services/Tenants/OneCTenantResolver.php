<?php

declare(strict_types=1);

# app/Services/Tenants/OneCTenantResolver.php

namespace App\Services\Tenants;

use App\Models\Tenant;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OneCTenantResolver
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array{activate_resolved_tenant?:bool,preferred_tenant_id?:int}  $options
     * @return array{tenant:?Tenant, mode:'preferred_existing'|'existing_external_id'|'matched_alias'|'matched_inn'|'created'|'failed'}
     */
    public function resolve(
        int $marketId,
        string $tenantExternalId,
        array $payload,
        string $source,
        CarbonInterface $now,
        array $options = [],
    ): array {
        $tenantExternalId = trim($tenantExternalId);
        $activateResolvedTenant = (bool) ($options['activate_resolved_tenant'] ?? false);
        $preferredTenantId = (int) ($options['preferred_tenant_id'] ?? 0);

        $inn = trim((string) ($payload['inn'] ?? ''));
        $kpp = trim((string) ($payload['kpp'] ?? ''));
        $tenantName = trim((string) ($payload['tenant_name'] ?? ''));

        if ($preferredTenantId > 0) {
            $preferredTenant = Tenant::query()
                ->where('market_id', $marketId)
                ->whereKey($preferredTenantId)
                ->first();

            if ($preferredTenant) {
                $this->hydrateExistingTenant(
                    $preferredTenant,
                    $tenantExternalId,
                    $inn,
                    $kpp,
                    $tenantName,
                    $source,
                    $now,
                    $activateResolvedTenant,
                );

                return [
                    'tenant' => $preferredTenant,
                    'mode' => 'preferred_existing',
                ];
            }
        }

        if ($tenantExternalId !== '') {
            $tenant = Tenant::query()
                ->where('market_id', $marketId)
                ->where('external_id', $tenantExternalId)
                ->first();

            if ($tenant) {
                $this->hydrateExistingTenant(
                    $tenant,
                    $tenantExternalId,
                    $inn,
                    $kpp,
                    $tenantName,
                    $source,
                    $now,
                    $activateResolvedTenant,
                );

                return [
                    'tenant' => $tenant,
                    'mode' => 'existing_external_id',
                ];
            }
        }

        $tenantByAlias = $this->findTenantByAlias($marketId, $tenantExternalId, $inn);

        if ($tenantByAlias) {
            $this->hydrateExistingTenant(
                $tenantByAlias,
                $tenantExternalId,
                $inn,
                $kpp,
                $tenantName,
                $source,
                $now,
                $activateResolvedTenant,
                false,
            );

            return [
                'tenant' => $tenantByAlias,
                'mode' => 'matched_alias',
            ];
        }

        if ($inn !== '') {
            $tenantByInn = Tenant::query()
                ->where('market_id', $marketId)
                ->where('inn', $inn)
                ->first();

            if ($tenantByInn) {
                $this->hydrateExistingTenant(
                    $tenantByInn,
                    $tenantExternalId,
                    $inn,
                    $kpp,
                    $tenantName,
                    $source,
                    $now,
                    $activateResolvedTenant,
                );

                return [
                    'tenant' => $tenantByInn,
                    'mode' => 'matched_inn',
                ];
            }
        }

        if ($tenantExternalId === '') {
            return [
                'tenant' => null,
                'mode' => 'failed',
            ];
        }

        $tenant = new Tenant();
        $tenant->market_id = $marketId;
        $tenant->inn = $inn !== '' ? $inn : null;
        $tenant->kpp = $kpp !== '' ? $kpp : null;
        $tenant->name = $tenantName !== '' ? $tenantName : ('1C tenant ' . $tenantExternalId);
        $tenant->external_id = $tenantExternalId;

        if ($this->looksLikeUuid($tenantExternalId)) {
            $tenant->one_c_uid = $tenantExternalId;
        }

        if (Schema::hasColumn('tenants', 'is_active')) {
            $tenant->is_active = true;
        }

        $tenant->one_c_data = $this->safeJsonEncode([
            'created_from' => $source,
            'first_seen' => $now->toDateTimeString(),
            'last_seen' => $now->toDateTimeString(),
            'inn' => $inn !== '' ? $inn : null,
            'kpp' => $kpp !== '' ? $kpp : null,
            'tenant_name' => $tenantName,
        ]);

        $tenant->save();

        return [
            'tenant' => $tenant,
            'mode' => 'created',
        ];
    }

    private function hydrateExistingTenant(
        Tenant $tenant,
        string $tenantExternalId,
        string $inn,
        string $kpp,
        string $tenantName,
        string $source,
        CarbonInterface $now,
        bool $activateResolvedTenant,
        bool $allowExternalIdentityUpdate = true,
    ): void {
        if ($allowExternalIdentityUpdate && $tenantExternalId !== '') {
            $tenant->external_id = $tenantExternalId;
        }

        if ($allowExternalIdentityUpdate && $this->looksLikeUuid($tenantExternalId)) {
            $tenant->one_c_uid = $tenantExternalId;
        }

        if (($tenant->inn === null || $tenant->inn === '') && $inn !== '') {
            $tenant->inn = $inn;
        }

        if (($tenant->kpp === null || $tenant->kpp === '') && $kpp !== '') {
            $tenant->kpp = $kpp;
        }

        if ($tenantName !== '' && ($tenant->name === null || $tenant->name === '')) {
            $tenant->name = $tenantName;
        }

        if ($activateResolvedTenant && Schema::hasColumn('tenants', 'is_active')) {
            $tenant->is_active = true;
        }

        $existing = $this->decodeOneCData($tenant->one_c_data);
        $existing = array_merge($existing, [
            'last_seen' => $now->toDateTimeString(),
            'inn' => $inn !== '' ? $inn : ($existing['inn'] ?? null),
            'kpp' => $kpp !== '' ? $kpp : ($existing['kpp'] ?? null),
            'tenant_name' => $tenantName !== '' ? $tenantName : ($existing['tenant_name'] ?? null),
            'last_resolved_from' => $source,
        ]);

        if (! isset($existing['created_from'])) {
            $existing['created_from'] = $source;
        }

        if (! $allowExternalIdentityUpdate && $tenantExternalId !== '') {
            $existing['last_alias_external_id'] = $tenantExternalId;
        }

        $tenant->one_c_data = $this->safeJsonEncode($existing);
        $tenant->save();
    }

    private function findTenantByAlias(int $marketId, string $tenantExternalId, string $inn): ?Tenant
    {
        if (! Schema::hasTable('tenant_external_aliases')
            || ! Schema::hasColumn('tenant_external_aliases', 'canonical_tenant_id')
            || ! Schema::hasColumn('tenant_external_aliases', 'alias_type')
            || ! Schema::hasColumn('tenant_external_aliases', 'alias_value')) {
            return null;
        }

        $aliases = [];

        if ($tenantExternalId !== '') {
            $aliases[] = ['type' => 'external_id', 'value' => $tenantExternalId];

            if ($this->looksLikeUuid($tenantExternalId)) {
                $aliases[] = ['type' => 'one_c_uid', 'value' => $tenantExternalId];
            }
        }

        if ($inn !== '') {
            $aliases[] = ['type' => 'inn', 'value' => $inn];
        }

        foreach ($aliases as $alias) {
            $row = DB::table('tenant_external_aliases')
                ->where('market_id', $marketId)
                ->where('alias_type', $alias['type'])
                ->where('alias_value', $alias['value'])
                ->orderByDesc('id')
                ->first(['canonical_tenant_id']);

            $canonicalTenantId = (int) ($row->canonical_tenant_id ?? 0);

            if ($canonicalTenantId <= 0) {
                continue;
            }

            $tenant = Tenant::query()
                ->where('market_id', $marketId)
                ->whereKey($canonicalTenantId)
                ->first();

            if ($tenant) {
                return $tenant;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeOneCData(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private function safeJsonEncode(mixed $value): string
    {
        $json = json_encode(
            $value,
            JSON_UNESCAPED_UNICODE
            | JSON_PRESERVE_ZERO_FRACTION
            | JSON_INVALID_UTF8_SUBSTITUTE
        );

        return is_string($json) ? $json : '{}';
    }

    private function looksLikeUuid(string $value): bool
    {
        return preg_match('/^[0-9a-fA-F-]{36}$/', $value) === 1;
    }
}
