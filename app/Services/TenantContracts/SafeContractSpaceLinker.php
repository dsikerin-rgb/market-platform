<?php

declare(strict_types=1);

namespace App\Services\TenantContracts;

use App\Models\TenantContract;
use App\Services\TenantAccruals\TenantAccrualContractMatch;
use Illuminate\Support\Facades\DB;

/**
 * Safe auto-linker for tenant_contracts -> market_spaces.
 *
 * Only high-confidence matches:
 * - RULE 1 (BRIDGE): tenant + source_place_code from accruals -> exactly 1 market_space
 * - RULE 2 (NUMBER): contract.number exact/normalized contains exactly 1 space code/number
 *
 * Non-primary contracts are never auto-linked.
 */
class SafeContractSpaceLinker
{
    public function __construct(
        private readonly ContractDocumentClassifier $classifier,
        private readonly ContractNumberSpaceMatcher $numberMatcher,
    ) {
    }

    /**
     * Try to find a safe match for a single contract.
     *
     * @return array{
     *   state: 'matched'|'not_matched',
     *   matched_space_id: ?int,
     *   source: 'bridge'|'number'|'none',
     *   reason: string
     * }
     */
    public function link(TenantContract $contract): array
    {
        // Already linked
        if ($contract->market_space_id !== null) {
            return [
                'state' => 'not_matched',
                'matched_space_id' => null,
                'source' => 'none',
                'reason' => 'already_linked',
            ];
        }

        // Only primary contracts
        $classification = $this->classifier->classify((string) ($contract->number ?? ''));
        $category = $classification['category'] ?? 'unknown';
        if ($category !== 'primary_contract') {
            return [
                'state' => 'not_matched',
                'matched_space_id' => null,
                'source' => 'none',
                'reason' => 'non_primary_excluded',
            ];
        }

        // RULE 1: BRIDGE - check if tenant has exactly 1 primary contract without space
        // and exactly 1 exact place bridge from accruals
        $bridgeResult = $this->tryBridgeLink($contract);
        if ($bridgeResult['state'] === 'matched') {
            return $bridgeResult;
        }

        // RULE 2: NUMBER - exact/normalized match
        $numberResult = $this->tryNumberLink($contract);
        if ($numberResult['state'] === 'matched') {
            return $numberResult;
        }

        // No safe match
        return [
            'state' => 'not_matched',
            'matched_space_id' => null,
            'source' => 'none',
            'reason' => $bridgeResult['reason'] ?? $numberResult['reason'] ?? 'no_safe_match',
        ];
    }

    /**
     * RULE 1: BRIDGE link.
     *
     * Conditions:
     * - tenant has exactly 1 primary contract without market_space_id
     * - tenant has exactly 1 exact place bridge from accruals (source_place_code -> market_space)
     */
    private function tryBridgeLink(TenantContract $contract): array
    {
        $tenantId = (int) $contract->tenant_id;
        $marketId = (int) $contract->market_id;

        // Check if tenant has exactly 1 primary contract without space
        $primaryContracts = DB::table('tenant_contracts')
            ->where('market_id', $marketId)
            ->where('tenant_id', $tenantId)
            ->whereNull('market_space_id')
            ->get(['id', 'number']);

        $primaryCount = 0;
        foreach ($primaryContracts as $c) {
            $classification = $this->classifier->classify((string) ($c->number ?? ''));
            $category = $classification['category'] ?? 'unknown';
            if ($category === 'primary_contract') {
                $primaryCount++;
            }
        }

        if ($primaryCount !== 1) {
            return [
                'state' => 'not_matched',
                'matched_space_id' => null,
                'source' => 'none',
                'reason' => 'bridge_multi_primary',
            ];
        }

        // Check for exact place bridge from accruals
        $accrualPairs = DB::table('tenant_accruals')
            ->where('market_id', $marketId)
            ->where('tenant_id', $tenantId)
            ->whereNotNull('source_place_code')
            ->where('source_place_code', '!=', '')
            ->distinct()
            ->pluck('source_place_code');

        $matchedSpaces = [];
        foreach ($accrualPairs as $code) {
            $spaces = DB::table('market_spaces')
                ->where('market_id', $marketId)
                ->where('tenant_id', $tenantId)
                ->where(function ($q) use ($code) {
                    $q->where('code', $code)
                      ->orWhere('number', $code);
                })
                ->pluck('id');

            if ($spaces->count() === 1) {
                $matchedSpaces[$spaces->first()] = true;
            }
        }

        if (count($matchedSpaces) === 1) {
            return [
                'state' => 'matched',
                'matched_space_id' => array_key_first($matchedSpaces),
                'source' => 'bridge',
                'reason' => 'safe_bridge_one_primary_one_place',
            ];
        }

        if (count($matchedSpaces) > 1) {
            return [
                'state' => 'not_matched',
                'matched_space_id' => null,
                'source' => 'none',
                'reason' => 'bridge_multi_place',
            ];
        }

        return [
            'state' => 'not_matched',
            'matched_space_id' => null,
            'source' => 'none',
            'reason' => 'bridge_no_place',
        ];
    }

    /**
     * RULE 2: NUMBER exact/normalized link.
     *
     * Conditions:
     * - contract.number contains exactly 1 market_space code/number (exact or normalized)
     */
    private function tryNumberLink(TenantContract $contract): array
    {
        $tenantId = (int) $contract->tenant_id;
        $marketId = (int) $contract->market_id;

        // Get all spaces for this tenant
        $spaces = DB::table('market_spaces')
            ->where('market_id', $marketId)
            ->where('tenant_id', $tenantId)
            ->get(['id', 'code', 'number']);

        if ($spaces->isEmpty()) {
            return [
                'state' => 'not_matched',
                'matched_space_id' => null,
                'source' => 'none',
                'reason' => 'number_no_tenant_spaces',
            ];
        }

        // Use the existing ContractNumberSpaceMatcher
        $matchResult = $this->numberMatcher->match((string) ($contract->number ?? ''), $spaces->all());

        if ($matchResult['state'] === 'ok') {
            return [
                'state' => 'matched',
                'matched_space_id' => $matchResult['market_space_id'],
                'source' => 'number',
                'reason' => 'safe_number_exact_match',
            ];
        }

        if ($matchResult['state'] === 'ambiguous') {
            return [
                'state' => 'not_matched',
                'matched_space_id' => null,
                'source' => 'none',
                'reason' => 'number_ambiguous',
            ];
        }

        return [
            'state' => 'not_matched',
            'matched_space_id' => null,
            'source' => 'none',
            'reason' => 'number_no_match',
        ];
    }

    /**
     * Apply safe link to a contract.
     *
     * @param array{state: 'matched'|'not_matched', matched_space_id: ?int, source: string, reason: string} $result
     * @return bool true if linked
     */
    public function apply(TenantContract $contract, array $result): bool
    {
        if ($result['state'] !== 'matched' || $result['matched_space_id'] === null) {
            return false;
        }

        $contract->market_space_id = $result['matched_space_id'];

        // Fill space mapping fields if they exist in the table
        $table = $contract->getTable();
        
        if (\Illuminate\Support\Facades\Schema::hasColumn($table, 'space_mapping_mode')) {
            $contract->space_mapping_mode = 'auto_' . $result['source'];
        }

        if (\Illuminate\Support\Facades\Schema::hasColumn($table, 'space_mapping_updated_at')) {
            $contract->space_mapping_updated_at = now();
        }

        if (\Illuminate\Support\Facades\Schema::hasColumn($table, 'space_mapping_updated_by_user_id')) {
            $contract->space_mapping_updated_by_user_id = null;
        }

        $contract->save();

        return true;
    }
}
