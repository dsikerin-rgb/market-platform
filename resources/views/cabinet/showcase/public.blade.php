<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $showcase?->title ?? $tenant->display_name }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-slate-50 text-slate-900">
    <div class="max-w-3xl mx-auto px-4 py-6 space-y-6">
        <div class="bg-white rounded-2xl p-4 border shadow-sm space-y-2">
            <h1 class="text-2xl font-semibold">{{ $showcase?->title ?? $tenant->display_name }}</h1>
            <p class="text-sm text-slate-500">{{ $showcase?->description ?? 'Описание витрины скоро появится.' }}</p>
        </div>

        @if(! empty($showcase?->photos))
            <div class="grid grid-cols-2 gap-3">
                @foreach($showcase->photos as $photo)
                    <div class="rounded-2xl overflow-hidden border bg-white shadow-sm">
                        <img class="w-full h-36 object-cover" src="{{ \Illuminate\Support\Facades\Storage::url($photo) }}" alt="Фото витрины">
                    </div>
                @endforeach
            </div>
        @else
            <div class="bg-white rounded-2xl p-4 border shadow-sm text-sm text-slate-500">Фото витрины будут добавлены позже.</div>
        @endif

        <div class="bg-white rounded-2xl p-4 border shadow-sm space-y-2 text-sm">
            <h2 class="text-base font-semibold">Контакты</h2>
            <p>Телефон: {{ $showcase?->phone ?? '—' }}</p>
            <p>Telegram: {{ $showcase?->telegram ?? '—' }}</p>
            <p>Сайт: {{ $showcase?->website ?? '—' }}</p>
        </div>

        <div class="text-center">
            @if($showcase?->telegram)
                <a class="inline-flex items-center justify-center rounded-xl bg-slate-900 text-white px-4 py-2 text-sm" href="{{ $showcase->telegram }}" target="_blank" rel="noreferrer">Написать</a>
            @else
                <div class="inline-flex items-center justify-center rounded-xl border px-4 py-2 text-sm text-slate-500">Связь появится позже</div>
            @endif
        </div>
    </div>
</body>
</html>
