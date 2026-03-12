@extends('marketplace.layout')

@section('title', 'Маркетплейс')

@section('content')
    @php
        use Illuminate\Support\Str;

        $marketSlug = $market->slug;
        $featuredLead = $featuredProducts->first();
        $featuredSecondary = $featuredProducts->slice(1, 3);
        $latestCards = $latestProducts->take(8);
        $topStoresPreview = $topStores->take(4);
        $headlineAnnouncements = $announcements->take(3);
        $slides = collect($infoSlides ?? [])->values();

        $heroStats = [
            [
                'label' => 'Витрины',
                'value' => $topStores->count(),
                'hint' => 'Продавцы с активными товарами',
                'url' => $topStores->count() > 0
                    ? '#marketplace-stores'
                    : route('marketplace.catalog', ['marketSlug' => $marketSlug]),
            ],
            [
                'label' => 'Товары',
                'value' => $latestProducts->count(),
                'hint' => 'Последние публикации',
                'url' => route('marketplace.catalog', ['marketSlug' => $marketSlug]),
            ],
            [
                'label' => 'Анонсы',
                'value' => $announcements->count(),
                'hint' => 'События и новости рынка',
                'url' => route('marketplace.announcements', ['marketSlug' => $marketSlug]),
            ],
            [
                'label' => 'Избранное',
                'value' => $marketplaceFavoriteCount ?? 0,
                'hint' => $marketplaceCurrentUserCanUseBuyer
                    ? 'Ваши сохранённые позиции'
                    : 'Войдите, чтобы сохранять товары',
                'url' => $marketplaceCurrentUserCanUseBuyer
                    ? route('marketplace.buyer.favorites', ['marketSlug' => $marketSlug])
                    : route('marketplace.login', ['marketSlug' => $marketSlug]),
            ],
        ];
    @endphp

    <style>
        .mp-home {
            display: grid;
            gap: 20px;
        }

        .mp-home-hero {
            position: relative;
            overflow: hidden;
            border-radius: 30px;
            border: 1px solid rgba(105, 161, 224, .34);
            background:
                radial-gradient(circle at top left, rgba(132, 211, 255, .36), transparent 28%),
                radial-gradient(circle at bottom right, rgba(255, 153, 54, .18), transparent 26%),
                linear-gradient(135deg, #0d1e39 0%, #123969 45%, #0d8be0 100%);
            color: #fff;
            box-shadow: 0 24px 60px rgba(13, 37, 73, .24);
        }

        .mp-home-hero::before,
        .mp-home-hero::after {
            content: '';
            position: absolute;
            pointer-events: none;
        }

        .mp-home-hero::before {
            top: -120px;
            right: -40px;
            width: 320px;
            height: 320px;
            border-radius: 999px;
            background: linear-gradient(180deg, rgba(255, 255, 255, .22), rgba(255, 255, 255, 0));
        }

        .mp-home-hero::after {
            left: 45%;
            bottom: -92px;
            width: 210px;
            height: 210px;
            border-radius: 999px;
            border: 1px solid rgba(255, 255, 255, .18);
            background: rgba(255, 255, 255, .06);
            transform: translateX(-50%);
        }

        .mp-home-hero__grid {
            position: relative;
            z-index: 1;
            display: grid;
            grid-template-columns: minmax(0, 1.06fr) minmax(360px, .94fr);
            gap: 20px;
            padding: 28px;
        }

        .mp-home-hero__eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border-radius: 999px;
            border: 1px solid rgba(255, 255, 255, .2);
            background: rgba(255, 255, 255, .1);
            font-size: 12px;
            font-weight: 800;
            letter-spacing: .12em;
            text-transform: uppercase;
        }

        .mp-home-hero__title {
            margin: 16px 0 0;
            max-width: 760px;
            font-size: clamp(34px, 4.9vw, 56px);
            line-height: 1.02;
            letter-spacing: -.04em;
            font-weight: 900;
        }

        .mp-home-hero__subtitle {
            margin: 16px 0 0;
            max-width: 640px;
            color: rgba(237, 246, 255, .92);
            font-size: 17px;
            line-height: 1.6;
        }

        .mp-home-hero__actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 22px;
        }

        .mp-home-hero__btn {
            border-radius: 14px;
            padding: 12px 16px;
            font-weight: 800;
            box-shadow: none;
        }

        .mp-home-hero__btn--solid {
            background: #fff;
            border-color: transparent;
            color: #123261;
        }

        .mp-home-hero__btn--ghost {
            background: rgba(255, 255, 255, .1);
            color: #fff;
            border-color: rgba(255, 255, 255, .26);
        }

        .mp-home-pulse {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 18px;
        }

        .mp-home-pulse__item {
            min-width: 136px;
            padding: 12px 14px;
            border-radius: 16px;
            background: rgba(7, 20, 40, .25);
            border: 1px solid rgba(255, 255, 255, .12);
        }

        .mp-home-pulse__label {
            color: rgba(222, 236, 255, .8);
            font-size: 12px;
        }

        .mp-home-pulse__value {
            margin-top: 6px;
            font-size: 24px;
            line-height: 1;
            font-weight: 900;
        }

        .mp-home-showcase {
            display: grid;
            gap: 14px;
            align-content: start;
        }

        .mp-home-highlight {
            display: grid;
            grid-template-columns: 1.12fr .88fr;
            gap: 14px;
        }

        .mp-home-lead-card,
        .mp-home-mini-card,
        .mp-home-news-card {
            border-radius: 22px;
            border: 1px solid rgba(255, 255, 255, .16);
            background: rgba(8, 20, 40, .32);
            backdrop-filter: blur(10px);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, .04);
        }

        .mp-home-lead-card {
            overflow: hidden;
            display: grid;
            grid-template-rows: 210px auto;
            min-height: 100%;
        }

        .mp-home-lead-card__media {
            background: linear-gradient(160deg, rgba(255, 255, 255, .22), rgba(255, 255, 255, .06));
            overflow: hidden;
        }

        .mp-home-lead-card__media img {
            width: 100%;
            height: 100%;
            display: block;
            object-fit: cover;
        }

        .mp-home-lead-card__body {
            display: grid;
            gap: 10px;
            padding: 16px;
        }

        .mp-home-kicker {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            width: fit-content;
            padding: 6px 10px;
            border-radius: 999px;
            border: 1px solid rgba(255, 255, 255, .14);
            background: rgba(255, 255, 255, .11);
            color: #dcecff;
            font-size: 12px;
            font-weight: 700;
        }

        .mp-home-lead-card__title,
        .mp-home-mini-card__title {
            margin: 0;
            font-weight: 900;
            color: #fff;
            line-height: 1.14;
        }

        .mp-home-lead-card__title {
            font-size: 24px;
        }

        .mp-home-mini-card__title {
            font-size: 19px;
        }

        .mp-home-lead-card__meta {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            color: rgba(223, 238, 255, .86);
            font-size: 13px;
        }

        .mp-home-mini-stack {
            display: grid;
            gap: 12px;
        }

        .mp-home-mini-card {
            display: grid;
            gap: 10px;
            padding: 16px;
        }

        .mp-home-news-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px;
        }

        .mp-home-news-card {
            display: grid;
            gap: 8px;
            padding: 16px;
            color: #fff;
        }

        .mp-home-news-card__date {
            color: rgba(223, 238, 255, .78);
            font-size: 12px;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
        }

        .mp-home-news-card__title {
            margin: 0;
            font-size: 18px;
            line-height: 1.2;
            font-weight: 900;
        }

        .mp-home-news-card__text {
            color: rgba(236, 245, 255, .88);
            line-height: 1.5;
            font-size: 14px;
        }

        .mp-home-stats-strip {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 12px;
        }

        .mp-home-stat-link {
            display: grid;
            gap: 8px;
            padding: 16px 18px;
            border-radius: 20px;
            border: 1px solid #d7e4f5;
            background: linear-gradient(180deg, #ffffff, #f7fbff);
            box-shadow: 0 10px 24px rgba(15, 54, 96, .07);
            transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease;
        }

        .mp-home-stat-link:hover {
            transform: translateY(-2px);
            border-color: #a9caef;
            box-shadow: 0 14px 32px rgba(15, 54, 96, .14);
        }

        .mp-home-stat-link__label {
            color: #6680a2;
            font-size: 12px;
            font-weight: 800;
            letter-spacing: .12em;
            text-transform: uppercase;
        }

        .mp-home-stat-link__value {
            font-size: 34px;
            line-height: 1;
            font-weight: 900;
            color: #10294c;
        }

        .mp-home-stat-link__hint {
            color: #5d6b86;
            line-height: 1.45;
            font-size: 14px;
        }

        .mp-home-panel {
            display: grid;
            gap: 0;
            border-radius: 24px;
            border: 1px solid #d7e4f5;
            background: linear-gradient(180deg, #ffffff, #f8fbff);
            box-shadow: 0 12px 32px rgba(15, 54, 96, .08);
            overflow: hidden;
        }

        .mp-home-panel__head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            padding: 20px 22px 18px;
            border-bottom: 1px solid #e5eef9;
        }

        .mp-home-panel__eyebrow {
            color: #0a84d6;
            font-size: 12px;
            font-weight: 800;
            letter-spacing: .14em;
            text-transform: uppercase;
        }

        .mp-home-panel__title {
            margin: 6px 0 0;
            font-size: 28px;
            line-height: 1.08;
            letter-spacing: -.03em;
            font-weight: 900;
            color: #10294c;
        }

        .mp-home-panel__text {
            margin: 8px 0 0;
            max-width: 720px;
            color: #61718b;
            line-height: 1.58;
        }

        .mp-home-panel__body {
            padding: 20px;
        }

        .mp-home-slider {
            position: relative;
            display: grid;
            gap: 14px;
        }

        .mp-home-slider__viewport {
            overflow: hidden;
        }

        .mp-home-slider__track {
            display: flex;
            gap: 16px;
            transition: transform .35s ease;
            will-change: transform;
        }

        .mp-home-slide {
            min-width: min(100%, 460px);
            display: grid;
            grid-template-columns: minmax(0, 1fr) 180px;
            gap: 16px;
            align-items: stretch;
            border-radius: 22px;
            border: 1px solid #d9e6f7;
            background: linear-gradient(145deg, #f9fcff, #eff6ff);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, .7);
            padding: 18px;
        }

        .mp-home-slide__badge {
            display: inline-flex;
            align-items: center;
            width: fit-content;
            padding: 6px 10px;
            border-radius: 999px;
            background: #eaf5ff;
            color: #0f5c9c;
            font-size: 12px;
            font-weight: 800;
        }

        .mp-home-slide__title {
            margin: 10px 0 0;
            font-size: 26px;
            line-height: 1.1;
            font-weight: 900;
            color: #10294c;
        }

        .mp-home-slide__description {
            margin: 10px 0 0;
            color: #5d6b86;
            line-height: 1.56;
        }

        .mp-home-slide__media {
            border-radius: 18px;
            overflow: hidden;
            background: linear-gradient(155deg, #dff0ff, #b8dfff);
            min-height: 180px;
        }

        .mp-home-slide__media img {
            width: 100%;
            height: 100%;
            display: block;
            object-fit: cover;
        }

        .mp-home-slider__actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
        }

        .mp-home-slider__dots {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .mp-home-slider__dot {
            width: 10px;
            height: 10px;
            border-radius: 999px;
            border: none;
            background: #c7d8ef;
            cursor: pointer;
            padding: 0;
        }

        .mp-home-slider__dot.is-active {
            width: 28px;
            background: linear-gradient(140deg, #0a84d6, #10b2d8);
        }

        .mp-home-slider__nav {
            display: flex;
            gap: 8px;
        }

        .mp-home-slider__nav .mp-btn {
            border-radius: 999px;
            padding: 9px 12px;
        }

        .mp-home-signal {
            display: grid;
            gap: 12px;
            border-radius: 22px;
            border: 1px solid #ffd5b0;
            background: linear-gradient(145deg, #fff8ef, #fff3e5);
            box-shadow: 0 10px 26px rgba(255, 138, 0, .1);
            padding: 18px;
        }

        .mp-home-signal__eyebrow {
            color: #b86800;
            font-size: 12px;
            font-weight: 800;
            letter-spacing: .14em;
            text-transform: uppercase;
        }

        .mp-home-signal__title {
            margin: 0;
            font-size: 24px;
            line-height: 1.14;
            font-weight: 900;
            color: #10294c;
        }

        .mp-home-signal__text {
            color: #5f6f89;
            line-height: 1.56;
        }

        .mp-home-featured-grid,
        .mp-home-latest-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 14px;
        }

        .mp-home-store-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 14px;
        }

        .mp-home-store-card {
            display: grid;
            gap: 10px;
            padding: 18px;
            border-radius: 20px;
            border: 1px solid #d8e5f6;
            background: linear-gradient(180deg, #ffffff, #f7fbff);
            box-shadow: 0 10px 24px rgba(15, 54, 96, .07);
        }

        .mp-home-store-card__kicker {
            color: #6480a3;
            font-size: 12px;
            font-weight: 800;
            letter-spacing: .12em;
            text-transform: uppercase;
        }

        .mp-home-store-card__title {
            margin: 0;
            font-size: 22px;
            line-height: 1.12;
            font-weight: 900;
            color: #10294c;
        }

        .mp-home-store-card__text {
            color: #61718b;
            line-height: 1.5;
        }

        .mp-home-cta-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 14px;
        }

        .mp-home-cta-card {
            position: relative;
            overflow: hidden;
            display: grid;
            gap: 10px;
            padding: 20px;
            border-radius: 24px;
            border: 1px solid #d7e4f5;
            background: linear-gradient(180deg, #ffffff, #f7fbff);
            box-shadow: 0 12px 28px rgba(15, 54, 96, .08);
        }

        .mp-home-cta-card::after {
            content: '';
            position: absolute;
            right: -28px;
            bottom: -28px;
            width: 120px;
            height: 120px;
            border-radius: 999px;
            background: radial-gradient(circle, rgba(16, 178, 216, .16), transparent 68%);
            pointer-events: none;
        }

        .mp-home-cta-card__title {
            margin: 0;
            font-size: 22px;
            line-height: 1.16;
            font-weight: 900;
            color: #10294c;
        }

        .mp-home-cta-card__text {
            color: #5d6b86;
            line-height: 1.55;
        }

        @media (max-width: 1180px) {
            .mp-home-hero__grid,
            .mp-home-highlight,
            .mp-home-slide {
                grid-template-columns: 1fr;
            }

            .mp-home-featured-grid,
            .mp-home-latest-grid,
            .mp-home-store-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }

            .mp-home-news-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 900px) {
            .mp-home-stats-strip,
            .mp-home-cta-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .mp-home-featured-grid,
            .mp-home-latest-grid,
            .mp-home-store-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 720px) {
            .mp-home-hero__grid {
                padding: 20px;
            }

            .mp-home-pulse {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .mp-home-stats-strip,
            .mp-home-cta-grid,
            .mp-home-featured-grid,
            .mp-home-latest-grid,
            .mp-home-store-grid {
                grid-template-columns: 1fr;
            }

            .mp-home-stat-link__value {
                font-size: 28px;
            }
        }
    </style>

    <div class="mp-home">
        <section class="mp-home-hero">
            <div class="mp-home-hero__grid">
                <div>
                    <span class="mp-home-hero__eyebrow">ЭкоЯрмарка · Маркетплейс</span>
                    <h1 class="mp-home-hero__title">{{ $marketplaceSettings['hero_title'] }}</h1>
                    <p class="mp-home-hero__subtitle">{{ $marketplaceSettings['hero_subtitle'] }}</p>

                    <div class="mp-home-hero__actions">
                        <a class="mp-btn mp-home-hero__btn mp-home-hero__btn--solid"
                           href="{{ route('marketplace.catalog', ['marketSlug' => $marketSlug]) }}">
                            Смотреть каталог
                        </a>
                        <a class="mp-btn mp-home-hero__btn mp-home-hero__btn--ghost"
                           href="{{ route('marketplace.map', ['marketSlug' => $marketSlug]) }}">
                            Карта рынка
                        </a>
                        @if($marketplaceCurrentUserCanUseBuyer)
                            <a class="mp-btn mp-home-hero__btn mp-home-hero__btn--ghost"
                               href="{{ route('marketplace.buyer.dashboard', ['marketSlug' => $marketSlug]) }}">
                                Кабинет покупателя
                            </a>
                        @endif
                    </div>

                    <div class="mp-home-pulse">
                        @if(filled($publicAddress))
                            <div class="mp-home-pulse__item">
                                <div class="mp-home-pulse__label">Адрес</div>
                                <div class="mp-home-pulse__value" style="font-size:16px;line-height:1.35;">{{ $publicAddress }}</div>
                            </div>
                        @endif
                        @if(filled($publicPhone))
                            <div class="mp-home-pulse__item">
                                <div class="mp-home-pulse__label">Контакт</div>
                                <div class="mp-home-pulse__value" style="font-size:18px;">{{ $publicPhone }}</div>
                            </div>
                        @endif
                        <div class="mp-home-pulse__item">
                            <div class="mp-home-pulse__label">Витрины продавцов</div>
                            <div class="mp-home-pulse__value">{{ $topStores->count() }}</div>
                        </div>
                        <div class="mp-home-pulse__item">
                            <div class="mp-home-pulse__label">Новых товаров</div>
                            <div class="mp-home-pulse__value">{{ $latestProducts->count() }}</div>
                        </div>
                    </div>
                </div>

                <div class="mp-home-showcase">
                    <div class="mp-home-highlight">
                        <article class="mp-home-lead-card">
                            <div class="mp-home-lead-card__media">
                                @if($featuredLead && is_array($featuredLead->images ?? null) && !empty($featuredLead->images[0]))
                                    <img src="{{ $featuredLead->images[0] }}" alt="{{ $featuredLead->title }}">
                                @endif
                            </div>
                            <div class="mp-home-lead-card__body">
                                <span class="mp-home-kicker">Рекомендуем</span>
                                <h2 class="mp-home-lead-card__title">{{ $featuredLead?->title ?: 'Новые предложения от продавцов ЭкоЯрмарки' }}</h2>
                                <div class="mp-home-lead-card__meta">
                                    @if($featuredLead)
                                        <span>{{ $featuredLead->tenant->short_name ?: $featuredLead->tenant->name }}</span>
                                        @if($featuredLead->price !== null)
                                            <span>{{ number_format((float) $featuredLead->price, 2, ',', ' ') }} ₽</span>
                                        @endif
                                    @else
                                        <span>Товары, витрины и события в одном окне</span>
                                    @endif
                                </div>
                                <div style="display:flex;gap:10px;flex-wrap:wrap;">
                                    @if($featuredLead)
                                        <a class="mp-btn mp-home-hero__btn mp-home-hero__btn--solid"
                                           href="{{ route('marketplace.product.show', ['marketSlug' => $marketSlug, 'productSlug' => $featuredLead->slug]) }}">
                                            Открыть товар
                                        </a>
                                    @endif
                                    <a class="mp-btn mp-home-hero__btn mp-home-hero__btn--ghost"
                                       href="{{ route('marketplace.catalog', ['marketSlug' => $marketSlug]) }}">
                                        Все предложения
                                    </a>
                                </div>
                            </div>
                        </article>

                        <div class="mp-home-mini-stack">
                            @foreach($featuredSecondary as $product)
                                <article class="mp-home-mini-card">
                                    <span class="mp-home-kicker">В фокусе</span>
                                    <h3 class="mp-home-mini-card__title">{{ $product->title }}</h3>
                                    <div class="mp-home-lead-card__meta">
                                        <span>{{ $product->tenant->short_name ?: $product->tenant->name }}</span>
                                        @if($product->price !== null)
                                            <span>{{ number_format((float) $product->price, 2, ',', ' ') }} ₽</span>
                                        @endif
                                    </div>
                                    <a class="mp-btn mp-home-hero__btn mp-home-hero__btn--ghost"
                                       href="{{ route('marketplace.product.show', ['marketSlug' => $marketSlug, 'productSlug' => $product->slug]) }}">
                                        Смотреть карточку
                                    </a>
                                </article>
                            @endforeach
                            @if($featuredSecondary->isEmpty())
                                <article class="mp-home-mini-card">
                                    <span class="mp-home-kicker">Навигация</span>
                                    <h3 class="mp-home-mini-card__title">Открывайте магазины, каталог и карту рынка без лишних переходов</h3>
                                    <div class="mp-home-lead-card__meta">
                                        <span>Главная собрана как витрина: смотреть, сравнивать, быстро выбирать.</span>
                                    </div>
                                </article>
                            @endif
                        </div>
                    </div>

                    <div class="mp-home-announcement-list">
                        @forelse($headlineAnnouncements as $announcement)
                            <article class="mp-home-announcement-card">
                                <span class="mp-home-kicker">{{ $announcement->category?->label() ?? 'Сообщение' }}</span>
                                <h3 class="mp-home-announcement-card__title">{{ $announcement->title }}</h3>
                                <p class="mp-home-announcement-card__text">
                                    {{ Str::limit(strip_tags($announcement->body ?? ''), 120) ?: 'Открыть карточку объявления и посмотреть детали.' }}
                                </p>
                                <a class="mp-btn mp-home-hero__btn mp-home-hero__btn--ghost"
                                   href="{{ route('marketplace.announcement.show', ['marketSlug' => $marketSlug, 'announcementSlug' => $announcement->slug]) }}">
                                    Читать
                                </a>
                            </article>
                        @empty
                            <article class="mp-home-announcement-card">
                                <span class="mp-home-kicker">Инфо-блок</span>
                                <h3 class="mp-home-announcement-card__title">Актуальные объявления рынка будут появляться здесь</h3>
                                <p class="mp-home-announcement-card__text">
                                    Пока список короткий, но витрина уже готова под новости, санитарные объявления и акции.
                                </p>
                            </article>
                        @endforelse
                    </div>
                </div>
            </div>
        </section>

        <section class="mp-home-strip">
            @foreach($heroStats as $stat)
                <article class="mp-home-strip-card">
                    <div class="mp-home-strip-card__label">{{ $stat['label'] }}</div>
                    <div class="mp-home-strip-card__value">{{ $stat['value'] }}</div>
                </article>
            @endforeach
        </section>

        @if($slides->isNotEmpty())
            <section class="mp-home-section">
                <div class="mp-home-section__header">
                    <div>
                        <span class="mp-home-kicker">Промо и навигация</span>
                        <h2 class="mp-home-section__title">Информационные и промо-слайды</h2>
                    </div>
                    @if($slides->count() > 1)
                        <div class="mp-home-slider__controls">
                            <button type="button" class="mp-home-slider__nav" data-slide-prev aria-label="Предыдущий слайд">‹</button>
                            <button type="button" class="mp-home-slider__nav" data-slide-next aria-label="Следующий слайд">›</button>
                        </div>
                    @endif
                </div>

                <div class="mp-home-slider" data-slider>
                    <div class="mp-home-slider__track" data-slider-track>
                        @foreach($slides as $slide)
                            <article class="mp-home-slide">
                                <div class="mp-home-slide__content">
                                    @if(!empty($slide['badge']))
                                        <span class="mp-home-kicker">{{ $slide['badge'] }}</span>
                                    @endif
                                    <h3 class="mp-home-slide__title">{{ $slide['title'] }}</h3>
                                    @if(!empty($slide['description']))
                                        <p class="mp-home-slide__text">{{ $slide['description'] }}</p>
                                    @endif
                                    @if(!empty($slide['cta_url']) && !empty($slide['cta_label']))
                                        <a class="mp-btn mp-home-hero__btn mp-home-hero__btn--solid" href="{{ $slide['cta_url'] }}">
                                            {{ $slide['cta_label'] }}
                                        </a>
                                    @endif
                                </div>
                                @if(!empty($slide['image_url']))
                                    <div class="mp-home-slide__media">
                                        <img src="{{ $slide['image_url'] }}" alt="{{ $slide['title'] }}">
                                    </div>
                                @endif
                            </article>
                        @endforeach
                    </div>

                    @if($slides->count() > 1)
                        <div class="mp-home-slider__dots">
                            @foreach($slides as $index => $slide)
                                <button type="button"
                                        class="mp-home-slider__dot{{ $index === 0 ? ' is-active' : '' }}"
                                        data-slide-dot="{{ $index }}"
                                        aria-label="Слайд {{ $index + 1 }}"></button>
                            @endforeach
                        </div>
                    @endif
                </div>
            </section>
        @endif

        @if($nearestSanitaryAnnouncement)
            <section class="mp-home-signal">
                <div>
                    <span class="mp-home-kicker">Важное объявление</span>
                    <h2 class="mp-home-section__title">{{ $nearestSanitaryAnnouncement->title }}</h2>
                    <p class="mp-home-signal__text">
                        {{ Str::limit(strip_tags($nearestSanitaryAnnouncement->body ?? ''), 220) }}
                    </p>
                </div>
                <a class="mp-btn mp-home-hero__btn mp-home-hero__btn--solid"
                   href="{{ route('marketplace.announcement.show', ['marketSlug' => $marketSlug, 'announcementSlug' => $nearestSanitaryAnnouncement->slug]) }}">
                    Открыть объявление
                </a>
            </section>
        @endif

        <section class="mp-home-grid">
            <article class="mp-home-panel">
                <div class="mp-home-section__header">
                    <div>
                        <span class="mp-home-kicker">В центре внимания</span>
                        <h2 class="mp-home-section__title">Популярные предложения</h2>
                    </div>
                    <a class="mp-home-inline-link" href="{{ route('marketplace.catalog', ['marketSlug' => $marketSlug]) }}">В каталог</a>
                </div>

                <div class="mp-home-products">
                    @forelse($featuredProducts as $product)
                        @include('marketplace.partials.product-card', ['product' => $product])
                    @empty
                        <div class="mp-home-empty">
                            <h3>Пока нет выделенных предложений</h3>
                            <p>Когда продавцы добавят витрины и публикации, здесь появятся главные карточки маркетплейса.</p>
                        </div>
                    @endforelse
                </div>
            </article>

            <article class="mp-home-panel">
                <div class="mp-home-section__header">
                    <div>
                        <span class="mp-home-kicker">Продавцы</span>
                        <h2 class="mp-home-section__title">Магазины и арендаторы</h2>
                    </div>
                    <a class="mp-home-inline-link" href="{{ route('marketplace.catalog', ['marketSlug' => $marketSlug, 'tab' => 'stores']) }}">Все магазины</a>
                </div>

                <div class="mp-home-store-list">
                    @forelse($topStoresPreview as $store)
                        <a class="mp-home-store-card" href="{{ route('marketplace.store.show', ['marketSlug' => $marketSlug, 'tenantSlug' => $store->slug ?: $store->getKey()]) }}">
                            <div class="mp-home-store-card__title">{{ $store->short_name ?: $store->name }}</div>
                            <div class="mp-home-store-card__meta">
                                <span>{{ $store->products_count }} товаров</span>
                                @if($store->marketSpace)
                                    <span>{{ $store->marketSpace->name ?: $store->marketSpace->number }}</span>
                                @endif
                            </div>
                        </a>
                    @empty
                        <div class="mp-home-empty">
                            <h3>Магазины скоро появятся</h3>
                            <p>Когда продавцы оформят витрины, здесь появится короткий каталог арендаторов рынка.</p>
                        </div>
                    @endforelse
                </div>
            </article>
        </section>

        <section class="mp-home-grid mp-home-grid--secondary">
            <article class="mp-home-panel">
                <div class="mp-home-section__header">
                    <div>
                        <span class="mp-home-kicker">Объявления</span>
                        <h2 class="mp-home-section__title">Что важно знать перед визитом</h2>
                    </div>
                    <a class="mp-home-inline-link" href="{{ route('marketplace.announcements', ['marketSlug' => $marketSlug]) }}">Все объявления</a>
                </div>

                <div class="mp-home-info-list">
                    @forelse($announcements as $announcement)
                        <a class="mp-home-info-card" href="{{ route('marketplace.announcement.show', ['marketSlug' => $marketSlug, 'announcementSlug' => $announcement->slug]) }}">
                            <div class="mp-home-info-card__eyebrow">
                                <span>{{ $announcement->category?->label() ?? 'Объявление' }}</span>
                                <span>{{ optional($announcement->starts_at)->format('d.m.Y') }}</span>
                            </div>
                            <h3>{{ $announcement->title }}</h3>
                            <p>{{ Str::limit(strip_tags($announcement->body ?? ''), 150) }}</p>
                        </a>
                    @empty
                        <div class="mp-home-empty">
                            <h3>Нет свежих объявлений</h3>
                            <p>Когда рынок опубликует новости или напоминания, они появятся здесь.</p>
                        </div>
                    @endforelse
                </div>
            </article>

            <article class="mp-home-panel">
                <div class="mp-home-section__header">
                    <div>
                        <span class="mp-home-kicker">Свежие поступления</span>
                        <h2 class="mp-home-section__title">Новые товары</h2>
                    </div>
                    <a class="mp-home-inline-link" href="{{ route('marketplace.catalog', ['marketSlug' => $marketSlug, 'sort' => 'new']) }}">Смотреть всё</a>
                </div>

                <div class="mp-home-products mp-home-products--compact">
                    @forelse($latestCards as $product)
                        @include('marketplace.partials.product-card', ['product' => $product])
                    @empty
                        <div class="mp-home-empty">
                            <h3>Свежих карточек пока нет</h3>
                            <p>Как только продавцы обновят витрины, здесь появятся новые товары.</p>
                        </div>
                    @endforelse
                </div>
            </article>
        </section>

        <section class="mp-home-cta-grid">
            <a class="mp-home-cta-card" href="{{ route('marketplace.catalog', ['marketSlug' => $marketSlug]) }}">
                <span class="mp-home-kicker">Каталог</span>
                <h3>Искать по товарам и продавцам</h3>
                <p>Полная витрина предложений рынка с фильтрами, категориями и переходом в карточки продавцов.</p>
            </a>

            <a class="mp-home-cta-card" href="{{ route('marketplace.map', ['marketSlug' => $marketSlug]) }}">
                <span class="mp-home-kicker">Карта</span>
                <h3>Найти продавца на схеме рынка</h3>
                <p>Переход к карте рынка, где можно быстро найти магазины и понять локацию перед визитом.</p>
            </a>

            <a class="mp-home-cta-card" href="{{ route('marketplace.announcements', ['marketSlug' => $marketSlug]) }}">
                <span class="mp-home-kicker">Информация</span>
                <h3>Следить за объявлениями и акциями</h3>
                <p>Санитарные уведомления, режим работы, промо и общие новости — всё в отдельной ленте.</p>
            </a>

            <a class="mp-home-cta-card" href="{{ $marketplaceCurrentUserCanUseBuyer ? route('marketplace.buyer.dashboard', ['marketSlug' => $marketSlug]) : route('marketplace.login', ['marketSlug' => $marketSlug]) }}">
                <span class="mp-home-kicker">Кабинет</span>
                <h3>Сохранять избранное и возвращаться к подборке</h3>
                <p>Личный кабинет покупателя с избранными товарами, быстрыми переходами и будущими заявками.</p>
            </a>
        </section>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const slider = document.querySelector('[data-slider]');

            if (!slider) {
                return;
            }

            const track = slider.querySelector('[data-slider-track]');
            const dots = Array.from(slider.querySelectorAll('[data-slide-dot]'));
            const nextBtn = slider.querySelector('[data-slide-next]');
            const prevBtn = slider.querySelector('[data-slide-prev]');

            if (!track) {
                return;
            }

            const slides = Array.from(track.children);
            let activeIndex = 0;

            const render = () => {
                track.style.transform = `translateX(-${activeIndex * 100}%)`;

                dots.forEach((dot, index) => {
                    dot.classList.toggle('is-active', index === activeIndex);
                });
            };

            nextBtn?.addEventListener('click', () => {
                activeIndex = (activeIndex + 1) % slides.length;
                render();
            });

            prevBtn?.addEventListener('click', () => {
                activeIndex = (activeIndex - 1 + slides.length) % slides.length;
                render();
            });

            dots.forEach((dot, index) => {
                dot.addEventListener('click', () => {
                    activeIndex = index;
                    render();
                });
            });

            render();
        });
    </script>
@endpush
