<?php

declare(strict_types=1);

namespace App\Http\Controllers\Marketplace;

use App\Models\MarketSpace;
use App\Models\MarketSpaceMapShape;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class MapController extends BaseMarketplaceController
{
    public function __invoke(Request $request, string $marketSlug): View
    {
        $market = $this->resolveMarketOrFail($marketSlug);

        $version = (int) $request->integer('version', 1);
        $page = (int) $request->integer('page', 1);

        /** @var Collection<int, MarketSpaceMapShape> $shapes */
        $shapes = collect();
        if (Schema::hasTable('market_space_map_shapes')) {
            $shapes = MarketSpaceMapShape::query()
                ->where('market_id', (int) $market->id)
                ->where('is_active', true)
                ->where('version', $version)
                ->where('page', $page)
                ->whereNotNull('market_space_id')
                ->with(['marketSpace:id,tenant_id,display_name,number,code,status', 'marketSpace.tenant:id,name,short_name,slug'])
                ->orderBy('sort_order')
                ->limit(4000)
                ->get();
        }

        if ($shapes->isEmpty()) {
            $fallbackVersion = 1;
            $fallbackPage = 1;
            if (Schema::hasTable('market_space_map_shapes')) {
                $fallbackVersion = (int) (MarketSpaceMapShape::query()
                    ->where('market_id', (int) $market->id)
                    ->where('is_active', true)
                    ->max('version') ?? 1);
                $fallbackPage = (int) (MarketSpaceMapShape::query()
                    ->where('market_id', (int) $market->id)
                    ->where('is_active', true)
                    ->where('version', $fallbackVersion)
                    ->max('page') ?? 1);
            }

            $version = $fallbackVersion;
            $page = $fallbackPage;

            if (Schema::hasTable('market_space_map_shapes')) {
                $shapes = MarketSpaceMapShape::query()
                    ->where('market_id', (int) $market->id)
                    ->where('is_active', true)
                    ->where('version', $version)
                    ->where('page', $page)
                    ->whereNotNull('market_space_id')
                    ->with(['marketSpace:id,tenant_id,display_name,number,code,status', 'marketSpace.tenant:id,name,short_name,slug'])
                    ->orderBy('sort_order')
                    ->limit(4000)
                    ->get();
            }
        }

        $spaces = collect();
        if (Schema::hasTable('market_spaces')) {
            $spaces = MarketSpace::query()
                ->where('market_id', (int) $market->id)
                ->with(['tenant:id,name,short_name,slug'])
                ->orderByRaw('COALESCE(code, number, display_name) asc')
                ->limit(2000)
                ->get(['id', 'tenant_id', 'display_name', 'number', 'code', 'status']);
        }

        $mapShapes = $shapes->map(static function (MarketSpaceMapShape $shape): array {
            $tenant = $shape->marketSpace?->tenant;
            $tenantRouteKey = filled($tenant?->slug ?? null)
                ? (string) $tenant->slug
                : (filled($tenant?->id ?? null) ? (string) $tenant->id : null);

            $spaceLabel = trim((string) (
                $shape->marketSpace?->display_name
                ?: ($shape->marketSpace?->number ?: $shape->marketSpace?->code)
            ));

            $tenantLabel = trim((string) (
                $tenant?->short_name
                ?: ($tenant?->name ?? '')
            ));

            return [
                'id' => (int) $shape->id,
                'space_id' => (int) ($shape->market_space_id ?? 0),
                'polygon' => is_array($shape->polygon) ? $shape->polygon : [],
                'tenant_key' => $tenantRouteKey,
                'space_label' => $spaceLabel,
                'tenant_label' => $tenantLabel,
            ];
        })->values();

        return view('marketplace.map.index', array_merge(
            $this->sharedViewData($request, $market),
            [
                'shapes' => $shapes,
                'spaces' => $spaces,
                'mapShapes' => $mapShapes,
                'version' => $version,
                'page' => $page,
            ],
        ));
    }
}
