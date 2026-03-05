@extends('marketplace.layout')

@section('title', 'Чат')

@section('content')
    <section class="mp-card">
        <div class="mp-page-head">
            <div>
                <h1 class="mp-page-title" style="font-size:28px;">{{ $chat->subject ?: 'Диалог с продавцом' }}</h1>
                <p class="mp-page-sub">
                    {{ $chat->tenant?->short_name ?: ($chat->tenant?->name ?: 'Магазин') }}
                    @if($chat->product)
                        · {{ $chat->product->title }}
                    @endif
                </p>
            </div>
            <a class="mp-btn" href="{{ route('marketplace.buyer.chats', ['marketSlug' => $market->slug]) }}">Назад к чатам</a>
        </div>

        <div style="border:1px solid #d7e7f8;border-radius:14px;padding:12px;max-height:560px;overflow:auto;background:#f8fbff;display:grid;gap:10px;">
            @forelse($messages as $message)
                @php
                    $isBuyer = $message->sender_type === 'buyer';
                @endphp
                <div style="display:flex;{{ $isBuyer ? 'justify-content:flex-end;' : 'justify-content:flex-start;' }}">
                    <article style="max-width:75%;border-radius:14px;padding:10px 12px;{{ $isBuyer ? 'background:linear-gradient(145deg,#0a84d6,#10b2d8);color:#fff;' : 'background:#fff;border:1px solid #d7e7f8;' }}">
                        <div style="font-size:12px;{{ $isBuyer ? 'opacity:.92;' : 'color:#5b7090;' }}">
                            {{ $isBuyer ? 'Вы' : 'Продавец' }} · {{ optional($message->created_at)->format('d.m.Y H:i') }}
                        </div>
                        <div style="margin-top:4px;white-space:pre-wrap;line-height:1.45;">{{ $message->body }}</div>
                    </article>
                </div>
            @empty
                <p class="mp-muted" style="margin:0;">Сообщений пока нет.</p>
            @endforelse
        </div>

        <form method="post" action="{{ route('marketplace.buyer.chat.send', ['marketSlug' => $market->slug, 'chatId' => $chat->id]) }}"
              style="margin-top:12px;display:grid;grid-template-columns:1fr auto;gap:10px;align-items:end;">
            @csrf
            <label style="display:grid;gap:6px;">
                <span class="mp-muted">Сообщение</span>
                <textarea name="message" rows="3" required placeholder="Введите сообщение..."
                          style="border:1px solid #cbdcf3;border-radius:12px;padding:10px 12px;">{{ old('message') }}</textarea>
            </label>
            <button class="mp-btn mp-btn-brand" type="submit">Отправить</button>
        </form>
    </section>

    <style>
        @media (max-width: 760px) {
            form[action*="/send"] { grid-template-columns: 1fr !important; }
        }
    </style>
@endsection

