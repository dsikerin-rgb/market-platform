@extends('marketplace.layout')

@section('title', $announcement->title)

@section('content')
    <article class="mp-card">
        <div class="mp-page-head">
            <div>
                <h1 class="mp-page-title">{{ $announcement->title }}</h1>
                <p class="mp-page-sub">
                    @if($announcement->starts_at)
                        С {{ optional($announcement->starts_at)->format('d.m.Y H:i') }}
                    @endif
                    @if($announcement->ends_at)
                        по {{ optional($announcement->ends_at)->format('d.m.Y H:i') }}
                    @endif
                </p>
            </div>
            <a class="mp-btn" href="{{ route('marketplace.announcements', ['marketSlug' => $market->slug]) }}">Назад к списку</a>
        </div>

        @if($announcement->cover_image_url)
            <div style="margin-bottom:14px;border-radius:14px;overflow:hidden;border:1px solid #d5e5f8;max-height:420px;">
                <img src="{{ $announcement->cover_image_url }}" alt="{{ $announcement->title }}" style="width:100%;height:100%;object-fit:cover;">
            </div>
        @endif

        @if($announcement->excerpt)
            <p style="font-size:18px;line-height:1.6;margin:0 0 12px;font-weight:600;">{{ $announcement->excerpt }}</p>
        @endif

        <div style="line-height:1.7;">
            {!! nl2br(e((string) $announcement->content)) !!}
        </div>
    </article>
@endsection
