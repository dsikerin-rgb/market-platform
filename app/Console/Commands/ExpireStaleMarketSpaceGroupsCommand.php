<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MarketSpace;
use App\Models\MarketSpaceGroupEpisode;
use App\Support\TenantContracts\TenantContractOperationalActivity;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ExpireStaleMarketSpaceGroupsCommand extends Command
{
    protected $signature = 'market-spaces:expire-stale-groups
        {--market= : Limit to one market_id}
        {--effective-date= : Date when groups stop being current, YYYY-MM-DD. Defaults to today}
        {--months=2 : How many months to look back when warning about recent financial activity}
        {--limit=50 : How many preview rows to show}
        {--apply : Persist changes instead of showing a dry run}';

    protected $description = 'Expire vacant parent groups; parent groups are operational only while they have a tenant.';

    public function __construct(
        private readonly TenantContractOperationalActivity $operationalActivity,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $marketId = $this->option('market') ? (int) $this->option('market') : null;
        $effectiveDate = $this->resolveEffectiveDate();
        if (! $effectiveDate instanceof CarbonImmutable) {
            return self::INVALID;
        }

        $months = max(0, (int) ($this->option('months') ?? TenantContractOperationalActivity::DEFAULT_STALE_AFTER_MONTHS_WITHOUT_ACCRUALS));
        $limit = max(1, (int) ($this->option('limit') ?? 50));
        $apply = (bool) $this->option('apply');

        $stats = [
            'mode' => $apply ? 'apply' : 'dry-run',
            'market_id' => $marketId,
            'effective_date' => $effectiveDate->toDateString(),
            'recent_activity_lookback_months' => $months,
            'parents_checked' => 0,
            'parents_without_children' => 0,
            'parents_without_accrual_baseline' => 0,
            'parents_with_recent_activity_warning' => 0,
            'parents_to_expire' => 0,
            'parents_expired' => 0,
            'children_detached' => 0,
            'bindings_closed' => 0,
            'episodes_created' => 0,
            'episodes_closed' => 0,
        ];
        $samples = [];

        $query = MarketSpace::query()
            ->where('space_group_role', MarketSpace::SPACE_GROUP_ROLE_PARENT)
            ->where('is_active', true)
            ->whereNull('tenant_id')
            ->when($marketId !== null, fn ($query) => $query->where('market_id', $marketId))
            ->orderBy('market_id')
            ->orderBy('id');

        $query->chunkById(100, function ($parents) use (
            $effectiveDate,
            $months,
            $limit,
            $apply,
            &$stats,
            &$samples,
        ): void {
            foreach ($parents as $parent) {
                if (! $parent instanceof MarketSpace) {
                    continue;
                }

                $stats['parents_checked']++;

                $children = $this->currentChildren($parent);
                if ($children->isEmpty()) {
                    $stats['parents_without_children']++;

                    continue;
                }

                $hasRecentFinancialActivity = false;
                $cutoff = $this->operationalActivity->recentAccrualCutoffPeriod((int) $parent->market_id, $months);
                if (! $cutoff instanceof CarbonImmutable) {
                    $stats['parents_without_accrual_baseline']++;
                } else {
                    $hasRecentFinancialActivity = $this->parentHasRecentFinancialActivity($parent, $cutoff, $months);
                    if ($hasRecentFinancialActivity) {
                        $stats['parents_with_recent_activity_warning']++;
                    }
                }

                $stats['parents_to_expire']++;

                if (count($samples) < $limit) {
                    $samples[] = [
                        'parent_id' => (int) $parent->id,
                        'parent' => $this->spaceLabel($parent),
                        'market_id' => (int) $parent->market_id,
                        'has_recent_financial_activity_warning' => $hasRecentFinancialActivity,
                        'children' => $children
                            ->map(fn (MarketSpace $child): string => $this->spaceLabel($child))
                            ->implode(', '),
                    ];
                }

                if (! $apply) {
                    continue;
                }

                $result = $this->expireParentGroup((int) $parent->id, $effectiveDate);
                $stats['parents_expired'] += $result['parent_expired'];
                $stats['children_detached'] += $result['children_detached'];
                $stats['bindings_closed'] += $result['bindings_closed'];
                $stats['episodes_created'] += $result['episodes_created'];
                $stats['episodes_closed'] += $result['episodes_closed'];
            }
        });

        $this->line(json_encode([
            'stats' => $stats,
            'samples' => $samples,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        if (! $apply) {
            $this->newLine();
            $this->line('Run with --apply to expire these parent groups.');
        }

        return self::SUCCESS;
    }

    private function resolveEffectiveDate(): ?CarbonImmutable
    {
        $raw = trim((string) ($this->option('effective-date') ?? ''));

        try {
            return ($raw !== ''
                ? CarbonImmutable::parse($raw)
                : CarbonImmutable::today()
            )->startOfDay();
        } catch (\Throwable) {
            $this->error('Invalid --effective-date value. Use YYYY-MM-DD.');

            return null;
        }
    }

    /**
     * @return Collection<int, MarketSpace>
     */
    private function currentChildren(MarketSpace $parent): Collection
    {
        return $parent->spaceGroupChildren()
            ->where('space_group_role', MarketSpace::SPACE_GROUP_ROLE_CHILD)
            ->where('is_active', true)
            ->orderBy('space_group_slot')
            ->orderBy('number')
            ->orderBy('id')
            ->get();
    }

    private function parentHasRecentFinancialActivity(
        MarketSpace $parent,
        CarbonImmutable $cutoff,
        int $months,
    ): bool {
        if ($this->parentHasRecentAccrual((int) $parent->id, (int) $parent->market_id, $cutoff)) {
            return true;
        }

        $contracts = $parent->tenantContracts()
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->get();

        foreach ($contracts as $contract) {
            if ($this->operationalActivity->isOperationalForCurrentMap($contract, $months)) {
                return true;
            }
        }

        return false;
    }

    private function parentHasRecentAccrual(int $parentId, int $marketId, CarbonImmutable $cutoff): bool
    {
        if (
            ! Schema::hasTable('tenant_accruals')
            || ! Schema::hasColumn('tenant_accruals', 'market_space_id')
            || ! Schema::hasColumn('tenant_accruals', 'period')
        ) {
            return false;
        }

        return DB::table('tenant_accruals')
            ->where('market_id', $marketId)
            ->where('market_space_id', $parentId)
            ->whereDate('period', '>=', $cutoff->toDateString())
            ->exists();
    }

    /**
     * @return array{parent_expired:int,children_detached:int,bindings_closed:int,episodes_created:int,episodes_closed:int}
     */
    private function expireParentGroup(int $parentId, CarbonImmutable $effectiveDate): array
    {
        return DB::transaction(function () use ($parentId, $effectiveDate): array {
            $parent = MarketSpace::query()
                ->whereKey($parentId)
                ->lockForUpdate()
                ->first();

            if (! $parent instanceof MarketSpace) {
                return $this->emptyApplyResult();
            }

            if (
                (string) ($parent->space_group_role ?? MarketSpace::SPACE_GROUP_ROLE_NONE) !== MarketSpace::SPACE_GROUP_ROLE_PARENT
                || ! (bool) $parent->is_active
                || filled($parent->tenant_id)
            ) {
                return $this->emptyApplyResult();
            }

            $children = $this->currentChildren($parent);
            if ($children->isEmpty()) {
                return $this->emptyApplyResult();
            }

            $episodeStats = $this->snapshotAndCloseGroupEpisode($parent, $children, $effectiveDate);
            $bindingsClosed = $this->closeParentBindings($parent, $effectiveDate);
            $childrenDetached = $this->detachChildren($children);

            $parent->forceFill([
                'tenant_id' => null,
                'status' => 'vacant',
                'is_active' => false,
                'notes' => $this->appendNote(
                    (string) ($parent->notes ?? ''),
                    'Expired as vacant parent group on '.$effectiveDate->toDateString().'.',
                ),
            ])->save();

            return [
                'parent_expired' => 1,
                'children_detached' => $childrenDetached,
                'bindings_closed' => $bindingsClosed,
                'episodes_created' => $episodeStats['created'],
                'episodes_closed' => $episodeStats['closed'],
            ];
        });
    }

    /**
     * @param  Collection<int, MarketSpace>  $children
     * @return array{created:int,closed:int}
     */
    private function snapshotAndCloseGroupEpisode(
        MarketSpace $parent,
        Collection $children,
        CarbonImmutable $effectiveDate,
    ): array {
        if (! Schema::hasTable('market_space_group_episodes') || ! Schema::hasTable('market_space_group_episode_children')) {
            return ['created' => 0, 'closed' => 0];
        }

        $validTo = $effectiveDate->subDay()->toDateString();
        $closed = 0;

        $openEpisodes = MarketSpaceGroupEpisode::query()
            ->where('market_id', (int) $parent->market_id)
            ->where('parent_market_space_id', (int) $parent->id)
            ->where(function ($query) use ($effectiveDate): void {
                $query
                    ->whereNull('valid_to')
                    ->orWhere('valid_to', '>=', $effectiveDate->toDateString());
            })
            ->get();

        foreach ($openEpisodes as $episode) {
            if ($episode->valid_from && $episode->valid_from->greaterThan($effectiveDate)) {
                continue;
            }

            $episode->forceFill(['valid_to' => $validTo])->save();
            $closed++;
        }

        if ($openEpisodes->isNotEmpty()) {
            return ['created' => 0, 'closed' => $closed];
        }

        $episode = MarketSpaceGroupEpisode::query()->create([
            'market_id' => (int) $parent->market_id,
            'parent_market_space_id' => (int) $parent->id,
            'valid_from' => null,
            'valid_to' => $validTo,
            'source' => 'stale_group_expire',
            'notes' => 'Snapshot before expiring vacant parent group.',
            'meta' => [
                'command' => 'market-spaces:expire-stale-groups',
                'effective_date' => $effectiveDate->toDateString(),
                'generated_at' => now()->toIso8601String(),
                'child_count' => $children->count(),
            ],
        ]);

        foreach ($children->values() as $index => $child) {
            $episode->children()->create([
                'child_market_space_id' => (int) $child->id,
                'slot' => $child->space_group_slot,
                'sort_order' => $index + 1,
                'area_sqm' => $child->area_sqm,
                'meta' => [
                    'child_number' => $child->number,
                    'child_code' => $child->code,
                ],
            ]);
        }

        return ['created' => 1, 'closed' => $closed];
    }

    private function closeParentBindings(MarketSpace $parent, CarbonImmutable $effectiveDate): int
    {
        if (! Schema::hasTable('market_space_tenant_bindings')) {
            return 0;
        }

        $endedAt = $effectiveDate->startOfDay();

        return DB::table('market_space_tenant_bindings')
            ->where('market_space_id', (int) $parent->id)
            ->whereNull('ended_at')
            ->where('binding_type', '!=', 'shared_use')
            ->update([
                'ended_at' => $endedAt,
                'updated_at' => now(),
                'resolution_reason' => 'vacant_parent_group_expired',
            ]);
    }

    /**
     * @param  Collection<int, MarketSpace>  $children
     */
    private function detachChildren(Collection $children): int
    {
        $count = 0;

        foreach ($children as $child) {
            if (! $child instanceof MarketSpace) {
                continue;
            }

            $status = filled($child->tenant_id) ? 'occupied' : 'vacant';

            $child->forceFill([
                'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_NONE,
                'space_group_parent_id' => null,
                'space_group_slot' => null,
                'space_group_token' => null,
                'status' => $status,
            ])->save();

            $count++;
        }

        return $count;
    }

    /**
     * @return array{parent_expired:int,children_detached:int,bindings_closed:int,episodes_created:int,episodes_closed:int}
     */
    private function emptyApplyResult(): array
    {
        return [
            'parent_expired' => 0,
            'children_detached' => 0,
            'bindings_closed' => 0,
            'episodes_created' => 0,
            'episodes_closed' => 0,
        ];
    }

    private function appendNote(string $notes, string $line): string
    {
        $notes = trim($notes);
        if ($notes === '') {
            return $line;
        }

        if (str_contains($notes, $line)) {
            return $notes;
        }

        return $notes.PHP_EOL.$line;
    }

    private function spaceLabel(MarketSpace $space): string
    {
        $label = trim((string) ($space->display_name ?: $space->number ?: $space->code));

        return $label !== '' ? $label : ('#'.(int) $space->id);
    }
}
