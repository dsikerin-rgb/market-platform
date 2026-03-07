@extends('marketplace.layout')

@section('title', 'Сообщения')

@section('content')
    <section class="mp-card">
        <div class="mp-page-head">
            <div>
                <h1 class="mp-page-title">Сообщения</h1>
                <p class="mp-page-sub">Все переписки на маркетплейсе в одном месте.</p>
            </div>
        </div>

        @if($chats->count() === 0)
            <p class="mp-muted" style="margin:0;">Пока нет активных переписок.</p>
        @else
            <div style="display:grid;gap:10px;">
                @foreach($chats as $chat)
                    <a href="{{ route('marketplace.buyer.chat.show', ['marketSlug' => $market->slug, 'chatId' => $chat->id]) }}"
                       style="display:flex;justify-content:space-between;align-items:center;gap:10px;border:1px solid #d7e7f8;border-radius:12px;padding:12px;background:#fff;">
                        <div>
                            <div style="font-weight:800;">{{ $chat->subject ?: 'Диалог' }}</div>
                            <div class="mp-muted" style="font-size:13px;">
                                {{ $chat->tenant?->short_name ?: ($chat->tenant?->name ?: 'Магазин') }}
                                @if($chat->product)
                                    · {{ $chat->product->title }}
                                @endif
                            </div>
                        </div>
                        <div style="text-align:right;">
                            <div class="mp-muted" style="font-size:12px;">{{ optional($chat->last_message_at)->format('d.m H:i') }}</div>
                            @if((int) $chat->buyer_unread_count > 0)
                                <div style="margin-top:4px;color:#0a84d6;font-weight:800;">{{ (int) $chat->buyer_unread_count }} новых</div>
                            @endif
                        </div>
                    </a>
                @endforeach
            </div>
            <div style="margin-top:14px;">{{ $chats->links() }}</div>
        @endif
    </section>
@endsection
