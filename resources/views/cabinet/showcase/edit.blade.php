<x-cabinet-layout :tenant="$tenant" title="Моя витрина">
    @php
        $tenantName = data_get($tenant, 'display_name') ?: data_get($tenant, 'name') ?: 'Арендатор';
        $tenantSlug = data_get($tenant, 'slug');
        $selectedSpaceId = isset($selectedSpaceId) ? (int) $selectedSpaceId : null;
        $activeShowcase = $spaceShowcase ?? $showcase ?? null;

        $publicUrl = null;
        if ($tenantSlug) {
            $publicUrl = route('cabinet.showcase.public', $tenantSlug)
                . ($selectedSpaceId ? ('?space_id=' . $selectedSpaceId) : '');
        }

        $photos = collect($activeShowcase?->photos ?? [])
            ->filter(fn ($path) => is_string($path) && $path !== '')
            ->values();

        $summaryClass = 'flex w-full items-center justify-between gap-3 cursor-pointer list-none';
        $sectionClass = 'rounded-3xl bg-white border border-slate-200 p-4 shadow-sm';
        $inputClass = 'mt-1.5 w-full rounded-2xl border-slate-200 px-4 py-3 text-sm';
        $contactInputClass = 'mt-1.5 w-full rounded-2xl border-2 border-sky-300 px-4 py-3 text-sm focus:border-sky-500 focus:ring-2 focus:ring-sky-100';
    @endphp

    @if(($spaces ?? collect())->isNotEmpty())
        <section class="{{ $sectionClass }} space-y-2">
            <h2 class="text-base font-semibold text-slate-900">Торговое место</h2>
            <form method="GET" action="{{ route('cabinet.showcase.edit') }}">
                <select
                    name="space_id"
                    class="w-full rounded-xl border-slate-200 px-3 py-2 text-sm"
                    onchange="this.form.submit()"
                >
                    <option value="">Все торговые места (общая витрина)</option>
                    @foreach($spaces as $space)
                        @php
                            $spaceLabel = trim((string) ($space->code ?: $space->number ?: $space->display_name ?: ('#' . $space->id)));
                            $spaceName = trim((string) ($space->display_name ?? ''));
                        @endphp
                        <option value="{{ $space->id }}" @selected((int) ($selectedSpaceId ?? 0) === (int) $space->id)>
                            {{ $spaceLabel }}{{ $spaceName !== '' ? ' · ' . $spaceName : '' }}
                        </option>
                    @endforeach
                </select>
            </form>

            <p class="text-xs text-slate-500">
                @if(!empty($selectedSpaceId))
                    Вы редактируете витрину выбранного торгового места.
                @else
                    Вы редактируете общую витрину арендатора. Для детализации выберите торговое место.
                @endif
            </p>
        </section>
    @endif

    @include('cabinet.partials.sales-nav')

    <form method="POST" action="{{ route('cabinet.showcase.update') }}" enctype="multipart/form-data" class="space-y-3">
        @csrf
        @if(!empty($selectedSpaceId))
            <input type="hidden" name="space_id" value="{{ (int) $selectedSpaceId }}">
        @endif

        <details class="{{ $sectionClass }}" open>
            <summary class="{{ $summaryClass }}">
                <div>
                    <h2 class="text-base font-semibold text-slate-900">Профиль витрины</h2>
                    <p class="mt-1 text-xs text-slate-500">Публичная карточка арендатора для покупателей</p>
                </div>
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-slate-400 transition details-arrow" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                </svg>
            </summary>

            <div class="mt-3 space-y-3">
                @if ($publicUrl)
                    <a
                        href="{{ $publicUrl }}"
                        target="_blank"
                        rel="noreferrer"
                        class="inline-flex rounded-2xl border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700"
                    >
                        Открыть публичную ссылку
                    </a>
                @endif

                <label class="block">
                    <span class="text-sm text-slate-600">Название витрины</span>
                    <input
                        class="{{ $inputClass }}"
                        type="text"
                        name="title"
                        value="{{ old('title', $activeShowcase->title ?? $showcase->title ?? $tenantName) }}"
                        placeholder="Например: Фермерская лавка"
                        autocomplete="organization"
                    >
                </label>

                <label class="block">
                    <span class="text-sm text-slate-600">Описание</span>
                    <textarea
                        class="mt-1.5 w-full rounded-2xl border-2 border-sky-300 px-4 py-3 text-sm focus:border-sky-500 focus:ring-2 focus:ring-sky-100"
                        name="description"
                        rows="5"
                        placeholder="Что продаете, условия заказа, график работы"
                    >{{ old('description', $activeShowcase->description ?? $showcase->description ?? '') }}</textarea>
                </label>

                @if(!empty($selectedSpaceId))
                    <label class="block">
                        <span class="text-sm text-slate-600">Ассортимент для этого места</span>
                        <textarea
                            class="mt-1.5 w-full rounded-2xl border-2 border-sky-300 px-4 py-3 text-sm focus:border-sky-500 focus:ring-2 focus:ring-sky-100"
                            name="assortment"
                            rows="4"
                            placeholder="Кратко укажите ассортимент и особенности именно этого места"
                        >{{ old('assortment', $spaceShowcase->assortment ?? '') }}</textarea>
                    </label>
                @endif
            </div>
        </details>

        <details class="{{ $sectionClass }}">
            <summary class="{{ $summaryClass }}">
                <div>
                    <h3 class="text-sm font-semibold text-slate-900">Контакты</h3>
                    <p class="mt-1 text-xs text-slate-500">Телефон, Telegram и сайт</p>
                </div>
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-slate-400 transition details-arrow" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                </svg>
            </summary>

            <div class="mt-3 space-y-3">
                <label class="block">
                    <span class="text-sm text-slate-600">Телефон</span>
                    <input
                        class="{{ $contactInputClass }}"
                        type="tel"
                        name="phone"
                        value="{{ old('phone', $activeShowcase->phone ?? $showcase->phone ?? '') }}"
                        placeholder="+7 900 000-00-00"
                        inputmode="tel"
                    >
                </label>

                <label class="block">
                    <span class="text-sm text-slate-600">Telegram</span>
                    <input
                        class="{{ $contactInputClass }}"
                        type="text"
                        name="telegram"
                        value="{{ old('telegram', $activeShowcase->telegram ?? $showcase->telegram ?? '') }}"
                        placeholder="@username или https://t.me/username"
                    >
                </label>

                <label class="block">
                    <span class="text-sm text-slate-600">Сайт</span>
                    <input
                        class="{{ $contactInputClass }}"
                        type="url"
                        name="website"
                        value="{{ old('website', $activeShowcase->website ?? $showcase->website ?? '') }}"
                        placeholder="https://example.com"
                        inputmode="url"
                    >
                </label>

                @if ($publicUrl)
                    <div class="rounded-2xl bg-sky-50 border-2 border-sky-300 px-3 py-2 text-xs text-slate-700">
                        Публичная ссылка: <a href="{{ $publicUrl }}" class="font-semibold underline" target="_blank" rel="noreferrer">{{ $publicUrl }}</a>
                    </div>
                @endif
            </div>
        </details>

        <details class="{{ $sectionClass }}">
            <summary class="{{ $summaryClass }}">
                <div>
                    <h3 class="text-sm font-semibold text-slate-900">Фото</h3>
                    <p class="mt-1 text-xs text-slate-500">До 5 изображений</p>
                </div>
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-slate-400 transition details-arrow" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                </svg>
            </summary>

            <div class="mt-3 space-y-3">
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
            </div>
        </details>

        <button class="w-full rounded-2xl bg-sky-600 text-white py-3 text-sm font-semibold" type="submit">
            Сохранить витрину
        </button>
    </form>

    <style>
        details > summary::-webkit-details-marker { display: none; }
        details[open] .details-arrow { transform: rotate(180deg); }
    </style>
</x-cabinet-layout>
