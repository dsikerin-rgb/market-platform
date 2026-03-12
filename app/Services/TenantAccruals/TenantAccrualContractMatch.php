<?php

declare(strict_types=1);

namespace App\Services\TenantAccruals;

use App\Models\TenantAccrual;

final class TenantAccrualContractMatch
{
    private function __construct(
        public readonly ?int $tenantContractId,
        public readonly string $status,
        public readonly string $source,
        public readonly ?string $note = null,
    ) {
    }

    public static function exact(int $tenantContractId, ?string $note = null): self
    {
        return new self(
            tenantContractId: $tenantContractId,
            status: TenantAccrual::CONTRACT_LINK_STATUS_EXACT,
            source: 'contract_external_id',
            note: $note,
        );
    }

    public static function resolved(int $tenantContractId, string $source = 'tenant_space_period', ?string $note = null): self
    {
        return new self(
            tenantContractId: $tenantContractId,
            status: TenantAccrual::CONTRACT_LINK_STATUS_RESOLVED,
            source: $source,
            note: $note,
        );
    }

    public static function unmatched(string $source = 'none', ?string $note = null): self
    {
        return new self(
            tenantContractId: null,
            status: TenantAccrual::CONTRACT_LINK_STATUS_UNMATCHED,
            source: $source,
            note: $note,
        );
    }

    public static function ambiguous(string $source = 'tenant_space_period', ?string $note = null): self
    {
        return new self(
            tenantContractId: null,
            status: TenantAccrual::CONTRACT_LINK_STATUS_AMBIGUOUS,
            source: $source,
            note: $note,
        );
    }

    public function isLinked(): bool
    {
        return $this->tenantContractId !== null;
    }
}
