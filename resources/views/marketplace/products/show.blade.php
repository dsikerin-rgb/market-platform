@extends('marketplace.layout')

@section('title', $product->title)

@section('content')
    @php($tenantRouteKey = filled($product->tenant->slug ?? null) ? (string) $product->tenant->slug : (string) $product->tenant->id)
    <section class="mp-card" style="display:grid;grid-template-columns:1.1fr .9fr;gap:16px;">
        <div style="background:#eef4fb;border:1px solid #d7e7f8;border-radius:14px;overflow:hidden;min-height:320px;">
            @if(is_array($product->images ?? null) && !empty($product->images[0]))
                <img src="{{ \App\Support\MarketplaceMediaStorage::url($product->images[0]) }}" alt="{{ $product->title }}" style="width:100%;height:100%;object-fit:cover;">
            @else
                <div style="height:100%;display:grid;place-items:center;color:#6e84a6;font-weight:700;">Фото отсутствует</div>
            @endif
        </div>
        <div>
            <div class="mp-muted" style="font-size:12px;margin-bottom:6px;">{{ $product->category->name ?? 'Без категории' }}</div>
            <h1 class="mp-page-title" style="font-size:30px;">{{ $product->title }}</h1>
            <div style="font-size:34px;font-weight:900;margin:10px 0 14px;">
                {{ $product->price !== null ? number_format((float) $product->price, 2, ',', ' ') . ' ₽' : 'Цена по запросу' }}
            </div>
            <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin-bottom:14px;">
                <div style="padding:10px;border:1px solid #d7e7f8;border-radius:12px;">
                    <div class="mp-muted" style="font-size:12px;">Магазин</div>
                    <a href="{{ route('marketplace.store.show', ['marketSlug' => $market->slug, 'tenantSlug' => $tenantRouteKey]) }}" style="font-weight:700;">
                        {{ $product->tenant->short_name ?: $product->tenant->name }}
                    </a>
                </div>
                <div style="padding:10px;border:1px solid #d7e7f8;border-radius:12px;">
                    <div class="mp-muted" style="font-size:12px;">Торговое место</div>
                    <div style="font-weight:700;">
                        {{ $product->marketSpace?->display_name ?: ($product->marketSpace?->number ?: ($product->marketSpace?->code ?: 'Не указано')) }}
                    </div>
                </div>
            </div>

            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                @if($marketplaceCurrentUserIsBuyer)
                    <form method="post" action="{{ route('marketplace.favorite.toggle', ['marketSlug' => $market->slug, 'productSlug' => $product->slug]) }}">
                        @csrf
                        <button class="mp-btn {{ $favoriteExists ? '' : 'mp-btn-brand' }}" type="submit">
                            {{ $favoriteExists ? 'Убрать из избранного' : 'В избранное' }}
                        </button>
                    </form>
                    <form method="post" action="{{ route('marketplace.buyer.chat.start', ['marketSlug' => $market->slug, 'tenantSlug' => $tenantRouteKey]) }}">
                        @csrf
                        <input type="hidden" name="product_slug" value="{{ $product->slug }}">
                        <input type="hidden" name="space_id" value="{{ (int) ($product->market_space_id ?? 0) }}">
                        <input type="hidden" name="message" value="Здравствуйте! Интересует товар: {{ $product->title }}">
                        <button class="mp-btn mp-btn-brand" type="submit">Написать продавцу</button>
                    </form>
                @else
                    <a class="mp-btn mp-btn-brand" href="{{ route('marketplace.login', ['marketSlug' => $market->slug]) }}">Войти и написать продавцу</a>
                @endif
            </div>
        </div>
    </section>

    <section class="mp-card">
        <h2 style="margin-top:0;">Описание</h2>
        <p style="margin:0;line-height:1.6;white-space:pre-wrap;">{{ trim((string) $product->description) !== '' ? $product->description : 'Описание пока не заполнено.' }}</p>
    </section>

    <section class="mp-card">
        <div class="mp-page-head">
            <div>
                <h2 class="mp-page-title" style="font-size:24px;">Отзывы</h2>
                <p class="mp-page-sub">Оценки покупателей по этому продавцу.</p>
            </div>
            <a class="mp-btn" href="{{ route('marketplace.store.show', ['marketSlug' => $market->slug, 'tenantSlug' => $tenantRouteKey, 'space_id' => (int) ($product->market_space_id ?? 0)]) }}">
                Все отзывы магазина
            </a>
        </div>
        @if($reviews->count() === 0)
            <p class="mp-muted">Пока нет отзывов.</p>
        @else
            <div style="display:grid;gap:10px;">
                @foreach($reviews as $review)
                    <article style="border:1px solid #d7e7f8;border-radius:12px;padding:12px;">
                        <div style="display:flex;justify-content:space-between;gap:10px;align-items:center;">
                            <strong>{{ $review->reviewer_name ?: 'Покупатель' }}</strong>
                            <span style="font-weight:800;color:#f39a00;">{{ str_repeat('★', (int) $review->rating) }}</span>
                        </div>
                        <p class="mp-muted" style="margin:8px 0 0;">{{ $review->review_text }}</p>
                    </article>
                @endforeach
            </div>
        @endif
    </section>

    @if($relatedProducts->count() > 0)
        <section class="mp-card">
            <h2 style="margin-top:0;">Похожие товары</h2>
            <div class="mp-grid">
                @foreach($relatedProducts as $p)
                    @include('marketplace.partials.product-card', ['product' => $p])
                @endforeach
            </div>
        </section>
    @endif

    <style>
        @media (max-width: 860px) {
            section.mp-card[style*="grid-template-columns:1.1fr .9fr"] { grid-template-columns: 1fr !important; }
        }
    </style>
@endsection
