@extends('marketplace.layout')

@section('title', 'Маркетплейс')

@section('content')
    <section class="mp-card" style="padding:0;overflow:hidden;background:linear-gradient(120deg,#0a84d6,#10b2d8 60%,#7bd5ff);color:#fff;">
        <div style="padding:24px;display:grid;grid-template-columns:1.2fr .8fr;gap:14px;align-items:center;">
            <div>
                <div style="font-size:13px;letter-spacing:.18em;text-transform:uppercase;opacity:.85;">Городской маркетплейс</div>
                <h1 class="mp-page-title" style="color:#fff;margin-top:8px;">Покупки на Экоярмарке в одном месте</h1>
                <p style="margin:10px 0 0;max-width:620px;color:#e8f7ff;">
                    Единая витрина товаров, карта ярмарки, прямой чат с продавцами, отзывы и анонсы мероприятий.
                </p>
                <div style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap;">
                    <a class="mp-btn" style="border-color:rgba(255,255,255,.45);background:rgba(255,255,255,.14);color:#fff;" href="{{ route('marketplace.catalog', ['marketSlug' => $market->slug]) }}">Перейти в каталог</a>
                    <a class="mp-btn" style="border-color:rgba(255,255,255,.45);background:rgba(255,255,255,.14);color:#fff;" href="{{ route('marketplace.map', ['marketSlug' => $market->slug]) }}">Посмотреть карту</a>
                </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                <div style="background:rgba(255,255,255,.16);border:1px solid rgba(255,255,255,.35);border-radius:14px;padding:12px;">
                    <div style="font-size:12px;opacity:.9;">Магазинов</div>
                    <div style="font-size:28px;font-weight:800;">{{ $topStores->count() }}</div>
                </div>
                <div style="background:rgba(255,255,255,.16);border:1px solid rgba(255,255,255,.35);border-radius:14px;padding:12px;">
                    <div style="font-size:12px;opacity:.9;">Товаров</div>
                    <div style="font-size:28px;font-weight:800;">{{ $latestProducts->count() }}</div>
                </div>
                <div style="background:rgba(255,255,255,.16);border:1px solid rgba(255,255,255,.35);border-radius:14px;padding:12px;">
                    <div style="font-size:12px;opacity:.9;">Анонсов</div>
                    <div style="font-size:28px;font-weight:800;">{{ $announcements->count() }}</div>
                </div>
                <div style="background:rgba(255,255,255,.16);border:1px solid rgba(255,255,255,.35);border-radius:14px;padding:12px;">
                    <div style="font-size:12px;opacity:.9;">Избранное</div>
                    <div style="font-size:28px;font-weight:800;">{{ $marketplaceFavoriteCount ?? 0 }}</div>
                </div>
            </div>
        </div>
    </section>

    @if(!empty($nearestSanitaryAnnouncement))
        <section class="mp-card" style="border-color:#f9d48c;background:linear-gradient(180deg,#fff9e8,#fffdf4);">
            <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap;">
                <div>
                    <div class="mp-badge" style="background:#fff3ce;border-color:#f2cd75;color:#6b4c00;">Ближайший санитарный день</div>
                    <h2 class="mp-page-title" style="font-size:24px;margin-top:10px;">{{ $nearestSanitaryAnnouncement->title }}</h2>
                    <p class="mp-page-sub" style="margin:8px 0 0;max-width:760px;">
                        {{ $nearestSanitaryAnnouncement->excerpt ?: 'Плановый санитарный день. Проверьте режим работы и ограничения заранее.' }}
                    </p>
                </div>
                <div style="display:flex;flex-direction:column;align-items:flex-end;gap:10px;">
                    <span class="mp-badge" style="font-size:14px;padding:8px 12px;">
                        {{ optional($nearestSanitaryAnnouncement->starts_at)->format('d.m.Y') ?? 'Дата уточняется' }}
                    </span>
                    @php($nearestSanitaryUrl = filled($nearestSanitaryAnnouncement->slug ?? null)
                        ? route('marketplace.announcement.show', ['marketSlug' => $market->slug, 'announcementSlug' => (string) $nearestSanitaryAnnouncement->slug])
                        : route('marketplace.announcements', ['marketSlug' => $market->slug]))
                    <a class="mp-btn" href="{{ $nearestSanitaryUrl }}">Подробнее</a>
                </div>
            </div>
        </section>
    @endif

    @if($announcements->count() > 0)
        <section class="mp-card">
            <div class="mp-page-head">
                <div>
                    <h2 class="mp-page-title" style="font-size:26px;">Анонсы и события</h2>
                    <p class="mp-page-sub">Праздники, акции, санитарные дни и новости ярмарки.</p>
                </div>
                <a class="mp-btn" href="{{ route('marketplace.announcements', ['marketSlug' => $market->slug]) }}">Все анонсы</a>
            </div>
            <div class="mp-grid">
                @foreach($announcements as $announcement)
                    <article style="background:#fff;border:1px solid #d9e6f7;border-radius:14px;padding:12px;display:flex;flex-direction:column;gap:8px;">
                        @if($announcement->cover_image_url)
                            <div style="height:146px;border-radius:10px;overflow:hidden;border:1px solid #d9e6f7;position:relative;">
                                <img src="{{ $announcement->cover_image_url }}" alt="{{ $announcement->title }}" style="width:100%;height:100%;object-fit:cover;">
                                @if($announcement->starts_at)
                                    <span style="position:absolute;right:10px;bottom:10px;color:#fff;font-weight:800;font-size:22px;line-height:1;padding:8px 11px;border-radius:8px;background:rgba(17,32,59,.35);backdrop-filter:blur(3px);">
                                        {{ optional($announcement->starts_at)->format('d.m') }}
                                    </span>
                                @endif
                            </div>
                        @endif
                        <a href="{{ route('marketplace.announcement.show', ['marketSlug' => $market->slug, 'announcementSlug' => $announcement->slug]) }}"
                           style="font-size:18px;font-weight:800;line-height:1.25;">
                            {{ $announcement->title }}
                        </a>
                        @if(filled($announcement->excerpt))
                            <p class="mp-muted" style="margin:0;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;">
                                {{ $announcement->excerpt }}
                            </p>
                        @endif
                    </article>
                @endforeach
            </div>
        </section>
    @endif

    @if($featuredProducts->count() > 0)
        <section class="mp-card">
            <div class="mp-page-head">
                <div>
                    <h2 class="mp-page-title" style="font-size:26px;">Рекомендуем</h2>
                    <p class="mp-page-sub">Подборка актуальных предложений арендаторов.</p>
                </div>
            </div>
            <div class="mp-grid">
                @foreach($featuredProducts as $product)
                    @include('marketplace.partials.product-card', ['product' => $product])
                @endforeach
            </div>
        </section>
    @endif

    <section class="mp-card">
        <div class="mp-page-head">
            <div>
                <h2 class="mp-page-title" style="font-size:26px;">Новые товары</h2>
                <p class="mp-page-sub">Последние добавления по рынку.</p>
            </div>
            <a class="mp-btn mp-btn-brand" href="{{ route('marketplace.catalog', ['marketSlug' => $market->slug]) }}">Открыть каталог</a>
        </div>
        <div class="mp-grid">
            @foreach($latestProducts as $product)
                @include('marketplace.partials.product-card', ['product' => $product])
            @endforeach
        </div>
    </section>

    @if($topStores->count() > 0)
        <section class="mp-card">
            <div class="mp-page-head">
                <div>
                    <h2 class="mp-page-title" style="font-size:26px;">Магазины</h2>
                    <p class="mp-page-sub">Популярные арендаторы с активной витриной.</p>
                </div>
            </div>
            <div class="mp-grid">
                @foreach($topStores as $store)
                    @php($storeRouteKey = filled($store->slug ?? null) ? (string) $store->slug : (string) $store->id)
                    <article style="background:var(--surface-soft);border:1px solid #d5e5f8;border-radius:14px;padding:14px;">
                        <h3 style="margin:0 0 8px;font-size:20px;">{{ $store->short_name ?: $store->name }}</h3>
                        <p class="mp-muted" style="margin:0 0 12px;">Товаров: {{ (int) ($store->active_products_count ?? 0) }}</p>
                        <a class="mp-btn" href="{{ route('marketplace.store.show', ['marketSlug' => $market->slug, 'tenantSlug' => $storeRouteKey]) }}">Перейти в витрину</a>
                    </article>
                @endforeach
            </div>
        </section>
    @endif
@endsection
