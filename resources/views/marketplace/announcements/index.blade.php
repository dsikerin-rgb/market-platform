@extends('marketplace.layout')

@section('title', 'Анонсы')

@section('content')
    <section class="mp-card">
        <div class="mp-page-head">
            <div>
                <h1 class="mp-page-title">Анонсы ярмарки</h1>
                <p class="mp-page-sub">Праздники, санитарные дни, акции и специальные события.</p>
            </div>
            <form method="get" action="{{ route('marketplace.announcements', ['marketSlug' => $market->slug]) }}" style="display:flex;gap:8px;align-items:end;">
                <label style="display:flex;flex-direction:column;gap:6px;">
                    <span class="mp-muted">Тип</span>
                    <select name="kind" style="border:1px solid #cbdcf3;border-radius:12px;padding:10px 12px;">
                        <option value="">Все</option>
                        <option value="event" {{ $kind === 'event' ? 'selected' : '' }}>Мероприятие</option>
                        <option value="holiday" {{ $kind === 'holiday' ? 'selected' : '' }}>Праздник</option>
                        <option value="promo" {{ $kind === 'promo' ? 'selected' : '' }}>Акция</option>
                        <option value="sanitary_day" {{ $kind === 'sanitary_day' ? 'selected' : '' }}>Санитарный день</option>
                    </select>
                </label>
                <button class="mp-btn mp-btn-brand" type="submit">Фильтр</button>
            </form>
        </div>

        @if($announcements->count() === 0)
            <p class="mp-muted" style="margin:0;">Активных анонсов пока нет.</p>
        @else
            <div class="mp-grid">
                @foreach($announcements as $announcement)
                    <article style="background:#fff;border:1px solid #d9e6f7;border-radius:14px;padding:12px;display:flex;flex-direction:column;gap:10px;">
                        @if($announcement->cover_image_url)
                            <div style="height:170px;border-radius:10px;overflow:hidden;border:1px solid #d9e6f7;position:relative;">
                                <img src="{{ $announcement->cover_image_url }}" alt="{{ $announcement->title }}" style="width:100%;height:100%;object-fit:cover;">
                                <span style="position:absolute;right:10px;bottom:10px;color:#fff;font-weight:800;font-size:22px;line-height:1;padding:8px 11px;border-radius:8px;background:rgba(17,32,59,.35);backdrop-filter:blur(3px);">
                                    {{ optional($announcement->starts_at)->format('d.m') ?: (optional($announcement->published_at)->format('d.m') ?: optional($announcement->created_at)->format('d.m')) }}
                                </span>
                            </div>
                        @endif
                        <a href="{{ route('marketplace.announcement.show', ['marketSlug' => $market->slug, 'announcementSlug' => $announcement->slug]) }}"
                           style="font-size:20px;font-weight:800;line-height:1.25;">
                            {{ $announcement->title }}
                        </a>
                        @if(filled($announcement->excerpt))
                            <p class="mp-muted" style="margin:0;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;">
                                {{ $announcement->excerpt }}
                            </p>
                        @endif
                    </article>
                @endforeach
            </div>
            <div style="margin-top:14px;">
                {{ $announcements->links() }}
            </div>
        @endif
    </section>
@endsection
