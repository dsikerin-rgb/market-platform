@php
    $image = null;
    if (is_array($product->images ?? null) && !empty($product->images[0])) {
        $image = \App\Support\MarketplaceMediaStorage::previewUrl($product->images[0]);
    }

    $title = trim((string) ($product->title ?? 'Товар'));
    $price = $product->price !== null ? number_format((float) $product->price, 2, ',', ' ') . ' ₽' : 'Цена по запросу';
@endphp

<article class="mp-product-card">
    <a href="{{ route('marketplace.product.show', ['marketSlug' => $market->slug, 'productSlug' => $product->slug]) }}"
       class="mp-product-card__media">
        @if($image)
            <img src="{{ $image }}" alt="{{ $title }}" class="mp-product-card__image" loading="lazy" decoding="async">
        @else
            <div class="mp-product-card__placeholder">Нет фото</div>
        @endif
    </a>
    <div class="mp-product-card__body">
        <a href="{{ route('marketplace.product.show', ['marketSlug' => $market->slug, 'productSlug' => $product->slug]) }}"
           class="mp-product-card__title">
            {{ $title }}
        </a>
        <div class="mp-product-card__price">{{ $price }}</div>
        <div class="mp-product-card__meta">
            <span>{{ $product->tenant->short_name ?: $product->tenant->name }}</span>
            <span>👁 {{ (int) ($product->views_count ?? 0) }}</span>
        </div>
    </div>
</article>
