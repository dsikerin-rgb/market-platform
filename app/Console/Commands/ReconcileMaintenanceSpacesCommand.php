<?php
# app/Console/Commands/ReconcileMaintenanceSpacesCommand.php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MarketSpace;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReconcileMaintenanceSpacesCommand extends Command
{
    protected $signature = 'market:reconcile-maintenance-spaces
        {--market= : Limit audit to one market id}
        {--apply : Persist changes, default mode is dry-run}';

    protected $description = 'Audit and optionally clean maintenance spaces that still keep tenant links or active bindings.';

    public function handle(): int
    {
        $marketId = max(0, (int) ($this->option('market') ?? 0));
        $apply = (bool) $this->option('apply');

        $spaces = $this->loadAnomalousSpaces($marketId);

        if ($spaces->isEmpty()) {
            $this->info('No anomalous maintenance spaces found.');

            return self::SUCCESS;
        }

        $bindingsBySpaceId = $this->loadActiveBindingsBySpace($spaces->pluck('id')->map(fn ($id): int => (int) $id)->all());

        foreach ($spaces as $space) {
            $activeBindings = $bindingsBySpaceId->get((int) $space->id, collect());

            $this->line(json_encode([
                'space_id' => (int) $space->id,
                'market_id' => (int) $space->market_id,
                'number' => (string) ($space->number ?? ''),
                'display_name' => (string) ($space->display_name ?? ''),
                'is_active' => (bool) $space->is_active,
                'tenant_id' => $space->tenant_id ? (int) $space->tenant_id : null,
                'active_bindings' => $activeBindings->map(fn (object $binding): array => [
                    'binding_id' => (int) $binding->id,
                    'tenant_id' => $binding->tenant_id ? (int) $binding->tenant_id : null,
                    'tenant_name' => (string) ($binding->tenant_name ?? ''),
                    'binding_type' => (string) ($binding->binding_type ?? ''),
                    'started_at' => (string) ($binding->started_at ?? ''),
                    'source' => (string) ($binding->source ?? ''),
                ])->values()->all(),
            ], JSON_UNESCAPED_UNICODE));
        }

        if (! $apply) {
            $this->warn('DRY RUN: nothing changed. Re-run with --apply to clean these spaces.');

            return self::SUCCESS;
        }

        $now = now();
        $spaceIds = $spaces->pluck('id')->map(fn ($id): int => (int) $id)->all();

        DB::transaction(function () use ($spaceIds, $now): void {
            DB::table('market_spaces')
                ->whereIn('id', $spaceIds)
                ->whereNotNull('tenant_id')
                ->update([
                    'tenant_id' => null,
                    'updated_at' => $now,
                ]);

            DB::table('market_space_tenant_bindings')
                ->whereIn('market_space_id', $spaceIds)
                ->whereNull('ended_at')
                ->update([
                    'ended_at' => $now,
                    'updated_at' => $now,
                    'resolution_reason' => 'maintenance_space_reconciled',
                ]);
        });

        $this->info(sprintf(
            'Applied cleanup for %d maintenance spaces; closed %d active bindings.',
            count($spaceIds),
            $bindingsBySpaceId->flatten(1)->count()
        ));

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, MarketSpace>
     */
    private function loadAnomalousSpaces(int $marketId): Collection
    {
        return MarketSpace::query()
            ->when($marketId > 0, fn ($query) => $query->where('market_id', $marketId))
            ->where('status', 'maintenance')
            ->where(function ($query): void {
                $query->whereNotNull('tenant_id')
                    ->orWhereExists(function ($sub): void {
                        $sub->selectRaw('1')
                            ->from('market_space_tenant_bindings as b')
                            ->whereColumn('b.market_space_id', 'market_spaces.id')
                            ->whereNull('b.ended_at');
                    });
            })
            ->orderBy('id')
            ->get([
                'id',
                'market_id',
                'number',
                'display_name',
                'status',
                'tenant_id',
                'is_active',
            ]);
    }

    /**
     * @param  list<int>  $spaceIds
     * @return Collection<int, Collection<int, object>>
     */
    private function loadActiveBindingsBySpace(array $spaceIds): Collection
    {
        return DB::table('market_space_tenant_bindings as b')
            ->leftJoin('tenants as t', 't.id', '=', 'b.tenant_id')
            ->whereIn('b.market_space_id', $spaceIds)
            ->whereNull('b.ended_at')
            ->orderBy('b.market_space_id')
            ->orderBy('b.id')
            ->get([
                'b.id',
                'b.market_space_id',
                'b.tenant_id',
                'b.binding_type',
                'b.started_at',
                'b.source',
                't.name as tenant_name',
            ])
            ->groupBy(fn (object $row): int => (int) $row->market_space_id);
    }
}
