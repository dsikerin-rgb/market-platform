<x-cabinet-layout :tenant="$tenant" title="Моя витрина">
    @php
        $tenantName = data_get($tenant, 'display_name') ?: data_get($tenant, 'name') ?: 'Арендатор';
        $tenantSlug = data_get($tenant, 'slug');
        $selectedSpaceId = isset($selectedSpaceId) ? (int) $selectedSpaceId : null;
        $activeShowcase = $spaceShowcase ?? $showcase ?? null;
        $spacesCollection = collect($spaces ?? []);
        $selectedSpace = $spacesCollection->firstWhere('id', $selectedSpaceId);

        $publicUrl = null;
        if ($tenantSlug) {
            $publicUrl = route('cabinet.showcase.public', $tenantSlug)
                . ($selectedSpaceId ? ('?space_id=' . $selectedSpaceId) : '');
        }

        $photos = collect($activeShowcase?->photos ?? [])
            ->filter(fn ($path) => is_string($path) && $path !== '')
            ->values();
        $resolvePhotoUrl = static function (string $photo): string {
            return \App\Support\MarketplaceMediaStorage::previewUrl($photo) ?? '';
        };

        $summaryClass = 'flex w-full items-center justify-between gap-3 cursor-pointer list-none';
        $sectionClass = 'rounded-3xl bg-white border border-slate-200 p-4 shadow-sm';
        $inputClass = 'mt-1.5 w-full rounded-2xl border-slate-200 px-4 py-3 text-sm';
        $contactInputClass = 'mt-1.5 w-full rounded-2xl border-2 border-sky-300 px-4 py-3 text-sm focus:border-sky-500 focus:ring-2 focus:ring-sky-100';

        $selectedSpaceLabel = '';
        if ($selectedSpace) {
            $selectedSpaceBase = trim((string) ($selectedSpace->code ?: $selectedSpace->number ?: $selectedSpace->display_name ?: ('#' . $selectedSpace->id)));
            $selectedSpaceName = trim((string) ($selectedSpace->display_name ?? ''));
            $selectedSpaceLabel = $selectedSpaceBase . ($selectedSpaceName !== '' && $selectedSpaceName !== $selectedSpaceBase ? ' · ' . $selectedSpaceName : '');
        }

        $showcaseScopeLabel = $selectedSpaceLabel !== '' ? $selectedSpaceLabel : 'Общая витрина';
        $showcaseScopeHint = $selectedSpaceLabel !== ''
            ? 'Редактируете отдельную витрину торгового места. Покупатель увидит именно этот профиль при переходе в выбранный отдел.'
            : 'Редактируете главную витрину арендатора. Она используется как базовая карточка продавца на маркетплейсе.';
    @endphp

    <style>
        .showcase-page {
            display: grid;
            gap: 1rem;
        }

        .showcase-panel {
            border-radius: 1.75rem;
            border: 1px solid rgba(203, 213, 225, 0.95);
            background:
                linear-gradient(180deg, rgba(255, 255, 255, 0.98), rgba(248, 250, 252, 0.96));
            box-shadow: 0 14px 32px rgba(15, 23, 42, 0.08);
        }

        .showcase-panel__body {
            padding: 1rem;
        }

        .showcase-hero {
            display: grid;
            gap: 1rem;
        }

        .showcase-hero__eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            width: fit-content;
            border-radius: 9999px;
            border: 1px solid rgba(125, 211, 252, 0.7);
            background: rgba(224, 242, 254, 0.95);
            color: rgb(14 116 144);
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.01em;
            padding: 0.45rem 0.75rem;
        }

        .showcase-hero__title {
            margin: 0;
            font-size: 1.35rem;
            line-height: 1.15;
            font-weight: 700;
            color: rgb(15 23 42);
        }

        .showcase-hero__text {
            margin-top: 0.45rem;
            max-width: 42rem;
            font-size: 0.9rem;
            line-height: 1.55;
            color: rgb(71 85 105);
        }

        .showcase-hero__meta {
            display: flex;
            flex-wrap: wrap;
            gap: 0.65rem;
        }

        .showcase-chip {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            min-height: 2.25rem;
            border-radius: 9999px;
            border: 1px solid rgba(191, 219, 254, 0.9);
            background: rgba(239, 246, 255, 0.95);
            color: rgb(30 64 175);
            font-size: 0.78rem;
            font-weight: 600;
            padding: 0.35rem 0.85rem;
        }

        .showcase-chip--muted {
            border-color: rgba(226, 232, 240, 1);
            background: rgba(248, 250, 252, 0.96);
            color: rgb(71 85 105);
        }

        .showcase-toolbar {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            align-items: center;
            justify-content: space-between;
        }

        .showcase-toolbar__actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
        }

        .showcase-public-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 2.75rem;
            border-radius: 1rem;
            border: 1px solid rgba(56, 189, 248, 0.6);
            background: linear-gradient(135deg, rgba(14, 165, 233, 0.14), rgba(56, 189, 248, 0.22));
            color: rgb(14 116 144);
            font-size: 0.84rem;
            font-weight: 700;
            padding: 0 1rem;
        }

        .showcase-select {
            min-height: 3.5rem;
            border-radius: 1rem;
            border: 1px solid rgba(191, 219, 254, 0.95);
            background: rgba(255, 255, 255, 0.98);
            padding: 0 1rem;
            font-size: 0.95rem;
        }

        .showcase-details {
            overflow: hidden;
        }

        .showcase-details > summary {
            padding: 1rem 1rem 0.85rem;
        }

        .showcase-details__content {
            padding: 0 1rem 1rem;
            display: grid;
            gap: 1rem;
        }

        .showcase-details[open] > summary {
            border-bottom: 1px solid rgba(226, 232, 240, 0.9);
            margin-bottom: 1rem;
        }

        .showcase-field-grid {
            display: grid;
            gap: 1rem;
        }

        .showcase-field-card {
            border-radius: 1.25rem;
            border: 1px solid rgba(226, 232, 240, 0.9);
            background: rgba(248, 250, 252, 0.78);
            padding: 1rem;
        }

        .showcase-field-card__title {
            font-size: 0.84rem;
            font-weight: 700;
            color: rgb(30 41 59);
        }

        .showcase-field-card__text {
            margin-top: 0.35rem;
            font-size: 0.76rem;
            line-height: 1.5;
            color: rgb(100 116 139);
        }

        .showcase-photo-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0.75rem;
        }

        .showcase-photo-card {
            overflow: hidden;
            border-radius: 1.25rem;
            border: 1px solid rgba(226, 232, 240, 0.95);
            background: white;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.85);
        }

        .showcase-photo-card img {
            display: block;
            width: 100%;
            height: 8.5rem;
            object-fit: cover;
        }

        .showcase-upload-note {
            border-radius: 1.25rem;
            border: 1px dashed rgba(148, 163, 184, 0.75);
            background: linear-gradient(180deg, rgba(248, 250, 252, 0.8), rgba(241, 245, 249, 0.92));
            padding: 1rem;
            font-size: 0.82rem;
            line-height: 1.55;
            color: rgb(71 85 105);
        }

        .showcase-save-bar {
            position: sticky;
            bottom: 0.85rem;
            z-index: 10;
            border-radius: 1.25rem;
            border: 1px solid rgba(186, 230, 253, 0.95);
            background: rgba(255, 255, 255, 0.92);
            backdrop-filter: blur(12px);
            padding: 0.75rem;
            box-shadow: 0 16px 28px rgba(14, 165, 233, 0.14);
        }

        .showcase-save-button {
            width: 100%;
            min-height: 3.2rem;
            border-radius: 1rem;
            border: none;
            background: linear-gradient(135deg, rgb(2 132 199), rgb(14 165 233));
            color: white;
            font-size: 0.95rem;
            font-weight: 700;
            box-shadow: 0 14px 24px rgba(2, 132, 199, 0.24);
        }

        @media (min-width: 768px) {
            .showcase-panel__body,
            .showcase-details > summary,
            .showcase-details__content {
                padding-left: 1.25rem;
                padding-right: 1.25rem;
            }

            .showcase-panel__body {
                padding-top: 1.2rem;
                padding-bottom: 1.2rem;
            }

            .showcase-details > summary {
                padding-top: 1.15rem;
            }

            .showcase-field-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .showcase-photo-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
        }

        @media (max-width: 767px) {
            .showcase-page {
                gap: 0.75rem;
            }

            .showcase-panel {
                border-radius: 1.4rem;
            }

            .showcase-panel__body,
            .showcase-details > summary,
            .showcase-details__content {
                padding-left: 0.9rem;
                padding-right: 0.9rem;
            }

            .showcase-panel__body {
                padding-top: 0.9rem;
                padding-bottom: 0.9rem;
            }

            .showcase-toolbar {
                flex-direction: column;
                align-items: stretch;
            }

            .showcase-toolbar__actions {
                width: 100%;
            }

            .showcase-public-link {
                width: 100%;
            }

            .showcase-hero__title {
                font-size: 1.2rem;
            }

            .showcase-hero__text {
                font-size: 0.84rem;
            }

            .showcase-chip {
                width: 100%;
                justify-content: center;
            }

            .showcase-select {
                min-height: 3.15rem;
                font-size: 0.92rem;
            }

            .showcase-field-grid {
                gap: 0.8rem;
            }

            .showcase-field-card,
            .showcase-upload-note {
                padding: 0.85rem;
            }

            .showcase-photo-grid {
                grid-template-columns: 1fr;
            }

            .showcase-photo-card img {
                height: 11rem;
            }

            .showcase-save-bar {
                bottom: 0.6rem;
                padding: 0.6rem;
            }

            .showcase-save-button {
                min-height: 3rem;
                font-size: 0.9rem;
            }
        }
    </style>

    <div class="showcase-page">
    @if($spacesCollection->isNotEmpty())
        <section class="showcase-panel">
            <div class="showcase-panel__body space-y-3">
                <div class="showcase-toolbar">
                    <div>
                        <h2 class="text-base font-semibold text-slate-900">Торговое место</h2>
                        <p class="mt-1 text-xs text-slate-500">Выберите общую витрину или отдельный отдел, который редактируете сейчас.</p>
                    </div>
                    <span class="showcase-chip showcase-chip--muted">{{ $showcaseScopeLabel }}</span>
                </div>
            <form method="GET" action="{{ route('cabinet.showcase.edit') }}">
                <select
                    name="space_id"
                    class="showcase-select w-full"
                    onchange="this.form.submit()"
                >
                    <option value="">Все торговые места (общая витрина)</option>
                    @foreach($spacesCollection as $space)
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

                <p class="text-xs text-slate-500">{{ $showcaseScopeHint }}</p>
            </div>
        </section>
    @endif

    @include('cabinet.partials.sales-nav')

    <form method="POST" action="{{ route('cabinet.showcase.update') }}" enctype="multipart/form-data" class="space-y-3">
        @csrf
        @if(!empty($selectedSpaceId))
            <input type="hidden" name="space_id" value="{{ (int) $selectedSpaceId }}">
        @endif

        <section class="showcase-panel">
            <div class="showcase-panel__body showcase-hero">
                <div class="showcase-toolbar">
                    <div>
                        <span class="showcase-hero__eyebrow">Публичная витрина</span>
                        <h2 class="showcase-hero__title">Оформление карточки продавца</h2>
                        <p class="showcase-hero__text">
                            Этот экран управляет тем, как продавец будет выглядеть на маркетплейсе: название, описание, контакты и фотографии витрины.
                        </p>
                    </div>

                    @if ($publicUrl)
                        <div class="showcase-toolbar__actions">
                            <a
                                href="{{ $publicUrl }}"
                                target="_blank"
                                rel="noreferrer"
                                class="showcase-public-link"
                            >
                                Открыть публичную ссылку
                            </a>
                        </div>
                    @endif
                </div>

                <div class="showcase-hero__meta">
                    <span class="showcase-chip">{{ $tenantName }}</span>
                    <span class="showcase-chip showcase-chip--muted">{{ $showcaseScopeLabel }}</span>
                    @if($photos->isNotEmpty())
                        <span class="showcase-chip showcase-chip--muted">Фото: {{ $photos->count() }}/5</span>
                    @endif
                </div>
            </div>
        </section>

        <details class="showcase-panel showcase-details" open>
            <summary class="{{ $summaryClass }}">
                <div>
                    <h2 class="text-base font-semibold text-slate-900">Профиль витрины</h2>
                    <p class="mt-1 text-xs text-slate-500">Основная информация, которую увидит покупатель в карточке продавца.</p>
                </div>
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-slate-400 transition details-arrow" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                </svg>
            </summary>

            <div class="showcase-details__content">
                <div class="showcase-field-card">
                    <div class="showcase-field-card__title">Что важно заполнить</div>
                    <p class="showcase-field-card__text">
                        Название должно быть коротким и понятным, а описание должно быстро объяснять ассортимент, формат заказа и режим работы.
                    </p>
                </div>

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

        <details class="showcase-panel showcase-details">
            <summary class="{{ $summaryClass }}">
                <div>
                    <h3 class="text-sm font-semibold text-slate-900">Контакты</h3>
                    <p class="mt-1 text-xs text-slate-500">Каналы связи, которые покупатель увидит рядом с витриной.</p>
                </div>
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-slate-400 transition details-arrow" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                </svg>
            </summary>

            <div class="showcase-details__content">
                <div class="showcase-field-grid">
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

                    <label class="block md:col-span-2">
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
                </div>

                @if ($publicUrl)
                    <div class="showcase-upload-note">
                        Публичная ссылка: <a href="{{ $publicUrl }}" class="font-semibold underline" target="_blank" rel="noreferrer">{{ $publicUrl }}</a>
                    </div>
                @endif
            </div>
        </details>

        <details class="showcase-panel showcase-details">
            <summary class="{{ $summaryClass }}">
                <div>
                    <h3 class="text-sm font-semibold text-slate-900">Фото</h3>
                    <p class="mt-1 text-xs text-slate-500">Покажите прилавок, витрину, торговый остров или ассортимент в реальном виде.</p>
                </div>
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-slate-400 transition details-arrow" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                </svg>
            </summary>

            <div class="showcase-details__content">
                <div class="showcase-upload-note">
                    Лучше работают реальные фотографии витрины: общий план, крупный план товара, выкладка на прилавке, вывеска и фото ассортимента. Поддерживается до 5 изображений.
                </div>

                <label class="block">
                    <span class="text-sm text-slate-600">Загрузить фото</span>
                    <input class="mt-1.5 w-full text-sm" type="file" name="photos[]" multiple accept="image/*">
                </label>

                @if($photos->isNotEmpty())
                    <div class="showcase-photo-grid">
                        @foreach($photos as $photo)
                            @php($url = $resolvePhotoUrl($photo))
                            <div class="showcase-photo-card">
                                <img src="{{ $url }}" alt="Фото витрины" loading="lazy">
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="showcase-upload-note">
                        Фото пока не добавлены.
                    </div>
                @endif
            </div>
        </details>

        <div class="showcase-save-bar">
            <button class="showcase-save-button" type="submit">
                Сохранить витрину
            </button>
        </div>
    </form>
    </div>

    <style>
        details > summary::-webkit-details-marker { display: none; }
        details[open] .details-arrow { transform: rotate(180deg); }
    </style>
</x-cabinet-layout>
