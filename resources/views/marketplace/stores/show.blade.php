@extends('marketplace.layout')

@section('title', $tenant->display_name ?? $tenant->name)

@section('content')
    @php($tenantRouteKey = filled($tenant->slug ?? null) ? (string) $tenant->slug : (string) $tenant->id)
    <section class="mp-card">
        <div class="mp-page-head">
            <div>
                <h1 class="mp-page-title">{{ $tenant->display_name ?? $tenant->name }}</h1>
                <p class="mp-page-sub">{{ $showcase->description ?? 'Публичная витрина арендатора. Вы можете выбрать торговое место и написать продавцу.' }}</p>
            </div>
            @if($reviewStats['count'] > 0)
                <span class="mp-badge">★ {{ number_format((float) $reviewStats['avg'], 1, ',', ' ') }} ({{ $reviewStats['count'] }})</span>
            @endif
        </div>

        <div style="display:grid;grid-template-columns:1fr auto;gap:12px;align-items:end;">
            <form method="get" action="{{ route('marketplace.store.show', ['marketSlug' => $market->slug, 'tenantSlug' => $tenantRouteKey]) }}"
                  style="display:flex;gap:10px;align-items:end;flex-wrap:wrap;">
                <label style="display:flex;flex-direction:column;gap:6px;min-width:260px;">
                    <span class="mp-muted">Торговое место</span>
                    <select name="space_id" style="border:1px solid #cbdcf3;border-radius:12px;padding:10px 12px;">
                        <option value="0">Все места</option>
                        @foreach($spaces as $space)
                            @php
                                $spaceLabel = trim((string) ($space->display_name ?: ($space->number ?: $space->code)));
                            @endphp
                            <option value="{{ $space->id }}" {{ $selectedSpaceId === (int) $space->id ? 'selected' : '' }}>
                                {{ $spaceLabel !== '' ? $spaceLabel : ('#' . $space->id) }}
                            </option>
                        @endforeach
                    </select>
                </label>
                <button class="mp-btn mp-btn-brand" type="submit">Показать</button>
            </form>

            @if($marketplaceCurrentUserIsBuyer)
                <form method="post" action="{{ route('marketplace.buyer.chat.start', ['marketSlug' => $market->slug, 'tenantSlug' => $tenantRouteKey]) }}">
                    @csrf
                    <input type="hidden" name="space_id" value="{{ $selectedSpaceId > 0 ? $selectedSpaceId : '' }}">
                    <input type="hidden" name="message" value="Здравствуйте! Хочу уточнить информацию по вашим товарам.">
                    <button class="mp-btn mp-btn-brand" type="submit">Написать продавцу</button>
                </form>
            @else
                <a class="mp-btn mp-btn-brand" href="{{ route('marketplace.login', ['marketSlug' => $market->slug]) }}">Войти и написать</a>
            @endif
        </div>
    </section>

    <section class="mp-card">
        <div class="mp-page-head">
            <div>
                <h2 class="mp-page-title" style="font-size:24px;">Товары магазина</h2>
                <p class="mp-page-sub">Доступные предложения по выбранному торговому месту.</p>
            </div>
            <span class="mp-badge">Всего: {{ $products->total() }}</span>
        </div>
        @if($products->count() === 0)
            <p class="mp-muted">По выбранному месту товары пока не опубликованы.</p>
        @else
            <div class="mp-grid">
                @foreach($products as $product)
                    @include('marketplace.partials.product-card', ['product' => $product])
                @endforeach
            </div>
            <div style="margin-top:14px;">{{ $products->links() }}</div>
        @endif
    </section>

    <section class="mp-card" id="reviews">
        <div class="mp-page-head">
            <div>
                <h2 class="mp-page-title" style="font-size:24px;">Отзывы</h2>
                <p class="mp-page-sub">Покупатели могут оставить оценку от 1 до 5.</p>
            </div>
        </div>

        <form method="post" action="{{ route('marketplace.store.review', ['marketSlug' => $market->slug, 'tenantSlug' => $tenantRouteKey]) }}"
              style="display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:10px;align-items:end;margin-bottom:14px;">
            @csrf
            <input type="hidden" name="market_space_id" value="{{ $selectedSpaceId > 0 ? $selectedSpaceId : '' }}">
            <label style="display:flex;flex-direction:column;gap:6px;">
                <span class="mp-muted">Имя</span>
                <input type="text" name="reviewer_name" value="{{ old('reviewer_name') }}"
                       style="border:1px solid #cbdcf3;border-radius:12px;padding:10px 12px;">
            </label>
            <label style="display:flex;flex-direction:column;gap:6px;">
                <span class="mp-muted">Контакт</span>
                <input type="text" name="reviewer_contact" value="{{ old('reviewer_contact') }}" placeholder="Email или телефон"
                       style="border:1px solid #cbdcf3;border-radius:12px;padding:10px 12px;">
            </label>
            <label style="display:flex;flex-direction:column;gap:6px;">
                <span class="mp-muted">Оценка</span>
                <select name="rating" style="border:1px solid #cbdcf3;border-radius:12px;padding:10px 12px;">
                    @for($i = 5; $i >= 1; $i--)
                        <option value="{{ $i }}" {{ (int) old('rating', 5) === $i ? 'selected' : '' }}>{{ $i }} ★</option>
                    @endfor
                </select>
            </label>
            <button class="mp-btn mp-btn-brand" type="submit">Отправить отзыв</button>
            <label style="grid-column:1/-1;display:flex;flex-direction:column;gap:6px;">
                <span class="mp-muted">Текст отзыва</span>
                <textarea name="review_text" rows="4" required
                          style="border:1px solid #cbdcf3;border-radius:12px;padding:10px 12px;">{{ old('review_text') }}</textarea>
            </label>
        </form>

        @if($reviews->count() === 0)
            <p class="mp-muted">Отзывов пока нет.</p>
        @else
            <div style="display:grid;gap:10px;">
                @foreach($reviews as $review)
                    <article style="border:1px solid #d7e7f8;border-radius:12px;padding:12px;">
                        <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;">
                            <strong>{{ $review->reviewer_name ?: 'Покупатель' }}</strong>
                            <span style="font-weight:800;color:#f39a00;">{{ str_repeat('★', (int) $review->rating) }}</span>
                        </div>
                        <div class="mp-muted" style="font-size:12px;margin-top:4px;">
                            {{ optional($review->created_at)->format('d.m.Y H:i') }}
                        </div>
                        <p style="margin:8px 0 0;line-height:1.55;">{{ $review->review_text }}</p>
                    </article>
                @endforeach
            </div>
        @endif
    </section>

    <style>
        @media (max-width: 980px) {
            form[action*="/review"] { grid-template-columns: 1fr !important; }
        }
    </style>
@endsection
