<?php

namespace App\Http\Controllers\Cabinet;

use App\Http\Controllers\Controller;
use App\Models\TenantShowcase;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
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

        return view('cabinet.showcase.edit', [
            'tenant' => $tenant,
            'showcase' => $showcase,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $tenant = $request->user()->tenant;

        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'phone' => ['nullable', 'string', 'max:50'],
            'telegram' => ['nullable', 'string', 'max:255'],
            'website' => ['nullable', 'string', 'max:255'],
            'photos' => ['nullable', 'array', 'max:5'],
            'photos.*' => ['image', 'max:4096'],
        ]);

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
            ->with('success', 'Витрина обновлена.');
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
