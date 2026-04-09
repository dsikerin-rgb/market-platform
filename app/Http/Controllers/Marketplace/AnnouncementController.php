<?php

declare(strict_types=1);

namespace App\Http\Controllers\Marketplace;

use App\Models\MarketplaceAnnouncement;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AnnouncementController extends BaseMarketplaceController
{
    public function index(Request $request, string $marketSlug): View
    {
        $market = $this->resolveMarketOrFail($marketSlug);

        $kind = trim((string) $request->query('kind', ''));
        $today = CarbonImmutable::today();
        $upcomingWindowEnd = $today->addMonths(2);

        $query = MarketplaceAnnouncement::query()
            ->where('market_id', (int) $market->id)
            ->where('is_active', true)
            ->whereDate('starts_at', '>=', $today->toDateString())
            ->whereDate('starts_at', '<=', $upcomingWindowEnd->toDateString());

        if ($kind !== '') {
            $query->where('kind', $kind);
        }

        $announcements = $query
            ->orderBy('starts_at')
            ->orderBy('id')
            ->paginate(20)
            ->withQueryString();

        return view('marketplace.announcements.index', array_merge(
            $this->sharedViewData($request, $market),
            [
                'announcements' => $announcements,
                'kind' => $kind,
            ],
        ));
    }

    public function show(Request $request, string $marketSlug, string $announcementSlug): View
    {
        $market = $this->resolveMarketOrFail($marketSlug);

        $announcement = MarketplaceAnnouncement::query()
            ->with('marketHoliday')
            ->where('market_id', (int) $market->id)
            ->where('slug', $announcementSlug)
            ->where('is_active', true)
            ->firstOrFail();

        return view('marketplace.announcements.show', array_merge(
            $this->sharedViewData($request, $market),
            [
                'announcement' => $announcement,
            ],
        ));
    }
}
