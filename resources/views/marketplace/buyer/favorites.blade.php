@extends('marketplace.layout')

@section('title', 'Избранное')

@section('content')
    <section class="mp-card">
        <div class="mp-page-head">
            <div>
                <h1 class="mp-page-title">Избранные товары</h1>
                <p class="mp-page-sub">Список сохранённых предложений.</p>
            </div>
        </div>

        @if($products->count() === 0)
            <p class="mp-muted" style="margin:0;">
                Избранное пока пусто.
                <a href="{{ route('marketplace.catalog', ['marketSlug' => $market->slug]) }}" style="color:#0a84d6;font-weight:700;">Открыть каталог</a>
            </p>
        @else
            <div class="mp-grid">
                @foreach($products as $product)
                    @include('marketplace.partials.product-card', ['product' => $product])
                @endforeach
            </div>
            <div style="margin-top:14px;">
                {{ $products->links() }}
            </div>
        @endif
    </section>
@endsection
