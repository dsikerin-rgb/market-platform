<?php

namespace App\Console\Commands;

use App\Models\TenantContract;
use App\Services\TenantContracts\SafeContractSpaceLinker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class LinkContractSpacesCommand extends Command
{
    protected $signature = 'contracts:link-spaces
        {--market= : Market ID (default: all)}
        {--limit=0 : Limit rows for testing}
        {--apply : Actually apply the links (default: preview only)}';

    protected $description = 'Preview or apply safe auto-linking of tenant_contracts to market_spaces';

    public function handle(SafeContractSpaceLinker $linker): int
    {
        $marketId = $this->option('market') ? (int) $this->option('market') : null;
        $limit = max(0, (int) $this->option('limit'));
        $apply = (bool) $this->option('apply');

        $query = TenantContract::query()
            ->whereNull('market_space_id');

        if ($marketId) {
            $query->where('market_id', $marketId);
        }

        if ($limit > 0) {
            $query->limit($limit);
        }

        $contracts = $query->get();

        $stats = [
            'total' => $contracts->count(),
            'linked_by_bridge' => 0,
            'linked_by_number' => 0,
            'skipped_non_primary' => 0,
            'skipped_ambiguous' => 0,
            'skipped_no_match' => 0,
            'skipped_already_linked' => 0,
            'skipped_multi_primary' => 0,
            'skipped_multi_place' => 0,
        ];

        $matchedSamples = [];
        $skippedSamples = [];
        $matchedSampleLimit = 30;
        $skippedSampleLimit = 30;

        foreach ($contracts as $contract) {
            $result = $linker->link($contract);

            $sample = [
                'contract_id' => (int) $contract->id,
                'tenant_id' => (int) $contract->tenant_id,
                'contract_number' => $contract->number,
                'state' => $result['state'],
                'matched_space_id' => $result['matched_space_id'],
                'source' => $result['source'],
                'reason' => $result['reason'],
            ];

            if ($result['state'] === 'matched') {
                if ($result['source'] === 'bridge') {
                    $stats['linked_by_bridge']++;
                } elseif ($result['source'] === 'number') {
                    $stats['linked_by_number']++;
                }

                if ($apply) {
                    $linked = $linker->apply($contract, $result);
                    $sample['applied'] = $linked;
                } else {
                    $sample['applied'] = null;
                }

                if (count($matchedSamples) < $matchedSampleLimit) {
                    $matchedSamples[] = $sample;
                }
            } else {
                // Count skip reasons
                $reason = $result['reason'];
                if ($reason === 'non_primary_excluded') {
                    $stats['skipped_non_primary']++;
                } elseif ($reason === 'already_linked') {
                    $stats['skipped_already_linked']++;
                } elseif ($reason === 'bridge_multi_primary') {
                    $stats['skipped_multi_primary']++;
                } elseif ($reason === 'bridge_multi_place') {
                    $stats['skipped_multi_place']++;
                } elseif ($reason === 'number_ambiguous') {
                    $stats['skipped_ambiguous']++;
                } else {
                    $stats['skipped_no_match']++;
                }

                if (count($skippedSamples) < $skippedSampleLimit && in_array($reason, ['non_primary_excluded', 'bridge_multi_primary', 'number_ambiguous'])) {
                    $skippedSamples[] = $sample;
                }
            }
        }

        $this->output->writeln('');
        $this->output->writeln('=== SAFE CONTRACT-SPACE LINKING ===');
        $this->output->writeln('');
        $this->output->writeln('Mode: ' . ($apply ? 'APPLY' : 'PREVIEW'));
        $this->output->writeln('');
        $this->output->writeln('## Stats');
        $this->output->writeln('total_contracts=' . $stats['total']);
        $this->output->writeln('linked_by_bridge=' . $stats['linked_by_bridge']);
        $this->output->writeln('linked_by_number=' . $stats['linked_by_number']);
        $this->output->writeln('skipped_non_primary=' . $stats['skipped_non_primary']);
        $this->output->writeln('skipped_ambiguous=' . $stats['skipped_ambiguous']);
        $this->output->writeln('skipped_no_match=' . $stats['skipped_no_match']);
        $this->output->writeln('skipped_multi_primary=' . $stats['skipped_multi_primary']);
        $this->output->writeln('skipped_multi_place=' . $stats['skipped_multi_place']);
        $this->output->writeln('skipped_already_linked=' . $stats['skipped_already_linked']);
        $this->output->writeln('');

        $totalLinked = $stats['linked_by_bridge'] + $stats['linked_by_number'];
        if ($totalLinked > 0) {
            $this->output->writeln('## Sample Links');
            foreach ($matchedSamples as $sample) {
                $this->output->writeln(json_encode($sample, JSON_UNESCAPED_UNICODE));
            }
            $this->output->writeln('');
        }

        if ($stats['skipped_non_primary'] > 0 || $stats['skipped_ambiguous'] > 0 || $stats['skipped_multi_primary'] > 0) {
            $this->output->writeln('## Sample Skipped');
            foreach ($skippedSamples as $sample) {
                $this->output->writeln(json_encode($sample, JSON_UNESCAPED_UNICODE));
            }
            $this->output->writeln('');
        }

        if ($apply && $totalLinked > 0) {
            $this->output->writeln('## Result');
            $this->output->writeln('Successfully linked ' . $totalLinked . ' contracts to market_spaces.');
        } elseif (!$apply && $totalLinked > 0) {
            $this->output->writeln('## Next Step');
            $this->output->writeln('Run with --apply to actually link ' . $totalLinked . ' contracts.');
        }

        return self::SUCCESS;
    }
}
