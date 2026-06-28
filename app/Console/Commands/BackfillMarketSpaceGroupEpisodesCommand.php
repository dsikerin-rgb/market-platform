<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MarketSpace;
use App\Models\MarketSpaceGroupEpisode;
use App\Support\MarketContext;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class BackfillMarketSpaceGroupEpisodesCommand extends Command
{
    protected $signature = 'market-spaces:backfill-group-episodes
        {--market= : Limit to one market_id}
        {--valid-from= : Episode start date, YYYY-MM-DD. Defaults to today}
        {--limit=30 : How many preview rows to show}
        {--include-inactive : Include inactive parent and child spaces}
        {--apply : Persist episodes instead of showing a dry run}';

    protected $description = 'Create initial open group episodes from the current confirmed parent-child market space links.';

    public function handle(): int
    {
        $marketId = $this->option('market') ? (int) $this->option('market') : null;
        $validFrom = $this->resolveValidFrom();
        if (! $validFrom instanceof Carbon) {
            return self::INVALID;
        }
        $previewLimit = max(1, (int) $this->option('limit'));
        $includeInactive = (bool) $this->option('include-inactive');
        $apply = (bool) $this->option('apply');

        if ($marketId !== null) {
            return app(MarketContext::class)->withMarket(
                $marketId,
                fn (): int => $this->backfillGroupEpisodes($marketId, $validFrom, $previewLimit, $includeInactive, $apply),
            );
        }

        return $this->backfillGroupEpisodes(null, $validFrom, $previewLimit, $includeInactive, $apply);
    }

    private function backfillGroupEpisodes(
        ?int $marketId,
        Carbon $validFrom,
        int $previewLimit,
        bool $includeInactive,
        bool $apply,
    ): int {
        $stats = [
            'mode' => $apply ? 'apply' : 'dry-run',
            'market_id' => $marketId,
            'valid_from' => $validFrom->toDateString(),
            'parents_checked' => 0,
            'parents_without_children' => 0,
            'parents_with_existing_episode_at_date' => 0,
            'episodes_to_create' => 0,
            'episodes_created' => 0,
            'children_to_snapshot' => 0,
            'children_snapshotted' => 0,
        ];
        $samples = [];

        $query = MarketSpace::query()
            ->where('space_group_role', MarketSpace::SPACE_GROUP_ROLE_PARENT)
            ->when($marketId !== null, fn ($query) => $query->where('market_id', $marketId))
            ->when(! $includeInactive, fn ($query) => $query->where('is_active', true))
            ->orderBy('id');

        $query->chunkById(100, function ($parents) use (
            $validFrom,
            $previewLimit,
            $includeInactive,
            $apply,
            &$stats,
            &$samples,
        ): void {
            foreach ($parents as $parent) {
                if (! $parent instanceof MarketSpace) {
                    continue;
                }

                $stats['parents_checked']++;

                $children = $this->currentChildren($parent, $includeInactive);
                if ($children->isEmpty()) {
                    $stats['parents_without_children']++;

                    continue;
                }

                if ($this->hasEpisodeAtDate($parent, $validFrom)) {
                    $stats['parents_with_existing_episode_at_date']++;

                    continue;
                }

                $stats['episodes_to_create']++;
                $stats['children_to_snapshot'] += $children->count();

                if (count($samples) < $previewLimit) {
                    $samples[] = [
                        'parent_id' => (int) $parent->id,
                        'parent' => $this->spaceLabel($parent),
                        'children' => $children
                            ->map(fn (MarketSpace $child): string => $this->spaceLabel($child))
                            ->implode(', '),
                    ];
                }

                if (! $apply) {
                    continue;
                }

                DB::transaction(function () use ($parent, $children, $validFrom, &$stats): void {
                    if ($this->hasEpisodeAtDate($parent, $validFrom)) {
                        $stats['parents_with_existing_episode_at_date']++;
                        $stats['episodes_to_create']--;
                        $stats['children_to_snapshot'] -= $children->count();

                        return;
                    }

                    $episode = MarketSpaceGroupEpisode::query()->create([
                        'market_id' => (int) $parent->market_id,
                        'parent_market_space_id' => (int) $parent->id,
                        'valid_from' => $validFrom->toDateString(),
                        'valid_to' => null,
                        'source' => 'backfill_current',
                        'notes' => 'Initial snapshot from current parent-child group links.',
                        'meta' => [
                            'command' => 'market-spaces:backfill-group-episodes',
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

                    $stats['episodes_created']++;
                    $stats['children_snapshotted'] += $children->count();
                });
            }
        });

        $this->line(json_encode([
            'stats' => $stats,
            'samples' => $samples,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        if (! $apply) {
            $this->newLine();
            $this->line('Run with --apply to create these initial group episodes.');
        }

        return self::SUCCESS;
    }

    private function resolveValidFrom(): ?Carbon
    {
        $raw = trim((string) ($this->option('valid-from') ?? ''));
        if ($raw === '') {
            return today();
        }

        try {
            return Carbon::parse($raw)->startOfDay();
        } catch (\Throwable) {
            $this->error('Invalid --valid-from value. Use YYYY-MM-DD.');

            return null;
        }
    }

    /**
     * @return \Illuminate\Support\Collection<int, MarketSpace>
     */
    private function currentChildren(MarketSpace $parent, bool $includeInactive): \Illuminate\Support\Collection
    {
        return $parent->spaceGroupChildren()
            ->where('space_group_role', MarketSpace::SPACE_GROUP_ROLE_CHILD)
            ->when(! $includeInactive, fn ($query) => $query->where('is_active', true))
            ->orderBy('space_group_slot')
            ->orderBy('number')
            ->orderBy('id')
            ->get();
    }

    private function hasEpisodeAtDate(MarketSpace $parent, Carbon $date): bool
    {
        return MarketSpaceGroupEpisode::query()
            ->where('market_id', (int) $parent->market_id)
            ->where('parent_market_space_id', (int) $parent->id)
            ->where(function ($query) use ($date): void {
                $query
                    ->whereNull('valid_from')
                    ->orWhere('valid_from', '<=', $date->toDateString());
            })
            ->where(function ($query) use ($date): void {
                $query
                    ->whereNull('valid_to')
                    ->orWhere('valid_to', '>=', $date->toDateString());
            })
            ->exists();
    }

    private function spaceLabel(MarketSpace $space): string
    {
        $label = trim((string) ($space->display_name ?: $space->number ?: $space->code));

        return $label !== '' ? $label : ('#'.(int) $space->id);
    }
}
