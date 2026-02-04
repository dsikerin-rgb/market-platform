{{-- resources/views/cabinet/showcase/edit.blade.php --}}

<x-cabinet-layout :tenant="$tenant" title="Моя витрина">
    @php
        $tenantName = data_get($tenant, 'display_name') ?: 'Арендатор';
        $tenantSlug = data_get($tenant, 'slug');

        $defaultTitle = $tenantName;

        $photos = collect($showcase?->photos ?? [])
            ->filter(fn ($p) => is_string($p) && $p !== '')
            ->values();

        $publicUrl = $tenantSlug ? route('cabinet.showcase.public', $tenantSlug) : null;

        $input = 'mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm
                  focus:border-slate-400 focus:ring-slate-300';
        $block = 'rounded-2xl bg-slate-50 border border-slate-200 p-4';
        $label = 'text-sm font-medium text-slate-700';
        $help  = 'mt-1 text-xs text-slate-500';
    @endphp

    <form method="POST" action="{{ route('cabinet.showcase.update') }}" enctype="multipart/form-data" class="space-y-4">
        @csrf

        {{-- Профиль / данные --}}
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
            <div class="px-4 py-3 border-b border-slate-100 flex items-start justify-between gap-3">
                <div>
                    <div class="text-xs font-semibold tracking-wide text-slate-400 uppercase">Профиль витрины</div>
                    <div class="mt-1 text-sm text-slate-600">
                        Арендатор: <span class="font-semibold text-slate-900">{{ $tenantName }}</span>
                    </div>
                </div>

                @if($publicUrl)
                    <a
                        class="shrink-0 inline-flex items-center justify-center rounded-xl px-3 py-2 text-sm font-medium
                               bg-slate-100 text-slate-800 hover:bg-slate-200 active:scale-[0.99] transition"
                        href="{{ $publicUrl }}"
                        target="_blank"
                        rel="noreferrer"
                    >
                        Открыть
                    </a>
                @endif
            </div>

            <div class="p-4 space-y-3">
                {{-- Блок 1 --}}
                <div class="{{ $block }}">
                    <div class="text-xs font-semibold tracking-wide text-slate-400 uppercase">Описание</div>

                    <label class="block mt-3">
                        <span class="{{ $label }}">Название витрины</span>
                        <input
                            class="{{ $input }}"
                            type="text"
                            name="title"
                            value="{{ old('title', $showcase->title ?? $defaultTitle) }}"
                            placeholder="Например: Лавка «Свой урожай»"
                            autocomplete="organization"
                        >
                        <p class="{{ $help }}">По умолчанию — название арендатора.</p>
                    </label>

                    <label class="block mt-3">
                        <span class="{{ $label }}">Описание</span>
                        <textarea
                            class="{{ $input }}"
                            name="description"
                            rows="5"
                            placeholder="Коротко: что продаёте, условия доставки, график…"
                        >{{ old('description', $showcase->description ?? '') }}</textarea>
                    </label>
                </div>

                {{-- Блок 2 --}}
                <div class="{{ $block }}">
                    <div class="text-xs font-semibold tracking-wide text-slate-400 uppercase">Контакты</div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-3">
                        <label class="block">
                            <span class="{{ $label }}">Телефон</span>
                            <input
                                class="{{ $input }}"
                                type="tel"
                                name="phone"
                                value="{{ old('phone', $showcase->phone ?? '') }}"
                                placeholder="+7 900 000-00-00"
                                autocomplete="tel"
                                inputmode="tel"
                            >
                        </label>

                        <label class="block">
                            <span class="{{ $label }}">Telegram</span>
                            <input
                                class="{{ $input }}"
                                type="text"
                                name="telegram"
                                value="{{ old('telegram', $showcase->telegram ?? '') }}"
                                placeholder="@username или https://t.me/username"
                                autocomplete="off"
                            >
                        </label>
                    </div>

                    <label class="block mt-3">
                        <span class="{{ $label }}">Сайт</span>
                        <input
                            class="{{ $input }}"
                            type="url"
                            name="website"
                            value="{{ old('website', $showcase->website ?? '') }}"
                            placeholder="https://example.com"
                            autocomplete="url"
                            inputmode="url"
                        >
                    </label>
                </div>

                {{-- Публичная ссылка --}}
                <div class="rounded-2xl bg-white border border-slate-200 px-4 py-3">
                    <div class="text-xs font-semibold tracking-wide text-slate-400 uppercase">Публичная ссылка</div>
                    <div class="mt-1 text-sm text-slate-700">
                        @if($publicUrl)
                            <a class="underline hover:text-slate-900" href="{{ $publicUrl }}" target="_blank" rel="noreferrer">
                                /v/{{ $tenantSlug }}
                            </a>
                        @else
                            <span class="text-slate-400">не задан slug арендатора</span>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        {{-- Фото --}}
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
            <div class="px-4 py-3 border-b border-slate-100 flex items-start justify-between gap-3">
                <div>
                    <div class="text-xs font-semibold tracking-wide text-slate-400 uppercase">Фото витрины</div>
                    <div class="mt-1 text-sm text-slate-600">До 5 фото, лучше квадратные или 4:3</div>
                </div>

                <div class="text-xs text-slate-500">
                    Загружено: <span class="font-semibold text-slate-900">{{ $photos->count() }}</span>
                </div>
            </div>

            <div class="p-4 space-y-3">
                <div class="{{ $block }}">
                    <label class="block">
                        <span class="{{ $label }}">Добавить фото</span>
                        <input class="mt-2 w-full text-sm" type="file" name="photos[]" multiple accept="image/*">
                        <p class="{{ $help }}">Можно выбрать несколько файлов за раз.</p>
                    </label>
                </div>

                @if($photos->isNotEmpty())
                    <div class="grid grid-cols-2 gap-3">
                        @foreach($photos as $photo)
                            @php $url = \Illuminate\Support\Facades\Storage::url($photo); @endphp

                            <div class="rounded-2xl border border-slate-200 overflow-hidden bg-slate-50">
                                <div class="relative">
                                    <img
                                        class="w-full h-32 object-cover"
                                        src="{{ $url }}"
                                        alt="Фото витрины"
                                        loading="lazy"
                                        onerror="this.classList.add('hidden'); this.nextElementSibling.classList.remove('hidden');"
                                    >
                                    <div class="hidden w-full h-32 flex items-center justify-center text-xs text-slate-500">
                                        Файл не найден
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="rounded-2xl border border-dashed border-slate-200 bg-slate-50 p-4 text-sm text-slate-500">
                        Пока нет фото. Добавь 1–2 изображения — витрина будет выглядеть живее.
                    </div>
                @endif
            </div>
        </div>

        {{-- Кнопка сохранения (чуть “приклеена”, чтобы всегда была под рукой) --}}
        <div class="sticky bottom-24 z-20">
            <button
                class="w-full rounded-xl bg-slate-900 text-white py-2.5 text-sm font-semibold
                       hover:bg-slate-800 active:scale-[0.99] transition shadow-sm"
                type="submit"
            >
                Сохранить витрину
            </button>
        </div>
    </form>
</x-cabinet-layout>
