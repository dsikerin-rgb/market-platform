<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#ffffff">

    @php
        $pageTitle = $showcase?->title ?: ($tenant->display_name ?: 'Витрина арендатора');

        $hasViteManifest = file_exists(public_path('build/manifest.json'));

        $photos = collect($showcase?->photos ?? [])
            ->filter(fn ($p) => is_string($p) && trim($p) !== '')
            ->values();

        $phone = $showcase?->phone ? trim((string) $showcase->phone) : null;
        $telegramRaw = $showcase?->telegram ? trim((string) $showcase->telegram) : null;
        $websiteRaw = $showcase?->website ? trim((string) $showcase->website) : null;

        // Telegram: принимаем @username, username, или ссылку
        $telegramLink = null;
        $telegramLabel = null;
        if ($telegramRaw) {
            $tg = ltrim($telegramRaw);
            if (str_starts_with($tg, '@')) {
                $u = ltrim($tg, '@');
                $telegramLink = 'https://t.me/' . $u;
                $telegramLabel = '@' . $u;
            } elseif (preg_match('~^(https?://)?(t\.me|telegram\.me)/~i', $tg)) {
                $telegramLink = preg_match('~^https?://~i', $tg) ? $tg : ('https://' . $tg);
                $telegramLabel = $telegramRaw;
            } else {
                // просто username
                $telegramLink = 'https://t.me/' . $tg;
                $telegramLabel = '@' . $tg;
            }
        }

        // Сайт: добавим https:// если не указано
        $websiteLink = null;
        if ($websiteRaw) {
            $websiteLink = preg_match('~^https?://~i', $websiteRaw) ? $websiteRaw : ('https://' . $websiteRaw);
        }
    @endphp

    <title>{{ $pageTitle }}</title>

    @if ($hasViteManifest)
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @else
        {{-- FALLBACK для staging/demo, когда нет public/build/manifest.json --}}
        <script src="https://cdn.tailwindcss.com"></script>
        <style>
            html, body { height: 100%; }
        </style>
    @endif
</head>

<body class="bg-slate-50 text-slate-900">
    <div class="max-w-3xl mx-auto px-4 py-6 space-y-6">
        <header class="bg-white rounded-2xl p-4 border shadow-sm space-y-2">
            <h1 class="text-2xl font-semibold leading-snug">{{ $pageTitle }}</h1>
            <p class="text-sm text-slate-500">
                {{ $showcase?->description ?: 'Описание витрины скоро появится.' }}
            </p>
        </header>

        @if($photos->isNotEmpty())
            <section aria-label="Фотографии витрины" class="grid grid-cols-2 gap-3">
                @foreach($photos as $photo)
                    <div class="rounded-2xl overflow-hidden border bg-white shadow-sm">
                        <img
                            class="w-full h-36 object-cover"
                            src="{{ \Illuminate\Support\Facades\Storage::url($photo) }}"
                            alt="Фото витрины"
                            loading="lazy"
                        >
                    </div>
                @endforeach
            </section>
        @else
            <div class="bg-white rounded-2xl p-4 border shadow-sm text-sm text-slate-500">
                Фото витрины будут добавлены позже.
            </div>
        @endif

        <section class="bg-white rounded-2xl p-4 border shadow-sm space-y-3 text-sm">
            <h2 class="text-base font-semibold">Контакты</h2>

            <div class="space-y-2">
                <div class="flex items-start justify-between gap-4">
                    <span class="text-slate-500">Телефон</span>
                    @if($phone)
                        <a class="font-medium hover:underline" href="tel:{{ preg_replace('/\s+/', '', $phone) }}">{{ $phone }}</a>
                    @else
                        <span class="text-slate-400">—</span>
                    @endif
                </div>

                <div class="flex items-start justify-between gap-4">
                    <span class="text-slate-500">Telegram</span>
                    @if($telegramLink)
                        <a class="font-medium hover:underline" href="{{ $telegramLink }}" target="_blank" rel="noreferrer noopener">
                            {{ $telegramLabel ?? $telegramRaw }}
                        </a>
                    @else
                        <span class="text-slate-400">—</span>
                    @endif
                </div>

                <div class="flex items-start justify-between gap-4">
                    <span class="text-slate-500">Сайт</span>
                    @if($websiteLink)
                        <a class="font-medium hover:underline break-all" href="{{ $websiteLink }}" target="_blank" rel="noreferrer noopener">
                            {{ $websiteRaw }}
                        </a>
                    @else
                        <span class="text-slate-400">—</span>
                    @endif
                </div>
            </div>
        </section>

        <div class="text-center">
            @if($telegramLink)
                <a
                    class="inline-flex items-center justify-center rounded-xl bg-slate-900 text-white px-4 py-2 text-sm font-medium"
                    href="{{ $telegramLink }}"
                    target="_blank"
                    rel="noreferrer noopener"
                >
                    Написать в Telegram
                </a>
            @else
                <div class="inline-flex items-center justify-center rounded-xl border px-4 py-2 text-sm text-slate-500 bg-white">
                    Связь появится позже
                </div>
            @endif
        </div>
    </div>
</body>
</html>
