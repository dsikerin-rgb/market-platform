@php
    $spaceId = isset($salesNavSpaceId) ? (int) $salesNavSpaceId : (request()->filled('space_id') ? (int) request()->query('space_id') : 0);
    $spaceQuery = $spaceId !== 0 ? ['space_id' => $spaceId] : [];
@endphp

<div class="rounded-3xl bg-white border border-slate-200 p-2 shadow-sm">
    <div class="grid grid-cols-2 gap-2">
        <a
            href="{{ route('cabinet.products.index', $spaceQuery) }}"
            @class([
                'rounded-2xl px-4 py-3 text-sm font-semibold text-center transition',
                'bg-sky-600 text-white shadow-sm' => request()->routeIs('cabinet.products.*'),
                'bg-slate-50 text-slate-700 border border-slate-200' => ! request()->routeIs('cabinet.products.*'),
            ])
        >
            Товары
        </a>
        <a
            href="{{ route('cabinet.showcase.edit', $spaceQuery) }}"
            @class([
                'rounded-2xl px-4 py-3 text-sm font-semibold text-center transition',
                'bg-sky-600 text-white shadow-sm' => request()->routeIs('cabinet.showcase.*'),
                'bg-slate-50 text-slate-700 border border-slate-200' => ! request()->routeIs('cabinet.showcase.*'),
            ])
        >
            Витрина
        </a>
    </div>
</div>
