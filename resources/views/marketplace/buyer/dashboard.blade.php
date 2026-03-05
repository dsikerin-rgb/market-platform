@extends('marketplace.layout')

@section('title', 'Кабинет покупателя')

@section('content')
    <section class="mp-card">
        <div class="mp-page-head">
            <div>
                <h1 class="mp-page-title">Кабинет покупателя</h1>
                <p class="mp-page-sub">Ваши избранные товары и последние диалоги.</p>
            </div>
            <a class="mp-btn" href="{{ route('marketplace.catalog', ['marketSlug' => $market->slug]) }}">Найти товары</a>
        </div>
        <div class="mp-grid">
            <div style="background:#f4fbff;border:1px solid #d5e9fb;border-radius:14px;padding:14px;">
                <div class="mp-muted">Избранное</div>
                <div style="font-size:32px;font-weight:900;">{{ $favoritesCount }}</div>
            </div>
            <div style="background:#f4fbff;border:1px solid #d5e9fb;border-radius:14px;padding:14px;">
                <div class="mp-muted">Открытые чаты</div>
                <div style="font-size:32px;font-weight:900;">{{ $openChatsCount }}</div>
            </div>
            <div style="background:#f4fbff;border:1px solid #d5e9fb;border-radius:14px;padding:14px;">
                <div class="mp-muted">Непрочитанные</div>
                <div style="font-size:32px;font-weight:900;">{{ $marketplaceChatUnreadCount }}</div>
            </div>
            <div style="background:#f4fbff;border:1px solid #d5e9fb;border-radius:14px;padding:14px;">
                <div class="mp-muted">Ярмарка</div>
                <div style="font-size:26px;font-weight:900;line-height:1.15;">{{ $market->name }}</div>
            </div>
        </div>
    </section>

    <section class="mp-card">
        <div class="mp-page-head">
            <div>
                <h2 class="mp-page-title" style="font-size:24px;">Последние чаты</h2>
            </div>
            <a class="mp-btn" href="{{ route('marketplace.buyer.chats', ['marketSlug' => $market->slug]) }}">Все чаты</a>
        </div>

        @if($latestChats->count() === 0)
            <p class="mp-muted">Пока нет диалогов с продавцами.</p>
        @else
            <div style="display:grid;gap:10px;">
                @foreach($latestChats as $chat)
                    <a href="{{ route('marketplace.buyer.chat.show', ['marketSlug' => $market->slug, 'chatId' => $chat->id]) }}"
                       style="display:flex;justify-content:space-between;gap:10px;align-items:center;border:1px solid #d7e7f8;border-radius:12px;padding:12px;background:#fff;">
                        <div>
                            <div style="font-weight:700;">{{ $chat->subject ?: 'Диалог с продавцом' }}</div>
                            <div class="mp-muted" style="font-size:13px;">
                                {{ $chat->tenant?->short_name ?: ($chat->tenant?->name ?: 'Магазин') }}
                                @if($chat->product)
                                    · {{ $chat->product->title }}
                                @endif
                            </div>
                        </div>
                        <div class="mp-muted" style="font-size:12px;">
                            {{ optional($chat->last_message_at)->format('d.m H:i') }}
                            @if((int) $chat->buyer_unread_count > 0)
                                <div style="margin-top:4px;color:#0a84d6;font-weight:700;">{{ (int) $chat->buyer_unread_count }} новых</div>
                            @endif
                        </div>
                    </a>
                @endforeach
            </div>
        @endif
    </section>

    <section class="mp-card">
        <div class="mp-page-head">
            <div>
                <h2 class="mp-page-title" style="font-size:24px;">Избранные товары</h2>
            </div>
            <a class="mp-btn" href="{{ route('marketplace.buyer.favorites', ['marketSlug' => $market->slug]) }}">Смотреть все</a>
        </div>
        @if($latestFavorites->count() === 0)
            <p class="mp-muted">Добавьте товары в избранное, чтобы быстро к ним возвращаться.</p>
        @else
            <div class="mp-grid">
                @foreach($latestFavorites as $product)
                    @include('marketplace.partials.product-card', ['product' => $product])
                @endforeach
            </div>
        @endif
    </section>
@endsection
