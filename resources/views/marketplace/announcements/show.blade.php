@extends('marketplace.layout')

@section('title', $announcement->title)

@section('content')
    @php($publicPayload = $announcement->publicCardPayload())
    @php($summary = trim((string) ($publicPayload['summary'] ?? '')))
    @php($summary = $summary !== '' ? $summary : trim((string) ($announcement->excerpt ?? '')))
    @php($summary = $summary !== '' ? $summary : trim((string) ($announcement->content ?? '')))
    @php($details = trim((string) ($publicPayload['details'] ?? '')))
    @php($details = $details !== '' ? $details : trim((string) ($announcement->content ?? '')))
    @php($details = $details !== '' ? $details : trim((string) ($announcement->excerpt ?? '')))
    @php($timeNote = trim((string) ($publicPayload['time_note'] ?? '')))
    @php($locationTitle = trim((string) ($publicPayload['location_title'] ?? '')))
    @php($locationNote = trim((string) ($publicPayload['location_note'] ?? '')))
    @php($specialHours = trim((string) ($publicPayload['special_hours'] ?? '')))
    @php($primaryCtaLabel = trim((string) ($publicPayload['primary_cta_label'] ?? '')))
    @php($primaryCtaUrl = trim((string) ($publicPayload['primary_cta_url'] ?? '')))
    @php($scheduleItems = collect($publicPayload['schedule_items'] ?? [])->filter(fn ($item) => is_array($item))->values())
    @php($promoItems = collect($publicPayload['promo_items'] ?? [])->filter(fn ($item) => is_array($item))->values())
    @php($coverImageUrl = $announcement->cover_image_preview_url ?? $announcement->cover_image_url)
    @php($kindLabel = match ((string) $announcement->kind) {
        'holiday' => 'Праздник',
        'promo' => 'Акция',
        'sanitary_day' => 'Санитарный день',
        default => 'Событие',
    })
    @php($dateLabel = $announcement->starts_at ? optional($announcement->starts_at)->format('d.m.Y') : '')
    @php($endDateLabel = $announcement->ends_at ? optional($announcement->ends_at)->format('d.m.Y') : '')
    @php($showDateRange = $dateLabel !== '' && $endDateLabel !== '' && $dateLabel !== $endDateLabel)
    @php($hasSummary = $summary !== '')
    @php($hasDetails = $details !== '')
    @php($hasSchedule = $scheduleItems->isNotEmpty())
    @php($hasPromo = $promoItems->isNotEmpty())
    @php($hasSecondaryContent = $hasSchedule || $hasPromo)
    @php($hasLocationInfo = $locationTitle !== '' || $locationNote !== '')
    @php($hasPracticalInfo = $dateLabel !== '' || $timeNote !== '' || $hasLocationInfo || $specialHours !== '')
    @php($introTitle = ($hasSummary || $hasDetails) ? 'Что будет на событии' : 'О событии')
    @php($introBody = $hasDetails ? $details : ($hasSummary ? $summary : 'Подробности события уточняются. Следите за обновлениями на странице ярмарки.'))

    <style>
        .mp-announcement-hero {
            display: grid;
            grid-template-columns: minmax(0, 1.35fr) minmax(280px, 0.9fr);
            gap: 18px;
            align-items: start;
            margin-bottom: 18px;
        }

        .mp-announcement-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin: 0 0 14px;
        }

        .mp-announcement-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 12px;
            border-radius: 999px;
            border: 1px solid #cfe0f5;
            background: #f8fbff;
            color: #17375f;
            font-size: 13px;
            font-weight: 700;
        }

        .mp-announcement-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 18px;
        }

        .mp-announcement-cover {
            min-height: 0;
            aspect-ratio: 16 / 10;
            border-radius: 18px;
            overflow: hidden;
            border: 1px solid #d5e5f8;
            background: linear-gradient(135deg, #f4f9ff 0%, #edf7ff 100%);
        }

        .mp-announcement-cover img {
            display: block;
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .mp-announcement-layout {
            display: grid;
            grid-template-columns: minmax(0, 1.35fr) minmax(280px, 0.8fr);
            gap: 18px;
        }

        .mp-announcement-stack {
            display: grid;
            gap: 14px;
        }

        .mp-announcement-section {
            border: 1px solid #d9e6f7;
            border-radius: 18px;
            background: #fff;
            padding: 18px;
        }

        .mp-announcement-section h2 {
            margin: 0 0 12px;
            font-size: 22px;
            line-height: 1.2;
        }

        .mp-announcement-section p:last-child {
            margin-bottom: 0;
        }

        .mp-announcement-program {
            display: grid;
            gap: 12px;
        }

        .mp-announcement-program-item {
            display: grid;
            grid-template-columns: 92px minmax(0, 1fr);
            gap: 12px;
            align-items: start;
            padding-top: 12px;
            border-top: 1px solid #ebf2fb;
        }

        .mp-announcement-program-item:first-child {
            padding-top: 0;
            border-top: 0;
        }

        .mp-announcement-program-time {
            font-size: 13px;
            font-weight: 800;
            color: #0f6fbd;
        }

        .mp-announcement-promo-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }

        .mp-announcement-promo-card {
            border: 1px solid #dbe8f7;
            border-radius: 16px;
            padding: 16px;
            background: linear-gradient(180deg, #ffffff 0%, #f9fbff 100%);
        }

        .mp-announcement-promo-badge {
            display: inline-block;
            margin-bottom: 10px;
            padding: 6px 10px;
            border-radius: 999px;
            background: #e9f6ff;
            color: #0f6fbd;
            font-size: 12px;
            font-weight: 800;
        }

        .mp-announcement-facts {
            display: grid;
            gap: 12px;
        }

        .mp-announcement-fact {
            border: 1px solid #e6eef8;
            border-radius: 14px;
            padding: 14px;
            background: #fbfdff;
        }

        .mp-announcement-fact-label {
            margin: 0 0 6px;
            color: #6280a9;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: .06em;
        }

        .mp-announcement-inline-facts {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
            margin-top: 16px;
        }

        .mp-announcement-inline-facts .mp-announcement-fact {
            padding: 12px 14px;
        }

        @media (max-width: 980px) {
            .mp-announcement-hero,
            .mp-announcement-layout {
                grid-template-columns: 1fr;
            }

            .mp-announcement-promo-grid,
            .mp-announcement-inline-facts {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 640px) {
            .mp-announcement-program-item {
                grid-template-columns: 1fr;
            }
        }
    </style>

    <article class="mp-card">
        <div class="mp-page-head" style="margin-bottom:16px;">
            <div>
                <div class="mp-announcement-meta">
                    <span class="mp-announcement-chip">{{ $kindLabel }}</span>
                    @if($dateLabel !== '')
                        <span class="mp-announcement-chip">
                            {{ $showDateRange ? ($dateLabel . ' - ' . $endDateLabel) : $dateLabel }}
                        </span>
                    @endif
                    @if($timeNote !== '')
                        <span class="mp-announcement-chip">{{ $timeNote }}</span>
                    @endif
                    @if($locationTitle !== '')
                        <span class="mp-announcement-chip">{{ $locationTitle }}</span>
                    @endif
                </div>
                <h1 class="mp-page-title">{{ $announcement->title }}</h1>
                @if($summary !== '')
                    <p class="mp-page-sub" style="max-width:920px;font-size:18px;line-height:1.6;margin-top:10px;">{{ $summary }}</p>
                @endif
            </div>
            <a class="mp-btn" href="{{ route('marketplace.announcements', ['marketSlug' => $market->slug]) }}">Назад к анонсам</a>
        </div>

        <section class="mp-announcement-hero">
            <div class="mp-announcement-section" style="background:linear-gradient(180deg,#ffffff 0%,#f8fbff 100%);">
                <h2>{{ $introTitle }}</h2>
                <div style="line-height:1.75;font-size:16px;color:#29476e;">
                    {!! nl2br(e($introBody)) !!}
                </div>

                <div class="mp-announcement-actions">
                    <a class="mp-btn mp-btn-brand" href="{{ route('marketplace.catalog', ['marketSlug' => $market->slug]) }}">Смотреть товары</a>
                    <a class="mp-btn" href="{{ route('marketplace.map', ['marketSlug' => $market->slug]) }}">Открыть карту</a>
                    @if($primaryCtaLabel !== '' && $primaryCtaUrl !== '')
                        <a class="mp-btn" href="{{ $primaryCtaUrl }}">{{ $primaryCtaLabel }}</a>
                    @endif
                </div>

                @if(! $hasSecondaryContent && $hasPracticalInfo)
                    <div class="mp-announcement-inline-facts">
                        <div class="mp-announcement-fact">
                            <p class="mp-announcement-fact-label">Дата</p>
                            <div>{{ $showDateRange ? ($dateLabel . ' - ' . $endDateLabel) : ($dateLabel !== '' ? $dateLabel : 'Будет объявлена позже') }}</div>
                        </div>

                        @if($timeNote !== '')
                            <div class="mp-announcement-fact">
                                <p class="mp-announcement-fact-label">Время</p>
                                <div>{{ $timeNote }}</div>
                            </div>
                        @endif

                        @if($hasLocationInfo)
                            <div class="mp-announcement-fact">
                                <p class="mp-announcement-fact-label">Место</p>
                                @if($locationTitle !== '')
                                    <div style="font-weight:700;margin-bottom:6px;">{{ $locationTitle }}</div>
                                @endif
                                @if($locationNote !== '')
                                    <div class="mp-muted" style="line-height:1.6;">{{ $locationNote }}</div>
                                @endif
                            </div>
                        @endif

                        @if($specialHours !== '')
                            <div class="mp-announcement-fact">
                                <p class="mp-announcement-fact-label">Важно знать</p>
                                <div class="mp-muted" style="line-height:1.6;">{{ $specialHours }}</div>
                            </div>
                        @endif
                    </div>
                @endif
            </div>

            @if($coverImageUrl)
                <div class="mp-announcement-cover">
                    <img src="{{ $coverImageUrl }}" alt="{{ $announcement->title }}" loading="lazy" decoding="async">
                </div>
            @endif
        </section>

        @if($hasSecondaryContent)
            <section class="mp-announcement-layout">
                <div class="mp-announcement-stack">
                    @if($hasSchedule)
                        <div class="mp-announcement-section">
                            <h2>Программа</h2>
                            <div class="mp-announcement-program">
                                @foreach($scheduleItems as $item)
                                    <div class="mp-announcement-program-item">
                                        <div class="mp-announcement-program-time">{{ trim((string) ($item['time'] ?? '')) ?: 'В течение дня' }}</div>
                                        <div>
                                            @if(trim((string) ($item['title'] ?? '')) !== '')
                                                <div style="font-weight:800;font-size:17px;margin-bottom:6px;">{{ $item['title'] }}</div>
                                            @endif
                                            @if(trim((string) ($item['description'] ?? '')) !== '')
                                                <div class="mp-muted" style="line-height:1.65;">{{ $item['description'] }}</div>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    @if($hasPromo)
                        <div class="mp-announcement-section">
                            <h2>Акции и активности</h2>
                            <div class="mp-announcement-promo-grid">
                                @foreach($promoItems as $item)
                                    <div class="mp-announcement-promo-card">
                                        @if(trim((string) ($item['badge'] ?? '')) !== '')
                                            <span class="mp-announcement-promo-badge">{{ $item['badge'] }}</span>
                                        @endif
                                        @if(trim((string) ($item['title'] ?? '')) !== '')
                                            <div style="font-size:18px;font-weight:800;margin-bottom:8px;">{{ $item['title'] }}</div>
                                        @endif
                                        @if(trim((string) ($item['description'] ?? '')) !== '')
                                            <div class="mp-muted" style="line-height:1.65;margin-bottom:10px;">{{ $item['description'] }}</div>
                                        @endif
                                        @if(trim((string) ($item['link_label'] ?? '')) !== '' && trim((string) ($item['link_url'] ?? '')) !== '')
                                            <a class="mp-btn" href="{{ $item['link_url'] }}">{{ $item['link_label'] }}</a>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>

                <aside class="mp-announcement-stack">
                    <div class="mp-announcement-section">
                        <h2>Практическая информация</h2>
                        <div class="mp-announcement-facts">
                            <div class="mp-announcement-fact">
                                <p class="mp-announcement-fact-label">Дата</p>
                                <div>{{ $showDateRange ? ($dateLabel . ' - ' . $endDateLabel) : ($dateLabel !== '' ? $dateLabel : 'Будет объявлена позже') }}</div>
                            </div>

                            @if($timeNote !== '')
                                <div class="mp-announcement-fact">
                                    <p class="mp-announcement-fact-label">Время</p>
                                    <div>{{ $timeNote }}</div>
                                </div>
                            @endif

                            @if($hasLocationInfo)
                                <div class="mp-announcement-fact">
                                    <p class="mp-announcement-fact-label">Место</p>
                                    @if($locationTitle !== '')
                                        <div style="font-weight:700;margin-bottom:6px;">{{ $locationTitle }}</div>
                                    @endif
                                    @if($locationNote !== '')
                                        <div class="mp-muted" style="line-height:1.6;">{{ $locationNote }}</div>
                                    @endif
                                </div>
                            @endif

                            @if($specialHours !== '')
                                <div class="mp-announcement-fact">
                                    <p class="mp-announcement-fact-label">Важно знать</p>
                                    <div class="mp-muted" style="line-height:1.6;">{{ $specialHours }}</div>
                                </div>
                            @endif
                        </div>
                    </div>
                </aside>
            </section>
        @endif
    </article>
@endsection
