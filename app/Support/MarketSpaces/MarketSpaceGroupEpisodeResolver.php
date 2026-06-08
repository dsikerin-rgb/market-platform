<?php
# app/Support/MarketSpaces/MarketSpaceGroupEpisodeResolver.php

declare(strict_types=1);

namespace App\Support\MarketSpaces;

use App\Models\MarketSpace;
use App\Models\MarketSpaceGroupEpisode;
use App\Models\TenantContract;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class MarketSpaceGroupEpisodeResolver
{
    /**
     * @return array{
     *     applies:bool,
     *     source:string,
     *     as_of:?string,
     *     parent:?MarketSpace,
     *     episode:?MarketSpaceGroupEpisode,
     *     children:Collection<int, MarketSpace>,
     *     message:string
     * }
     */
    public function forContract(TenantContract $contract, ?string $documentDate = null): array
    {
        $space = $contract->marketSpace;

        if (! $space instanceof MarketSpace) {
            return $this->empty('Договор не привязан к месту.');
        }

        $asOf = $this->normalizeDate($documentDate);

        if ((string) ($space->space_group_role ?? MarketSpace::SPACE_GROUP_ROLE_NONE) !== MarketSpace::SPACE_GROUP_ROLE_PARENT) {
            $episode = $this->episodeContainingChildAtDate($space, $asOf);

            if ($episode instanceof MarketSpaceGroupEpisode && $episode->parentMarketSpace instanceof MarketSpace) {
                return $this->fromEpisode(
                    $episode->parentMarketSpace,
                    $episode,
                    $asOf,
                    'Показан исторический состав группы: это место входило в группу на дату договора.'
                );
            }
        }

        $parent = $this->resolveParentSpace($space);
        if (! $parent instanceof MarketSpace) {
            return $this->empty('Договор привязан к обычному месту, не к группе.');
        }

        return $this->forParentAtDate($parent, $asOf);
    }

    /**
     * @return array{
     *     applies:bool,
     *     source:string,
     *     as_of:?string,
     *     parent:?MarketSpace,
     *     episode:?MarketSpaceGroupEpisode,
     *     children:Collection<int, MarketSpace>,
     *     message:string
     * }
     */
    public function forParentAtDate(MarketSpace $parent, ?string $date = null): array
    {
        if ((string) ($parent->space_group_role ?? MarketSpace::SPACE_GROUP_ROLE_NONE) !== MarketSpace::SPACE_GROUP_ROLE_PARENT) {
            return $this->empty('Место не является parent-группой.');
        }

        $asOf = $this->normalizeDate($date);

        if (Schema::hasTable('market_space_group_episodes')) {
            $episode = $this->episodeForParentAtDate($parent, $asOf);

            if ($episode instanceof MarketSpaceGroupEpisode) {
                return $this->fromEpisode($parent, $episode, $asOf, 'Показан исторический состав группы на дату договора.');
            }
        }

        $children = $parent->spaceGroupChildren()
            ->where('space_group_role', MarketSpace::SPACE_GROUP_ROLE_CHILD)
            ->orderBy('space_group_slot')
            ->orderBy('number')
            ->get();

        return [
            'applies' => true,
            'source' => 'current',
            'as_of' => $asOf,
            'parent' => $parent,
            'episode' => null,
            'children' => $children,
            'message' => 'Исторический эпизод на эту дату не найден, показан текущий состав группы.',
        ];
    }

    private function resolveParentSpace(MarketSpace $space): ?MarketSpace
    {
        $role = (string) ($space->space_group_role ?? MarketSpace::SPACE_GROUP_ROLE_NONE);

        if ($role === MarketSpace::SPACE_GROUP_ROLE_PARENT) {
            return $space;
        }

        if ($role === MarketSpace::SPACE_GROUP_ROLE_CHILD && filled($space->space_group_parent_id)) {
            return $space->spaceGroupParent;
        }

        return null;
    }

    private function episodeForParentAtDate(MarketSpace $parent, ?string $asOf): ?MarketSpaceGroupEpisode
    {
        $query = MarketSpaceGroupEpisode::query()
            ->with(['children.childMarketSpace'])
            ->where('market_id', (int) $parent->market_id)
            ->where('parent_market_space_id', (int) $parent->id);

        if ($asOf !== null) {
            $query
                ->where(function ($periodStart) use ($asOf): void {
                    $periodStart
                        ->whereNull('valid_from')
                        ->orWhere('valid_from', '<=', $asOf);
                })
                ->where(function ($periodEnd) use ($asOf): void {
                    $periodEnd
                        ->whereNull('valid_to')
                        ->orWhere('valid_to', '>=', $asOf);
                });
        }

        return $query
            ->orderByRaw('valid_from IS NULL')
            ->orderByDesc('valid_from')
            ->orderByDesc('id')
            ->first();
    }

    private function episodeContainingChildAtDate(MarketSpace $child, ?string $asOf): ?MarketSpaceGroupEpisode
    {
        if (! Schema::hasTable('market_space_group_episodes') || ! Schema::hasTable('market_space_group_episode_children')) {
            return null;
        }

        $query = MarketSpaceGroupEpisode::query()
            ->with(['parentMarketSpace', 'children.childMarketSpace'])
            ->where('market_id', (int) $child->market_id)
            ->whereHas('children', function ($children) use ($child): void {
                $children->where('child_market_space_id', (int) $child->id);
            });

        if ($asOf !== null) {
            $query
                ->where(function ($periodStart) use ($asOf): void {
                    $periodStart
                        ->whereNull('valid_from')
                        ->orWhere('valid_from', '<=', $asOf);
                })
                ->where(function ($periodEnd) use ($asOf): void {
                    $periodEnd
                        ->whereNull('valid_to')
                        ->orWhere('valid_to', '>=', $asOf);
                });
        }

        return $query
            ->orderByRaw('valid_from IS NULL')
            ->orderByDesc('valid_from')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return array{
     *     applies:bool,
     *     source:string,
     *     as_of:?string,
     *     parent:?MarketSpace,
     *     episode:?MarketSpaceGroupEpisode,
     *     children:Collection<int, MarketSpace>,
     *     message:string
     * }
     */
    private function fromEpisode(MarketSpace $parent, MarketSpaceGroupEpisode $episode, ?string $asOf, string $message): array
    {
        return [
            'applies' => true,
            'source' => 'episode',
            'as_of' => $asOf,
            'parent' => $parent,
            'episode' => $episode,
            'children' => $episode->children
                ->sortBy([
                    ['sort_order', 'asc'],
                    ['slot', 'asc'],
                    ['id', 'asc'],
                ])
                ->map(fn ($row): ?MarketSpace => $row->childMarketSpace)
                ->filter(fn ($space): bool => $space instanceof MarketSpace)
                ->values(),
            'message' => $message,
        ];
    }

    private function normalizeDate(?string $date): ?string
    {
        $date = trim((string) $date);
        if ($date === '') {
            return null;
        }

        try {
            return Carbon::parse($date)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{
     *     applies:bool,
     *     source:string,
     *     as_of:?string,
     *     parent:?MarketSpace,
     *     episode:?MarketSpaceGroupEpisode,
     *     children:Collection<int, MarketSpace>,
     *     message:string
     * }
     */
    private function empty(string $message): array
    {
        return [
            'applies' => false,
            'source' => 'none',
            'as_of' => null,
            'parent' => null,
            'episode' => null,
            'children' => collect(),
            'message' => $message,
        ];
    }
}
