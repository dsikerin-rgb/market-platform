@extends('marketplace.layout')

@section('title', 'Маркетплейс')

@section('content')
    <section class="mp-card" style="padding:0;overflow:hidden;background:linear-gradient(120deg,#0a84d6,#10b2d8 60%,#7bd5ff);color:#fff;">
        <div style="padding:24px;display:grid;grid-template-columns:1.2fr .8fr;gap:14px;align-items:center;">
            <div>
                <div style="font-size:13px;letter-spacing:.18em;text-transform:uppercase;opacity:.85;">Городская Экоярмарка</div>
                <h1 class="mp-page-title" style="color:#fff;margin-top:8px;">{{ $marketplaceSettings['hero_title'] }}</h1>
                <p style="margin:10px 0 0;max-width:620px;color:#e8f7ff;">
                    {{ $marketplaceSettings['hero_subtitle'] }}
                </p>
                <div style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap;">
                    <a class="mp-btn" style="border-color:rgba(255,255,255,.45);background:rgba(255,255,255,.14);color:#fff;" href="{{ route('marketplace.catalog', ['marketSlug' => $market->slug]) }}">Перейти в каталог</a>
                    <a class="mp-btn" style="border-color:rgba(255,255,255,.45);background:rgba(255,255,255,.14);color:#fff;" href="{{ route('marketplace.map', ['marketSlug' => $market->slug]) }}">Посмотреть карту</a>
                </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                <div style="background:rgba(255,255,255,.16);border:1px solid rgba(255,255,255,.35);border-radius:14px;padding:12px;">
                    <div style="font-size:12px;opacity:.9;">Витрин</div>
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

    @if(collect($infoSlides)->isNotEmpty())
        <section class="mp-card">
            <style>
                .mp-slider {
                    position: relative;
                    display: grid;
                    gap: 12px;
                }
                .mp-slider__viewport {
                    overflow: hidden;
                }
                .mp-slider__track {
                    display: grid;
                    grid-auto-flow: column;
                    grid-auto-columns: calc(33.333% - 8px);
                    gap: 12px;
                    overflow-x: auto;
                    scroll-snap-type: x mandatory;
                    scrollbar-width: none;
                    scroll-behavior: smooth;
                    padding-bottom: 4px;
                }
                .mp-slider__track::-webkit-scrollbar {
                    display: none;
                }
                .mp-slider__card {
                    scroll-snap-align: start;
                    min-height: 218px;
                    border-radius: 18px;
                    border: 1px solid #d9e6f7;
                    background: linear-gradient(180deg, #ffffff, #f7fbff);
                    box-shadow: 0 8px 24px rgba(17, 32, 59, .06);
                    display: flex;
                    flex-direction: column;
                    overflow: hidden;
                }
                .mp-slider__card[data-theme="buyer"] {
                    background: linear-gradient(180deg, #eff8ff, #ffffff);
                }
                .mp-slider__card[data-theme="seller"] {
                    background: linear-gradient(180deg, #f7f5ff, #ffffff);
                }
                .mp-slider__card[data-theme="partner"] {
                    background: linear-gradient(180deg, #fef7ec, #ffffff);
                }
                .mp-slider__media {
                    height: 140px;
                    background: #dfefff;
                }
                .mp-slider__media img {
                    width: 100%;
                    height: 100%;
                    object-fit: cover;
                    display: block;
                }
                .mp-slider__body {
                    padding: 16px;
                    display: flex;
                    flex: 1;
                    flex-direction: column;
                    gap: 10px;
                }
                .mp-slider__badge {
                    display: inline-flex;
                    align-items: center;
                    width: fit-content;
                    padding: 6px 10px;
                    border-radius: 999px;
                    font-size: 12px;
                    font-weight: 700;
                    color: #1c4d81;
                    border: 1px solid #bed9f7;
                    background: #f0f8ff;
                }
                .mp-slider__title {
                    margin: 0;
                    font-size: 21px;
                    line-height: 1.2;
                }
                .mp-slider__text {
                    margin: 0;
                    color: var(--muted);
                    line-height: 1.45;
                }
                .mp-slider__footer {
                    margin-top: auto;
                    display: flex;
                    align-items: center;
                    justify-content: space-between;
                    gap: 10px;
                }
                .mp-slider__controls {
                    display: flex;
                    align-items: center;
                    justify-content: space-between;
                    gap: 12px;
                }
                .mp-slider__nav {
                    display: inline-flex;
                    align-items: center;
                    gap: 8px;
                }
                .mp-slider__arrow {
                    width: 40px;
                    height: 40px;
                    border-radius: 999px;
                    border: 1px solid #cfe2f7;
                    background: #fff;
                    color: var(--text);
                    font-weight: 800;
                    cursor: pointer;
                }
                .mp-slider__dots {
                    display: flex;
                    align-items: center;
                    gap: 6px;
                    flex-wrap: wrap;
                }
                .mp-slider__dot {
                    width: 9px;
                    height: 9px;
                    border-radius: 999px;
                    border: none;
                    background: #c6d9ee;
                    cursor: pointer;
                }
                .mp-slider__dot.is-active {
                    width: 28px;
                    background: linear-gradient(140deg, var(--brand), var(--brand-2));
                }
                @media (max-width: 1100px) {
                    .mp-slider__track {
                        grid-auto-columns: calc(50% - 6px);
                    }
                }
                @media (max-width: 760px) {
                    .mp-slider__track {
                        grid-auto-columns: 100%;
                    }
                    .mp-slider__controls {
                        flex-direction: column;
                        align-items: stretch;
                    }
                }
            </style>

            <div class="mp-slider"
                 data-mp-slider
                 data-autoplay="{{ !empty($marketplaceSettings['slider_autoplay_enabled']) ? '1' : '0' }}"
                 data-interval="{{ (int) ($marketplaceSettings['slider_autoplay_interval_ms'] ?? 7000) }}">
                <div class="mp-slider__viewport">
                    <div class="mp-slider__track" data-mp-slider-track>
                        @foreach($infoSlides as $slide)
                            @php($imageUrl = is_object($slide) ? $slide->image_url : ($slide['image_url'] ?? null))
                            @php($theme = is_object($slide) ? ($slide->theme ?? 'info') : ($slide['theme'] ?? 'info'))
                            @php($badge = is_object($slide) ? ($slide->badge ?? null) : ($slide['badge'] ?? null))
                            @php($title = is_object($slide) ? ($slide->title ?? '') : ($slide['title'] ?? ''))
                            @php($description = is_object($slide) ? ($slide->description ?? '') : ($slide['description'] ?? ''))
                            @php($ctaLabel = is_object($slide) ? ($slide->cta_label ?? null) : ($slide['cta_label'] ?? null))
                            @php($ctaUrl = is_object($slide) ? ($slide->cta_url ?? null) : ($slide['cta_url'] ?? null))
                            <article class="mp-slider__card" data-theme="{{ $theme }}">
                                @if(filled($imageUrl))
                                    <div class="mp-slider__media">
                                        <img src="{{ $imageUrl }}" alt="{{ $title }}">
                                    </div>
                                @endif
                                <div class="mp-slider__body">
                                    @if(filled($badge))
                                        <span class="mp-slider__badge">{{ $badge }}</span>
                                    @endif
                                    <h3 class="mp-slider__title">{{ $title }}</h3>
                                    @if(filled($description))
                                        <p class="mp-slider__text">{{ $description }}</p>
                                    @endif
                                    <div class="mp-slider__footer">
                                        @if(filled($ctaLabel) && filled($ctaUrl))
                                            <a class="mp-btn" href="{{ $ctaUrl }}">{{ $ctaLabel }}</a>
                                        @else
                                            <span></span>
                                        @endif
                                    </div>
                                </div>
                            </article>
                        @endforeach
                    </div>
                </div>
                <div class="mp-slider__controls">
                    <div class="mp-slider__nav">
                        <button class="mp-slider__arrow" type="button" data-mp-slider-prev>&lsaquo;</button>
                        <button class="mp-slider__arrow" type="button" data-mp-slider-next>&rsaquo;</button>
                    </div>
                    <div class="mp-slider__dots" data-mp-slider-dots></div>
                </div>
            </div>
        </section>
    @endif

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
                    <p class="mp-page-sub">Праздники, акции, санитарные дни и новости Экоярмарки.</p>
                </div>
                <a class="mp-btn" href="{{ route('marketplace.announcements', ['marketSlug' => $market->slug]) }}">Все анонсы</a>
            </div>
            <div class="mp-grid">
                @foreach($announcements->take(4) as $announcement)
                    @php($hasImage = filled($announcement->cover_image_url))
                    <article style="background:#fff;border:1px solid #d9e6f7;border-radius:14px;{{ $hasImage ? 'padding:0;overflow:hidden;display:block;' : 'padding:12px;display:flex;flex-direction:column;gap:8px;' }}">
                        @if($announcement->cover_image_url)
                            <a href="{{ route('marketplace.announcement.show', ['marketSlug' => $market->slug, 'announcementSlug' => $announcement->slug]) }}"
                               style="height:220px;overflow:hidden;position:relative;display:block;">
                                <img src="{{ $announcement->cover_image_url }}" alt="{{ $announcement->title }}" style="width:100%;height:100%;object-fit:cover;">
                                @php($startDate = optional($announcement->starts_at)->format('d.m'))
                                @php($endDate = optional($announcement->ends_at)->format('d.m'))
                                @php($fallbackDate = optional($announcement->published_at)->format('d.m') ?: optional($announcement->created_at)->format('d.m'))
                                @php($dateLabel = ((string) $announcement->kind === 'promo' && filled($startDate) && filled($endDate)) ? ($startDate . ' - ' . $endDate) : ($startDate ?: $fallbackDate))
                                @if(filled($dateLabel))
                                    <span style="position:absolute;right:10px;bottom:10px;color:#fff;padding:8px 11px;border-radius:8px;background:rgba(17,32,59,.42);backdrop-filter:blur(3px);display:flex;flex-direction:column;align-items:flex-end;gap:4px;max-width:82%;">
                                        <span style="font-weight:800;font-size:22px;line-height:1;">{{ $dateLabel }}</span>
                                        <span style="font-weight:700;font-size:16px;line-height:1.2;text-align:right;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;">{{ $announcement->title }}</span>
                                    </span>
                                @endif
                            </a>
                        @else
                            <a href="{{ route('marketplace.announcement.show', ['marketSlug' => $market->slug, 'announcementSlug' => $announcement->slug]) }}"
                               style="font-size:18px;font-weight:800;line-height:1.25;">
                                {{ $announcement->title }}
                            </a>
                            @if(filled($announcement->excerpt))
                                <p class="mp-muted" style="margin:0;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;">
                                    {{ $announcement->excerpt }}
                                </p>
                            @endif
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
                    <p class="mp-page-sub">Подборка актуальных предложений продавцов Экоярмарки.</p>
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
                <p class="mp-page-sub">Последние добавления по Экоярмарке.</p>
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
                    <h2 class="mp-page-title" style="font-size:26px;">Витрины продавцов</h2>
                    <p class="mp-page-sub">Активные продавцы с публичными карточками и товарами.</p>
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

    @push('scripts')
        <script>
            (function () {
                document.querySelectorAll('[data-mp-slider]').forEach(function (slider) {
                    const track = slider.querySelector('[data-mp-slider-track]');
                    const prev = slider.querySelector('[data-mp-slider-prev]');
                    const next = slider.querySelector('[data-mp-slider-next]');
                    const dots = slider.querySelector('[data-mp-slider-dots]');

                    if (!track) {
                        return;
                    }

                    const cards = Array.from(track.children);
                    if (cards.length === 0) {
                        return;
                    }

                    let current = 0;
                    let timer = null;
                    const autoplay = slider.dataset.autoplay === '1';
                    const interval = Math.max(parseInt(slider.dataset.interval || '7000', 10), 4000);

                    const measureStep = function () {
                        if (!cards[0]) {
                            return 0;
                        }

                        const style = window.getComputedStyle(track);
                        const gap = parseFloat(style.columnGap || style.gap || '0');

                        return cards[0].getBoundingClientRect().width + gap;
                    };

                    const renderDots = function () {
                        if (!dots) {
                            return;
                        }

                        dots.innerHTML = '';
                        cards.forEach(function (_, index) {
                            const dot = document.createElement('button');
                            dot.type = 'button';
                            dot.className = 'mp-slider__dot' + (index === current ? ' is-active' : '');
                            dot.addEventListener('click', function () {
                                scrollToIndex(index);
                            });
                            dots.appendChild(dot);
                        });
                    };

                    const scrollToIndex = function (index) {
                        const step = measureStep();
                        current = Math.max(0, Math.min(index, cards.length - 1));
                        track.scrollTo({ left: step * current, behavior: 'smooth' });
                        renderDots();
                    };

                    const syncCurrent = function () {
                        const step = measureStep();
                        if (step <= 0) {
                            return;
                        }

                        current = Math.max(0, Math.min(Math.round(track.scrollLeft / step), cards.length - 1));
                        renderDots();
                    };

                    const stopAutoplay = function () {
                        if (timer) {
                            window.clearInterval(timer);
                            timer = null;
                        }
                    };

                    const startAutoplay = function () {
                        stopAutoplay();

                        if (!autoplay || cards.length < 2) {
                            return;
                        }

                        timer = window.setInterval(function () {
                            const nextIndex = current >= cards.length - 1 ? 0 : current + 1;
                            scrollToIndex(nextIndex);
                        }, interval);
                    };

                    prev && prev.addEventListener('click', function () {
                        scrollToIndex(current <= 0 ? cards.length - 1 : current - 1);
                    });

                    next && next.addEventListener('click', function () {
                        scrollToIndex(current >= cards.length - 1 ? 0 : current + 1);
                    });

                    track.addEventListener('scroll', function () {
                        window.requestAnimationFrame(syncCurrent);
                    }, { passive: true });

                    slider.addEventListener('mouseenter', stopAutoplay);
                    slider.addEventListener('mouseleave', startAutoplay);
                    slider.addEventListener('touchstart', stopAutoplay, { passive: true });
                    slider.addEventListener('touchend', startAutoplay, { passive: true });

                    document.addEventListener('visibilitychange', function () {
                        if (document.hidden) {
                            stopAutoplay();
                        } else {
                            startAutoplay();
                        }
                    });

                    window.addEventListener('resize', function () {
                        scrollToIndex(current);
                    });

                    renderDots();
                    startAutoplay();
                });
            }());
        </script>
    @endpush
@endsection
