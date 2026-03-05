<x-cabinet-layout :tenant="$tenant" title="Моя витрина">
    @php
        $tenantName = data_get($tenant, 'display_name') ?: data_get($tenant, 'name') ?: 'Арендатор';
        $tenantSlug = data_get($tenant, 'slug');
        $publicUrl = $tenantSlug ? route('cabinet.showcase.public', $tenantSlug) : null;

        $photos = collect($showcase?->photos ?? [])
            ->filter(fn ($path) => is_string($path) && $path !== '')
            ->values();
    @endphp

    <form method="POST" action="{{ route('cabinet.showcase.update') }}" enctype="multipart/form-data" class="space-y-3">
        @csrf

        <section class="rounded-3xl bg-white border border-slate-200 p-4 shadow-sm space-y-3">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h2 class="text-base font-semibold text-slate-900">Профиль витрины</h2>
                    <p class="mt-1 text-xs text-slate-500">Публичная карточка арендатора для покупателей</p>
                </div>
                @if ($publicUrl)
                    <a
                        href="{{ $publicUrl }}"
                        target="_blank"
                        rel="noreferrer"
                        class="shrink-0 rounded-2xl border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700"
                    >
                        Открыть
                    </a>
                @endif
            </div>

            <label class="block">
                <span class="text-sm text-slate-600">Название витрины</span>
                <input
                    class="mt-1.5 w-full rounded-2xl border-slate-200 px-4 py-3 text-sm"
                    type="text"
                    name="title"
                    value="{{ old('title', $showcase->title ?? $tenantName) }}"
                    placeholder="Например: Фермерская лавка"
                    autocomplete="organization"
                >
            </label>

            <label class="block">
                <span class="text-sm text-slate-600">Описание</span>
                <textarea
                    class="mt-1.5 w-full rounded-2xl border-slate-200 px-4 py-3 text-sm"
                    name="description"
                    rows="5"
                    placeholder="Что продаете, условия заказа, график работы"
                >{{ old('description', $showcase->description ?? '') }}</textarea>
            </label>
        </section>

        <section class="rounded-3xl bg-white border border-slate-200 p-4 shadow-sm space-y-3">
            <h3 class="text-sm font-semibold text-slate-900">Контакты</h3>
            <label class="block">
                <span class="text-sm text-slate-600">Телефон</span>
                <input
                    class="mt-1.5 w-full rounded-2xl border-slate-200 px-4 py-3 text-sm"
                    type="tel"
                    name="phone"
                    value="{{ old('phone', $showcase->phone ?? '') }}"
                    placeholder="+7 900 000-00-00"
                    inputmode="tel"
                >
            </label>

            <label class="block">
                <span class="text-sm text-slate-600">Telegram</span>
                <input
                    class="mt-1.5 w-full rounded-2xl border-slate-200 px-4 py-3 text-sm"
                    type="text"
                    name="telegram"
                    value="{{ old('telegram', $showcase->telegram ?? '') }}"
                    placeholder="@username или https://t.me/username"
                >
            </label>

            <label class="block">
                <span class="text-sm text-slate-600">Сайт</span>
                <input
                    class="mt-1.5 w-full rounded-2xl border-slate-200 px-4 py-3 text-sm"
                    type="url"
                    name="website"
                    value="{{ old('website', $showcase->website ?? '') }}"
                    placeholder="https://example.com"
                    inputmode="url"
                >
            </label>

            @if ($publicUrl)
                <div class="rounded-2xl bg-slate-50 border border-slate-200 px-3 py-2 text-xs text-slate-600">
                    Публичная ссылка: <a href="{{ $publicUrl }}" class="font-semibold underline" target="_blank" rel="noreferrer">{{ $publicUrl }}</a>
                </div>
            @endif
        </section>

        <section class="rounded-3xl bg-white border border-slate-200 p-4 shadow-sm space-y-3">
            <div class="flex items-center justify-between gap-2">
                <h3 class="text-sm font-semibold text-slate-900">Фото</h3>
                <span class="text-xs text-slate-500">До 5 шт.</span>
            </div>

            <label class="block">
                <span class="text-sm text-slate-600">Загрузить фото</span>
                <input class="mt-1.5 w-full text-sm" type="file" name="photos[]" multiple accept="image/*">
            </label>

            @if($photos->isNotEmpty())
                <div class="grid grid-cols-2 gap-2">
                    @foreach($photos as $photo)
                        @php($url = \Illuminate\Support\Facades\Storage::url($photo))
                        <div class="rounded-2xl border border-slate-200 bg-slate-50 overflow-hidden">
                            <img class="w-full h-28 object-cover" src="{{ $url }}" alt="Фото витрины" loading="lazy">
                        </div>
                    @endforeach
                </div>
            @else
                <div class="rounded-2xl border border-dashed border-slate-300 px-3 py-4 text-xs text-slate-500">
                    Фото пока не добавлены.
                </div>
            @endif
        </section>

        <button class="w-full rounded-2xl bg-slate-900 text-white py-3 text-sm font-semibold" type="submit">
            Сохранить витрину
        </button>
    </form>
</x-cabinet-layout>
