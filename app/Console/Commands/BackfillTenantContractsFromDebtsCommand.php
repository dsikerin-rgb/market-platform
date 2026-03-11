<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\TenantContract;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BackfillTenantContractsFromDebtsCommand extends Command
{
    protected $signature = 'contracts:backfill-from-debts
        {--market=1 : Market ID}
        {--contract-external-id= : Backfill only one contract_external_id}
        {--execute : Persist created tenant_contract rows}
        {--include-test : Include TEST_*/staging-check-* external ids}';

    protected $description = 'Create placeholder tenant_contracts for debt rows that have a live tenant but no contract card.';

    public function handle(): int
    {
        $marketId = max(1, (int) $this->option('market'));
        $execute = (bool) $this->option('execute');
        $includeTest = (bool) $this->option('include-test');
        $targetContractExternalId = trim((string) ($this->option('contract-external-id') ?? ''));

        $candidates = $this->collectCandidates($marketId, $includeTest, $targetContractExternalId);

        $stats = [
            'market_id' => $marketId,
            'mode' => $execute ? 'execute' : 'dry-run',
            'candidates' => $candidates->count(),
            'created' => 0,
            'skipped' => 0,
        ];

        $samples = [];

        foreach ($candidates as $candidate) {
            $sample = [
                'tenant_id' => (int) $candidate->tenant_id,
                'tenant_name' => (string) $candidate->tenant_name,
                'tenant_external_id' => (string) $candidate->tenant_external_id,
                'contract_external_id' => (string) $candidate->contract_external_id,
                'number' => $this->makePlaceholderNumber((string) $candidate->contract_external_id),
                'starts_at' => (string) $candidate->first_period_start,
                'source' => 'contract_debts',
            ];

            if (! $execute) {
                $samples[] = $sample;
                continue;
            }

            $exists = TenantContract::query()
                ->where('market_id', $marketId)
                ->where('external_id', (string) $candidate->contract_external_id)
                ->exists();

            if ($exists) {
                $stats['skipped']++;
                continue;
            }

            $contract = new TenantContract();
            $contract->market_id = $marketId;
            $contract->tenant_id = (int) $candidate->tenant_id;
            $contract->external_id = (string) $candidate->contract_external_id;
            $contract->number = $this->makePlaceholderNumber((string) $candidate->contract_external_id);
            $contract->status = 'active';
            $contract->starts_at = CarbonImmutable::parse((string) $candidate->first_period_start)->toDateString();
            $contract->ends_at = null;
            $contract->signed_at = null;
            $contract->monthly_rent = null;
            $contract->currency = null;
            $contract->is_active = true;
            $contract->notes = 'Создано автоматически из выгрузки задолженности 1С: карточка договора не пришла в import contracts.';
            $contract->space_mapping_mode = TenantContract::SPACE_MAPPING_MODE_AUTO;
            $contract->save();

            $stats['created']++;
            $samples[] = $sample + ['tenant_contract_id' => (int) $contract->id];
        }

        $this->line(json_encode([
            'stats' => $stats,
            'samples' => $samples,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, object>
     */
    private function collectCandidates(int $marketId, bool $includeTest, string $targetContractExternalId): Collection
    {
        $rows = DB::table('contract_debts as cd')
            ->join('tenants as t', function ($join) use ($marketId): void {
                $join->on('t.external_id', '=', 'cd.tenant_external_id')
                    ->where('t.market_id', '=', $marketId);
            })
            ->leftJoin('tenant_contracts as tc', function ($join) use ($marketId): void {
                $join->on('tc.external_id', '=', 'cd.contract_external_id')
                    ->where('tc.market_id', '=', $marketId);
            })
            ->whereNull('tc.id')
            ->whereNotNull('cd.contract_external_id')
            ->where('cd.contract_external_id', '<>', '')
            ->when($targetContractExternalId !== '', function ($query) use ($targetContractExternalId): void {
                $query->where('cd.contract_external_id', $targetContractExternalId);
            })
            ->selectRaw("
                t.id as tenant_id,
                t.name as tenant_name,
                cd.tenant_external_id,
                cd.contract_external_id,
                MIN(to_date(cd.period || '-01', 'YYYY-MM-DD')) as first_period_start
            ")
            ->groupBy('t.id', 't.name', 'cd.tenant_external_id', 'cd.contract_external_id')
            ->orderBy('t.id')
            ->orderBy('cd.contract_external_id')
            ->get();

        return $rows
            ->filter(function (object $row) use ($includeTest): bool {
                if ($includeTest) {
                    return true;
                }

                return ! $this->looksSyntheticExternalId((string) $row->tenant_external_id)
                    && ! $this->looksSyntheticExternalId((string) $row->contract_external_id);
            })
            ->values();
    }

    private function looksSyntheticExternalId(string $externalId): bool
    {
        $value = trim($externalId);

        return str_starts_with($value, 'TEST_')
            || str_starts_with($value, 'TEST-')
            || str_starts_with($value, 'staging-check-');
    }

    private function makePlaceholderNumber(string $contractExternalId): string
    {
        return mb_substr('[1С долг] ' . $contractExternalId, 0, 50);
    }
}
