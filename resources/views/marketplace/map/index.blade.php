@extends('marketplace.layout')

@section('title', 'Карта ярмарки')

@section('content')
    <section class="mp-card">
        <div class="mp-page-head">
            <div>
                <h1 class="mp-page-title">Карта ярмарки</h1>
                <p class="mp-page-sub">Нажмите на торговое место, чтобы открыть витрину арендатора.</p>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <span class="mp-badge">Версия: {{ $version }}</span>
                <span class="mp-badge">Страница: {{ $page }}</span>
            </div>
        </div>

        @if($shapes->count() === 0)
            <p class="mp-muted" style="margin:0;">Разметка карты пока не опубликована.</p>
        @else
            <div style="display:grid;grid-template-columns:1.3fr .7fr;gap:12px;">
                <div style="border:1px solid #d0e2f7;border-radius:14px;background:#f8fbff;padding:10px;min-height:420px;">
                    <svg id="mp-map" width="100%" height="520" style="border-radius:10px;background:#fff;border:1px solid #d7e7f8;"></svg>
                </div>
                <div style="display:grid;gap:10px;max-height:540px;overflow:auto;padding-right:4px;">
                    @foreach($spaces as $space)
                        @php
                            $label = trim((string) ($space->display_name ?: ($space->number ?: $space->code)));
                            $tenantRouteKey = filled($space->tenant?->slug ?? null) ? (string) $space->tenant->slug : (filled($space->tenant?->id ?? null) ? (string) $space->tenant->id : null);
                        @endphp
                        <article style="border:1px solid #d7e7f8;border-radius:12px;padding:10px;background:#fff;">
                            <strong>{{ $label !== '' ? $label : ('#' . $space->id) }}</strong>
                            <div class="mp-muted" style="margin-top:4px;">{{ $space->tenant?->short_name ?: ($space->tenant?->name ?: 'Свободно') }}</div>
                            @if($tenantRouteKey)
                                <a class="mp-btn" style="margin-top:8px;" href="{{ route('marketplace.store.show', ['marketSlug' => $market->slug, 'tenantSlug' => $tenantRouteKey, 'space_id' => $space->id]) }}">
                                    Открыть витрину
                                </a>
                            @endif
                        </article>
                    @endforeach
                </div>
            </div>
        @endif
    </section>
@endsection

@push('scripts')
    @if($shapes->count() > 0)
        <script>
            (function () {
                const svg = document.getElementById('mp-map');
                if (!svg) return;

                const shapes = @json($shapes->map(function ($shape) {
                    return [
                        'id' => (int) $shape->id,
                        'space_id' => (int) ($shape->market_space_id ?? 0),
                        'polygon' => is_array($shape->polygon) ? $shape->polygon : [],
                        'tenant_key' => filled($shape->marketSpace?->tenant?->slug ?? null)
                            ? (string) $shape->marketSpace->tenant->slug
                            : (filled($shape->marketSpace?->tenant?->id ?? null) ? (string) $shape->marketSpace->tenant->id : null),
                        'space_label' => trim((string) ($shape->marketSpace?->display_name ?: ($shape->marketSpace?->number ?: $shape->marketSpace?->code))),
                        'tenant_label' => trim((string) ($shape->marketSpace?->tenant?->short_name ?: ($shape->marketSpace?->tenant?->name ?? ''))),
                    ];
                }));

                let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
                shapes.forEach((shape) => {
                    (shape.polygon || []).forEach((p) => {
                        const x = Number(p.x ?? p[0] ?? 0);
                        const y = Number(p.y ?? p[1] ?? 0);
                        if (Number.isFinite(x) && Number.isFinite(y)) {
                            minX = Math.min(minX, x);
                            minY = Math.min(minY, y);
                            maxX = Math.max(maxX, x);
                            maxY = Math.max(maxY, y);
                        }
                    });
                });

                if (!Number.isFinite(minX) || !Number.isFinite(minY) || !Number.isFinite(maxX) || !Number.isFinite(maxY)) {
                    return;
                }

                const pad = 40;
                const width = Math.max(200, (maxX - minX) + pad * 2);
                const height = Math.max(160, (maxY - minY) + pad * 2);
                svg.setAttribute('viewBox', `${minX - pad} ${minY - pad} ${width} ${height}`);

                shapes.forEach((shape) => {
                    const pts = (shape.polygon || [])
                        .map((p) => `${Number(p.x ?? p[0] ?? 0)},${Number(p.y ?? p[1] ?? 0)}`)
                        .join(' ');
                    if (!pts) return;

                    const poly = document.createElementNS('http://www.w3.org/2000/svg', 'polygon');
                    poly.setAttribute('points', pts);
                    poly.setAttribute('fill', shape.tenant_key ? 'rgba(16,178,216,0.18)' : 'rgba(160,170,190,0.18)');
                    poly.setAttribute('stroke', shape.tenant_key ? '#10a0dc' : '#8ea3be');
                    poly.setAttribute('stroke-width', '2');
                    poly.style.cursor = shape.tenant_key ? 'pointer' : 'default';
                    poly.addEventListener('mouseenter', () => { poly.setAttribute('fill', 'rgba(10,132,214,0.32)'); });
                    poly.addEventListener('mouseleave', () => { poly.setAttribute('fill', shape.tenant_key ? 'rgba(16,178,216,0.18)' : 'rgba(160,170,190,0.18)'); });

                    const title = document.createElementNS('http://www.w3.org/2000/svg', 'title');
                    title.textContent = [shape.space_label, shape.tenant_label].filter(Boolean).join(' — ') || `Место #${shape.space_id}`;
                    poly.appendChild(title);

                    if (shape.tenant_key) {
                        poly.addEventListener('click', () => {
                            const url = `{{ route('marketplace.store.show', ['marketSlug' => $market->slug, 'tenantSlug' => '__tenant__']) }}`.replace('__tenant__', shape.tenant_key) + `?space_id=${shape.space_id}`;
                            window.location.href = url;
                        });
                    }

                    svg.appendChild(poly);
                });
            })();
        </script>
    @endif
@endpush
