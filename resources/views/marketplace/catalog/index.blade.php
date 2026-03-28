@extends('marketplace.layout')

@section('title', 'Каталог')

@section('content')
    <section class="mp-card">
        <div class="mp-page-head">
            <div>
                <h1 class="mp-page-title">Каталог товаров</h1>
                <p class="mp-page-sub">Фильтруйте по категориям, магазинам и цене.</p>
            </div>
            <span class="mp-badge">Найдено: {{ $products->total() }}</span>
        </div>

        <form method="get" action="{{ route('marketplace.catalog', ['marketSlug' => $market->slug]) }}"
              style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr 1fr auto;gap:10px;align-items:end;">
            <label style="display:flex;flex-direction:column;gap:6px;">
                <span class="mp-muted">Поиск</span>
                <input type="text" name="q" value="{{ $search }}" placeholder="Название, описание, SKU"
                       style="border:1px solid #cbdcf3;border-radius:12px;padding:10px 12px;">
            </label>
            <label style="display:flex;flex-direction:column;gap:6px;">
                <span class="mp-muted">Категория</span>
                <select name="category" style="border:1px solid #cbdcf3;border-radius:12px;padding:10px 12px;">
                    <option value="">Все</option>
                    @foreach($categories as $category)
                        <option value="{{ $category->slug }}" {{ $selectedCategory && $selectedCategory->id === $category->id ? 'selected' : '' }}>
                            {{ $category->parent_id ? '-- ' : '' }}{{ $category->name }}
                        </option>
                    @endforeach
                </select>
            </label>
            <label style="display:flex;flex-direction:column;gap:6px;">
                <span class="mp-muted">Магазин</span>
                <select name="store" style="border:1px solid #cbdcf3;border-radius:12px;padding:10px 12px;">
                    <option value="">Все</option>
                    @foreach($stores as $store)
                        @php($storeFilterKey = filled($store->slug ?? null) ? (string) $store->slug : (string) $store->id)
                        <option value="{{ $storeFilterKey }}" {{ $selectedStore && $selectedStore->id === $store->id ? 'selected' : '' }}>
                            {{ $store->short_name ?: $store->name }}
                        </option>
                    @endforeach
                </select>
            </label>
            <label style="display:flex;flex-direction:column;gap:6px;">
                <span class="mp-muted">Цена от</span>
                <input type="number" name="min_price" value="{{ $minPrice }}"
                       style="border:1px solid #cbdcf3;border-radius:12px;padding:10px 12px;">
            </label>
            <label style="display:flex;flex-direction:column;gap:6px;">
                <span class="mp-muted">Цена до</span>
                <input type="number" name="max_price" value="{{ $maxPrice }}"
                       style="border:1px solid #cbdcf3;border-radius:12px;padding:10px 12px;">
            </label>
            <label style="display:flex;flex-direction:column;gap:6px;">
                <span class="mp-muted">Сортировка</span>
                <select name="sort" style="border:1px solid #cbdcf3;border-radius:12px;padding:10px 12px;">
                    <option value="new" {{ $sort === 'new' ? 'selected' : '' }}>Сначала новые</option>
                    <option value="popular" {{ $sort === 'popular' ? 'selected' : '' }}>По популярности</option>
                    <option value="price_asc" {{ $sort === 'price_asc' ? 'selected' : '' }}>Цена по возрастанию</option>
                    <option value="price_desc" {{ $sort === 'price_desc' ? 'selected' : '' }}>Цена по убыванию</option>
                </select>
            </label>
            <button class="mp-btn mp-btn-brand" type="submit">Применить</button>
        </form>
    </section>

    <section class="mp-card">
        @if($products->count() === 0)
            <p class="mp-muted" style="margin:0;">По выбранным фильтрам ничего не найдено.</p>
        @else
            <div class="mp-grid">
                @foreach($products as $product)
                    @include('marketplace.partials.product-card', ['product' => $product])
                @endforeach
            </div>
            <div style="margin-top:14px;">
                {{ $products->links('marketplace.partials.pagination') }}
            </div>
        @endif
    </section>

    <style>
        @media (max-width: 1100px) {
            form[action*="catalog"] { grid-template-columns: repeat(3, minmax(0,1fr)) !important; }
        }
        @media (max-width: 760px) {
            form[action*="catalog"] { grid-template-columns: 1fr !important; }
        }
    </style>
@endsection
