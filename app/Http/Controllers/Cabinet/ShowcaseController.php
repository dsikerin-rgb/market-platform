<?php

namespace App\Http\Controllers\Cabinet;

use App\Http\Controllers\Controller;
use App\Models\MarketSpace;
use App\Models\TenantShowcase;
use App\Models\TenantSpaceShowcase;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ShowcaseController extends Controller
{
    public function edit(Request $request): View
    {
        $tenant = $request->user()->tenant;

        if (! $tenant->slug) {
            $tenant->slug = $this->makeUniqueSlug($tenant->display_name ?: $tenant->name ?: 'tenant-' . $tenant->id, $tenant->id);
            $tenant->save();
        }

        $showcase = TenantShowcase::query()->where('tenant_id', $tenant->id)->first();
        $spaces = MarketSpace::query()
            ->where('tenant_id', (int) $tenant->id)
            ->when((int) ($tenant->market_id ?? 0) > 0, fn ($query) => $query->where('market_id', (int) $tenant->market_id))
            ->orderByRaw('COALESCE(code, number, display_name) asc')
            ->get(['id', 'code', 'number', 'display_name']);

        $selectedSpaceId = (int) $request->integer('space_id', 0);
        $selectedSpace = $selectedSpaceId > 0 ? $spaces->firstWhere('id', $selectedSpaceId) : null;
        $selectedSpaceId = $selectedSpace ? (int) $selectedSpace->id : null;

        $spaceShowcase = null;
        if ($selectedSpaceId && Schema::hasTable('tenant_space_showcases')) {
            $spaceShowcase = TenantSpaceShowcase::query()
                ->where('tenant_id', (int) $tenant->id)
                ->where('market_space_id', $selectedSpaceId)
                ->where('is_active', true)
                ->first();
        }

        return view('cabinet.showcase.edit', [
            'tenant' => $tenant,
            'showcase' => $showcase,
            'spaces' => $spaces,
            'selectedSpaceId' => $selectedSpaceId,
            'selectedSpace' => $selectedSpace,
            'spaceShowcase' => $spaceShowcase,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $tenant = $request->user()->tenant;
        $spaceId = (int) $request->integer('space_id', 0);
        $spaceIds = MarketSpace::query()
            ->where('tenant_id', (int) $tenant->id)
            ->when((int) ($tenant->market_id ?? 0) > 0, fn ($query) => $query->where('market_id', (int) $tenant->market_id))
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->values()
            ->all();

        if ($spaceId > 0 && ! in_array($spaceId, $spaceIds, true)) {
            throw ValidationException::withMessages([
                'space_id' => 'Выбрано недопустимое торговое место.',
            ]);
        }

        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'assortment' => ['nullable', 'string'],
            'phone' => ['nullable', 'string', 'max:50'],
            'telegram' => ['nullable', 'string', 'max:255'],
            'website' => ['nullable', 'string', 'max:255'],
            'photos' => ['nullable', 'array', 'max:5'],
            'photos.*' => ['image', 'max:4096'],
        ]);

        if ($spaceId > 0 && in_array($spaceId, $spaceIds, true) && Schema::hasTable('tenant_space_showcases')) {
            $spaceShowcase = TenantSpaceShowcase::query()->firstOrNew([
                'tenant_id' => (int) $tenant->id,
                'market_space_id' => $spaceId,
            ]);

            $photos = $spaceShowcase->photos ?? [];

            if ($request->hasFile('photos')) {
                $photos = [];

                foreach ($request->file('photos', []) as $file) {
                    if (! $file) {
                        continue;
                    }

                    $photos[] = $file->store('tenant-showcases', 'public');
                }
            }

            $spaceShowcase->fill([
                'market_id' => (int) ($tenant->market_id ?? 0),
                'title' => $validated['title'] ?? null,
                'description' => $validated['description'] ?? null,
                'assortment' => $validated['assortment'] ?? null,
                'phone' => $validated['phone'] ?? null,
                'telegram' => $validated['telegram'] ?? null,
                'website' => $validated['website'] ?? null,
                'photos' => $photos,
                'is_active' => true,
            ]);

            $spaceShowcase->save();

            return redirect()
                ->route('cabinet.showcase.edit', ['space_id' => $spaceId])
                ->with('success', 'Настройки витрины для выбранного торгового места сохранены.');
        }

        $showcase = TenantShowcase::query()->firstOrNew(['tenant_id' => $tenant->id]);
        $photos = $showcase->photos ?? [];

        if ($request->hasFile('photos')) {
            $photos = [];

            foreach ($request->file('photos', []) as $file) {
                if (! $file) {
                    continue;
                }

                $photos[] = $file->store('tenant-showcases', 'public');
            }
        }

        $showcase->fill([
            'title' => $validated['title'] ?? null,
            'description' => $validated['description'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'telegram' => $validated['telegram'] ?? null,
            'website' => $validated['website'] ?? null,
            'photos' => $photos,
        ]);

        $showcase->save();

        return redirect()
            ->route('cabinet.showcase.edit')
            ->with('success', 'Основная витрина обновлена.');
    }

    private function makeUniqueSlug(string $base, int $tenantId): string
    {
        $slugBase = Str::slug($base) ?: 'tenant-' . $tenantId;
        $slug = $slugBase;
        $counter = 1;

        while (\App\Models\Tenant::query()->where('slug', $slug)->whereKeyNot($tenantId)->exists()) {
            $counter += 1;
            $slug = $slugBase . '-' . $counter;
        }

        return $slug;
    }
}
