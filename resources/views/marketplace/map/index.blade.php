@extends('marketplace.layout')

@section('title', 'Карта ярмарки')

@push('head')
    <style>
        .mp-shell {
            max-width: 100%;
        }
        .mp-map-layout {
            display: grid;
            grid-template-columns: minmax(0, 1fr);
            gap: 12px;
        }
        .mp-map-stage {
            border: 1px solid #d0e2f7;
            border-radius: 14px;
            background: #f8fbff;
            padding: 10px;
            min-height: 420px;
        }
        .mp-map-tools {
            border: 1px solid #d7e7f8;
            border-radius: 14px;
            background: #fff;
            padding: 12px;
            display: grid;
            gap: 12px;
        }
        .mp-map-search {
            width: 100%;
            border: 1px solid #c8dcf5;
            border-radius: 12px;
            padding: 10px 12px;
            font-size: 14px;
            outline: none;
            color: #11203b;
            background: #fbfdff;
        }
        .mp-map-search:focus {
            border-color: #7cc0f4;
            box-shadow: 0 0 0 3px rgba(16, 178, 216, .14);
        }
        .mp-map-index {
            max-height: 360px;
            overflow: auto;
            display: grid;
            gap: 10px;
            padding-right: 4px;
            grid-template-columns: repeat(4, minmax(0, 1fr));
        }
        .mp-map-row {
            border: 1px solid #d7e7f8;
            border-radius: 12px;
            background: #fff;
            padding: 10px;
            display: grid;
            grid-template-columns: minmax(0, 1fr);
            gap: 8px;
            align-content: start;
            cursor: pointer;
            transition: border-color .15s ease, background .15s ease, transform .15s ease;
        }
        .mp-map-row:hover {
            border-color: #9ecbf1;
            background: #f7fbff;
            transform: translateY(-1px);
        }
        .mp-map-row.is-active {
            border-color: #0a84d6;
            background: #eef8ff;
            box-shadow: 0 0 0 1px rgba(10, 132, 214, .18) inset;
        }
        .mp-map-row strong {
            display: block;
            line-height: 1.2;
        }
        .mp-map-row .mp-btn {
            justify-self: start;
            margin-top: 2px;
        }
        .mp-map-empty {
            border: 1px dashed #c7d8ee;
            border-radius: 12px;
            padding: 10px;
            color: #5d6b86;
            text-align: center;
            grid-column: 1 / -1;
        }
        @media (max-width: 1200px) {
            .mp-map-index {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
        }
        @media (max-width: 900px) {
            .mp-map-index {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }
        @media (max-width: 620px) {
            #mp-map {
                height: 440px !important;
            }
            .mp-map-row {
                grid-template-columns: 1fr;
            }
            .mp-map-index {
                grid-template-columns: 1fr;
            }
        }
    </style>
@endpush

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
            <div class="mp-map-layout">
                <div class="mp-map-stage">
                    <svg id="mp-map" width="100%" height="620" style="border-radius:10px;background:#fff;border:1px solid #d7e7f8;"></svg>
                </div>

                <div class="mp-map-tools">
                    <input
                        id="mp-map-search"
                        class="mp-map-search"
                        type="text"
                        placeholder="Поиск по месту, названию или арендатору"
                        autocomplete="off"
                    >

                    <div id="mp-map-index" class="mp-map-index">
                        @foreach($spaces as $space)
                            @php
                                $label = trim((string) ($space->display_name ?: ($space->number ?: $space->code)));
                                $tenantLabel = trim((string) ($space->tenant?->short_name ?: ($space->tenant?->name ?: 'Свободно')));
                                $tenantRouteKey = filled($space->tenant?->slug ?? null)
                                    ? (string) $space->tenant->slug
                                    : (filled($space->tenant?->id ?? null) ? (string) $space->tenant->id : null);
                            @endphp
                            <article
                                class="mp-map-row"
                                data-map-row
                                data-space-id="{{ (int) $space->id }}"
                                data-search="{{ mb_strtolower(trim($label . ' ' . $tenantLabel)) }}"
                            >
                                <div>
                                    <strong>{{ $label !== '' ? $label : ('#' . $space->id) }}</strong>
                                    <div class="mp-muted" style="margin-top:4px;">{{ $tenantLabel }}</div>
                                </div>
                                @if($tenantRouteKey)
                                    <a
                                        class="mp-btn"
                                        href="{{ route('marketplace.store.show', ['marketSlug' => $market->slug, 'tenantSlug' => $tenantRouteKey, 'space_id' => $space->id]) }}"
                                        onclick="event.stopPropagation()"
                                    >
                                        Витрина
                                    </a>
                                @endif
                            </article>
                        @endforeach
                        <div id="mp-map-empty" class="mp-map-empty" style="display:none;">Ничего не найдено.</div>
                    </div>
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

                const shapes = @json($mapShapes ?? []);
                const searchInput = document.getElementById('mp-map-search');
                const mapRows = Array.from(document.querySelectorAll('[data-map-row]'));
                const emptyState = document.getElementById('mp-map-empty');
                const shapeBySpaceId = new Map();
                let activeSpaceId = null;
                const defaultEmptyText = 'Начните ввод в поиске, чтобы отфильтровать места.';

                let minX = Infinity;
                let minY = Infinity;
                let maxX = -Infinity;
                let maxY = -Infinity;

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

                const setSpaceActive = (spaceId, scrollToRow = false) => {
                    activeSpaceId = Number(spaceId || 0);

                    shapeBySpaceId.forEach((polys, sid) => {
                        const isActive = sid === activeSpaceId;
                        polys.forEach((poly) => {
                            const baseFill = poly.dataset.baseFill || 'rgba(160,170,190,0.18)';
                            poly.setAttribute('fill', isActive ? 'rgba(10,132,214,0.45)' : baseFill);
                            poly.setAttribute('stroke-width', isActive ? '3' : '2');
                        });
                    });

                    mapRows.forEach((row) => {
                        const rowId = Number(row.getAttribute('data-space-id') || 0);
                        const isActive = rowId === activeSpaceId;
                        row.classList.toggle('is-active', isActive);
                        if (searchInput && searchInput.value.trim() === '') {
                            row.style.display = isActive ? '' : 'none';
                        }
                        if (isActive && scrollToRow) {
                            row.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                        }
                    });

                    if (emptyState && searchInput && searchInput.value.trim() === '') {
                        emptyState.style.display = activeSpaceId > 0 ? 'none' : '';
                        emptyState.textContent = defaultEmptyText;
                    }
                };

                shapes.forEach((shape) => {
                    const pts = (shape.polygon || [])
                        .map((p) => `${Number(p.x ?? p[0] ?? 0)},${Number(p.y ?? p[1] ?? 0)}`)
                        .join(' ');
                    if (!pts) return;

                    const baseFill = shape.tenant_key ? 'rgba(16,178,216,0.18)' : 'rgba(160,170,190,0.18)';
                    const sid = Number(shape.space_id || 0);

                    const poly = document.createElementNS('http://www.w3.org/2000/svg', 'polygon');
                    poly.setAttribute('points', pts);
                    poly.setAttribute('fill', baseFill);
                    poly.setAttribute('stroke', shape.tenant_key ? '#10a0dc' : '#8ea3be');
                    poly.setAttribute('stroke-width', '2');
                    poly.dataset.baseFill = baseFill;
                    poly.dataset.spaceId = String(sid || 0);
                    poly.style.cursor = shape.tenant_key ? 'pointer' : 'default';

                    poly.addEventListener('mouseenter', () => {
                        if (sid !== activeSpaceId) {
                            poly.setAttribute('fill', 'rgba(10,132,214,0.32)');
                        }
                    });
                    poly.addEventListener('mouseleave', () => {
                        if (sid !== activeSpaceId) {
                            poly.setAttribute('fill', baseFill);
                        }
                    });

                    const title = document.createElementNS('http://www.w3.org/2000/svg', 'title');
                    title.textContent = [shape.space_label, shape.tenant_label].filter(Boolean).join(' — ') || `Место #${shape.space_id}`;
                    poly.appendChild(title);

                    poly.addEventListener('click', () => {
                        if (sid > 0) {
                            setSpaceActive(sid, true);
                        }

                        if (shape.tenant_key) {
                            const url = `{{ route('marketplace.store.show', ['marketSlug' => $market->slug, 'tenantSlug' => '__tenant__']) }}`.replace('__tenant__', shape.tenant_key) + `?space_id=${shape.space_id}`;
                            window.location.href = url;
                        }
                    });

                    if (sid > 0) {
                        if (!shapeBySpaceId.has(sid)) {
                            shapeBySpaceId.set(sid, []);
                        }
                        shapeBySpaceId.get(sid).push(poly);
                    }

                    svg.appendChild(poly);
                });

                mapRows.forEach((row) => {
                    row.addEventListener('click', () => {
                        const sid = Number(row.getAttribute('data-space-id') || 0);
                        if (sid > 0) {
                            setSpaceActive(sid, true);
                        }
                    });
                });

                const applySearchFilter = () => {
                    const q = ((searchInput?.value) || '').trim().toLowerCase();

                    if (q === '') {
                        mapRows.forEach((row) => {
                            const rowId = Number(row.getAttribute('data-space-id') || 0);
                            row.style.display = activeSpaceId > 0 && rowId === activeSpaceId ? '' : 'none';
                        });
                        if (emptyState) {
                            emptyState.style.display = activeSpaceId > 0 ? 'none' : '';
                            emptyState.textContent = defaultEmptyText;
                        }
                        return;
                    }

                    let visible = 0;
                    mapRows.forEach((row) => {
                        const haystack = String(row.getAttribute('data-search') || '').toLowerCase();
                        const show = haystack.includes(q);
                        row.style.display = show ? '' : 'none';
                        if (show) visible++;
                    });

                    if (emptyState) {
                        emptyState.style.display = visible === 0 ? '' : 'none';
                        emptyState.textContent = visible === 0 ? 'Ничего не найдено.' : defaultEmptyText;
                    }
                };

                if (searchInput) {
                    searchInput.addEventListener('input', applySearchFilter);
                    applySearchFilter();
                }
            })();
        </script>
    @endif
@endpush
