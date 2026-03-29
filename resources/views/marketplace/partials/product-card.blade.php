@php
    $image = null;
    if (is_array($product->images ?? null) && !empty($product->images[0])) {
        $image = \App\Support\MarketplaceMediaStorage::previewUrl($product->images[0]);
    }

    $title = trim((string) ($product->title ?? 'Товар'));
    $price = $product->price !== null ? number_format((float) $product->price, 2, ',', ' ') . ' ₽' : 'Цена по запросу';
@endphp

<article style="background:#fff;border:1px solid #d9e6f7;border-radius:14px;overflow:hidden;display:flex;flex-direction:column;">
    <a href="{{ route('marketplace.product.show', ['marketSlug' => $market->slug, 'productSlug' => $product->slug]) }}"
       style="display:block;height:clamp(200px, 18vw, 260px);background:#eef4fb;border-bottom:1px solid #d9e6f7;overflow:hidden;">
        @if($image)
            <img src="{{ $image }}" alt="{{ $title }}" style="width:100%;height:100%;object-fit:cover;" loading="lazy" decoding="async">
        @else
            <div style="width:100%;height:100%;display:grid;place-items:center;color:#7f93b3;font-weight:600;">Нет фото</div>
        @endif
    </a>
    <div style="padding:12px;display:flex;flex-direction:column;gap:8px;">
        <a href="{{ route('marketplace.product.show', ['marketSlug' => $market->slug, 'productSlug' => $product->slug]) }}"
           style="font-weight:700;line-height:1.25;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;">
            {{ $title }}
        </a>
        <div style="font-size:24px;font-weight:800;color:#10294c;">{{ $price }}</div>
        <div style="font-size:13px;color:#5f7392;display:flex;justify-content:space-between;gap:8px;">
            <span>{{ $product->tenant->short_name ?: $product->tenant->name }}</span>
            <span>👁 {{ (int) ($product->views_count ?? 0) }}</span>
        </div>
    </div>
</article>
