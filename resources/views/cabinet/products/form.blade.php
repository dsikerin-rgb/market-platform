<x-cabinet-layout :tenant="$tenant" :title="$isEdit ? 'Редактирование товара' : 'Новый товар'">
    @include('cabinet.partials.sales-nav')

    @php
        $existingImages = collect($product->images ?? [])
            ->filter(fn ($path) => is_string($path) && $path !== '')
            ->values();

        $currentCategoryId = (int) old('category_id', (int) ($product->category_id ?? 0));
        $currentSpaceId = (int) old('market_space_id', (int) ($product->market_space_id ?? 0));
        $currentIsActive = (bool) old('is_active', (bool) $product->is_active);
        $currentIsFeatured = (bool) old('is_featured', (bool) $product->is_featured);

        $currentCategory = $categories->firstWhere('id', $currentCategoryId);
        $currentSpace = $spaces->firstWhere('id', $currentSpaceId);

        $categoryLabel = $currentCategory?->name ?: 'Без категории';
        $spaceLabel = 'Без привязки';

        if ($currentSpace) {
            $spaceCode = trim((string) ($currentSpace->code ?: $currentSpace->number ?: ''));
            $spaceName = trim((string) ($currentSpace->display_name ?? ''));
            $spaceLabel = trim($spaceCode . ($spaceName !== '' ? ' · ' . $spaceName : '')) ?: ('#' . $currentSpace->id);
        } elseif ($canManageGlobalProducts) {
            $spaceLabel = 'Вся витрина';
        }

        $photoCount = $existingImages->count();
        $backParams = $currentSpaceId > 0 ? ['space_id' => $currentSpaceId] : [];

        $marketRouteKey = data_get($tenant, 'market.slug');
        if (! filled($marketRouteKey) && (int) ($tenant->market_id ?? 0) > 0) {
            $marketRouteKey = \App\Models\Market::query()
                ->whereKey((int) $tenant->market_id)
                ->value('slug') ?: (string) $tenant->market_id;
        }

        $productRouteKey = filled($product->slug ?? null) ? (string) $product->slug : '';

        $productShareUrl = $isEdit && filled($marketRouteKey) && $productRouteKey !== ''
            ? route('marketplace.product.show', ['marketSlug' => $marketRouteKey, 'productSlug' => $productRouteKey])
            : null;

        $qrGenerator = app(\App\Support\QrCodeDataUriGenerator::class);
        $productShareQr = $productShareUrl ? $qrGenerator->generateSvgDataUri($productShareUrl, 8) : null;

        $fieldClass = 'mt-1.5 w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-900 shadow-sm outline-none transition placeholder:text-slate-400 focus:border-sky-400 focus:ring-4 focus:ring-sky-100';
        $selectClass = $fieldClass . ' pr-10';
        $textareaClass = $fieldClass . ' min-h-[11rem] resize-y';
        $checkboxClass = 'h-4 w-4 rounded border-slate-300 text-sky-600 focus:ring-4 focus:ring-sky-100';
    @endphp

    <style>
        .cabinet-share-modal {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 60;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            background: rgba(15, 23, 42, 0.55);
        }

        .cabinet-share-modal:target {
            display: flex;
        }
    </style>

    <form method="POST" action="{{ $formAction }}" enctype="multipart/form-data" class="space-y-4">
        @csrf

        <section class="overflow-hidden rounded-[2rem] border border-sky-100 bg-gradient-to-br from-white via-sky-50 to-slate-50 p-5 shadow-[0_14px_34px_rgba(15,23,42,0.08)] md:p-6">
            <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_auto] xl:items-start">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <a
                            href="{{ route('cabinet.products.index', $backParams) }}"
                            class="inline-flex items-center rounded-full border border-slate-200 bg-white/90 px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-sm transition hover:border-sky-200 hover:text-sky-700"
                        >
                            ← К товарам
                        </a>
                        <span class="inline-flex items-center rounded-full bg-sky-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm">
                            {{ $isEdit ? 'Редактирование товара' : 'Новый товар' }}
                        </span>
                    </div>

                    <h2 class="mt-4 text-2xl font-semibold tracking-tight text-slate-900 md:text-3xl">
                        {{ $isEdit ? 'Карточка товара' : 'Добавление товара' }}
                    </h2>
                    <p class="hidden">
                        Короткая карточка товара без лишних панелей: название, цена, фото и видимость.
                    </p>

                    <div class="mt-4 flex flex-wrap gap-2">
                        <span class="inline-flex items-center rounded-full border border-slate-200 bg-white/90 px-3 py-1 text-xs font-semibold text-slate-700 shadow-sm">
                            Категория: {{ $categoryLabel }}
                        </span>
                        <span class="inline-flex items-center rounded-full border border-slate-200 bg-white/90 px-3 py-1 text-xs font-semibold text-slate-700 shadow-sm">
                            Место: {{ $spaceLabel }}
                        </span>
                        <span class="inline-flex items-center rounded-full border border-slate-200 bg-white/90 px-3 py-1 text-xs font-semibold text-slate-700 shadow-sm">
                            Фото: {{ $photoCount }}
                        </span>
                        <span class="inline-flex items-center rounded-full {{ $currentIsActive ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-slate-200 bg-slate-100 text-slate-600' }} px-3 py-1 text-xs font-semibold shadow-sm">
                            {{ $currentIsActive ? 'Показывается в маркетплейсе' : 'Скрыт из маркетплейса' }}
                        </span>
                        @if($currentIsFeatured)
                            <span class="inline-flex items-center rounded-full border border-amber-200 bg-amber-50 px-3 py-1 text-xs font-semibold text-amber-700 shadow-sm">
                                В подборках
                            </span>
                        @endif
                    </div>

                    @if($productShareUrl)
                        <div class="mt-2 flex w-full justify-start xl:justify-end">
                                <a
                                    href="#product-share-modal"
                                    class="inline-flex items-center justify-center rounded-2xl border border-sky-200 bg-white px-4 py-3 text-sm font-semibold text-sky-700 shadow-sm transition hover:border-sky-300 hover:bg-sky-50 xl:min-w-[17rem]"
                                >
                                    Поделиться ссылкой на товар
                                </a>
                        </div>
                    @endif
                </div>

                <div class="inline-flex items-center rounded-2xl border border-white/80 bg-white/85 px-4 py-3 text-sm font-semibold text-slate-700 shadow-sm backdrop-blur xl:min-w-[17rem] xl:justify-center">
                    {{ $currentIsActive ? 'Активен и виден покупателям' : 'Скрыт и виден только в кабинете' }}
                </div>
            </div>
        </section>

        <div class="grid gap-4 xl:grid-cols-[minmax(0,1.45fr)_minmax(18rem,26rem)]">
            <div class="space-y-4">
                <section class="rounded-[1.75rem] border border-slate-200 bg-white p-5 shadow-[0_10px_24px_rgba(15,23,42,0.06)] md:p-6">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <h3 class="text-base font-semibold text-slate-900">Карточка товара</h3>
                            <p class="mt-1 text-xs leading-5 text-slate-500">
                                Название, категория, цена, остаток и описание.
                            </p>
                        </div>
                        <span class="inline-flex w-fit items-center rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-xs font-semibold text-slate-600">
                            Обязательно: название
                        </span>
                    </div>

                    <div class="mt-6 space-y-4">
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">Название товара</span>
                            <input
                                type="text"
                                name="title"
                                value="{{ old('title', $product->title) }}"
                                maxlength="190"
                                required
                                class="{{ $fieldClass }}"
                                placeholder="Например: Домашний сыр"
                            >
                        </label>

                        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <label class="block">
                                <span class="text-sm font-medium text-slate-700">Категория</span>
                                <select
                                    name="category_id"
                                    class="{{ $selectClass }}"
                                >
                                    <option value="">Без категории</option>
                                    @foreach($categories as $category)
                                        <option value="{{ $category->id }}" @selected($currentCategoryId === (int) $category->id)>
                                            {{ $category->name }}
                                        </option>
                                    @endforeach
                                </select>
                            </label>

                            <label class="block">
                                <span class="text-sm font-medium text-slate-700">Торговое место</span>
                                <select
                                    name="market_space_id"
                                    class="{{ $selectClass }}"
                                >
                                    @if($canManageGlobalProducts)
                                        <option value="">Без привязки / вся витрина</option>
                                    @else
                                        <option value="">Выберите торговое место</option>
                                    @endif
                                    @foreach($spaces as $space)
                                        @php
                                            $spaceCode = trim((string) ($space->code ?: $space->number ?: $space->display_name ?: ('#' . $space->id)));
                                            $spaceName = trim((string) ($space->display_name ?? ''));
                                            $spaceOptionLabel = $spaceCode . ($spaceName !== '' ? ' · ' . $spaceName : '');
                                        @endphp
                                        <option value="{{ $space->id }}" @selected($currentSpaceId === (int) $space->id)>
                                            {{ $spaceOptionLabel }}
                                        </option>
                                    @endforeach
                                </select>
                            </label>
                        </div>

                        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <label class="block">
                                <span class="text-sm font-medium text-slate-700">Цена</span>
                                <input
                                    type="number"
                                    name="price"
                                    value="{{ old('price', $product->price !== null ? number_format((float) $product->price, 2, '.', '') : '') }}"
                                    min="0"
                                    step="0.01"
                                    class="{{ $fieldClass }}"
                                    placeholder="0.00"
                                    inputmode="decimal"
                                >
                            </label>

                            <label class="block">
                                <span class="text-sm font-medium text-slate-700">Остаток</span>
                                <input
                                    type="number"
                                    name="stock_qty"
                                    value="{{ old('stock_qty', (int) ($product->stock_qty ?? 0)) }}"
                                    min="0"
                                    step="1"
                                    class="{{ $fieldClass }}"
                                    inputmode="numeric"
                                >
                            </label>
                        </div>

                        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <label class="block">
                                <span class="text-sm font-medium text-slate-700">Артикул</span>
                                <input
                                    type="text"
                                    name="sku"
                                    value="{{ old('sku', $product->sku) }}"
                                    maxlength="120"
                                    class="{{ $fieldClass }}"
                                    placeholder="Например: CHEESE-001"
                                >
                            </label>

                            <label class="block">
                                <span class="text-sm font-medium text-slate-700">Единица</span>
                                <input
                                    type="text"
                                    name="unit"
                                    value="{{ old('unit', $product->unit) }}"
                                    maxlength="40"
                                    class="{{ $fieldClass }}"
                                    placeholder="шт, кг, упаковка"
                                >
                            </label>
                        </div>

                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">Описание</span>
                            <textarea
                                name="description"
                                rows="6"
                                class="{{ $textareaClass }}"
                                placeholder="Кратко опишите товар, преимущества и условия выдачи"
                            >{{ old('description', $product->description) }}</textarea>
                        </label>
                    </div>
                </section>
            </div>

            <div class="w-full space-y-4 xl:self-start">
                <section class="rounded-[1.75rem] border border-slate-200 bg-white p-5 shadow-[0_10px_24px_rgba(15,23,42,0.06)] md:p-6">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h3 class="text-base font-semibold text-slate-900">Фото товара</h3>
                            <p class="mt-1 text-sm leading-6 text-slate-500">
                                До 8 изображений. Первое фото будет главным в карточке, остальные можно удалить по отдельности.
                            </p>
                        </div>
                        <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600">
                            {{ $photoCount }}/8
                        </span>
                    </div>

                    <div class="mt-6 space-y-4">
                        <div class="rounded-2xl border border-slate-200 bg-slate-50/80 px-4 py-3">
                            <p class="text-sm font-semibold text-slate-900">Текущие фото</p>
                            <p class="mt-1 text-xs leading-5 text-slate-500">
                                Нажмите на крестик у фото, чтобы удалить его сразу. Первое оставшееся фото автоматически станет основным.
                            </p>
                        </div>

                        <div
                            class="grid justify-start gap-3"
                            style="grid-template-columns: repeat(auto-fit, minmax(11rem, 11rem));"
                            data-existing-photos-grid
                            data-image-delete-url="{{ route('cabinet.products.images.destroy', ['product' => (int) $product->id]) }}"
                            data-csrf-token="{{ csrf_token() }}"
                        >
                            @foreach($existingImages as $index => $imagePath)
                                @php
                                    $imagePreview = \App\Support\MarketplaceMediaStorage::previewUrl($imagePath) ?? \App\Support\MarketplaceMediaStorage::url($imagePath);
                                    $isCoverImage = $index === 0;
                                @endphp

                                <article
                                    class="group overflow-hidden rounded-[1.25rem] border border-slate-200 bg-white shadow-sm transition"
                                    data-existing-photo-card
                                    data-image-path="{{ $imagePath }}"
                                >
                                    <div class="flex items-center justify-between gap-2 border-b border-slate-100 px-2.5 py-2">
                                        <div class="min-w-0" data-existing-photo-badge>
                                            <span class="inline-flex max-w-full items-center truncate rounded-full bg-slate-100 px-2.5 py-1 text-[11px] font-semibold text-slate-700 ring-1 ring-slate-200">
                                                {{ $isCoverImage ? 'Основное фото' : 'Фото ' . ($index + 1) }}
                                            </span>
                                        </div>
                                        <button
                                            type="button"
                                            class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-white text-slate-500 shadow-sm ring-1 ring-slate-200 transition hover:bg-rose-50 hover:text-rose-600"
                                            data-remove-existing-photo
                                            aria-label="Удалить фото {{ $index + 1 }}"
                                            title="Удалить фото"
                                        >
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                                                <path fill-rule="evenodd" d="M4.22 4.22a.75.75 0 011.06 0L10 8.94l4.72-4.72a.75.75 0 111.06 1.06L11.06 10l4.72 4.72a.75.75 0 11-1.06 1.06L10 11.06l-4.72 4.72a.75.75 0 11-1.06-1.06L8.94 10 4.22 5.28a.75.75 0 010-1.06z" clip-rule="evenodd"/>
                                            </svg>
                                        </button>
                                    </div>
                                    <div class="aspect-[4/3] w-full overflow-hidden bg-slate-100">
                                        <img
                                            src="{{ $imagePreview }}"
                                            alt="{{ $isCoverImage ? 'Основное фото товара' : 'Фото товара ' . ($index + 1) }}"
                                            class="block h-full w-full object-cover"
                                            loading="lazy"
                                        >
                                    </div>
                                </article>
                            @endforeach

                            <label class="group relative flex min-h-[11.5rem] cursor-pointer flex-col items-center justify-center rounded-[1.25rem] border-2 border-dashed border-sky-200 bg-sky-50/60 p-4 text-center transition hover:border-sky-400 hover:bg-sky-50">
                                <input
                                    type="file"
                                    name="new_images[]"
                                    multiple
                                    accept="image/*"
                                    style="position:absolute;inset:0;width:100%;height:100%;opacity:0;cursor:pointer;"
                                    data-product-image-input
                                >

                                <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-white text-sky-700 shadow-sm ring-1 ring-sky-100">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5V6.75A2.25 2.25 0 015.25 4.5h13.5A2.25 2.25 0 0121 6.75v9.75M3 16.5l4.172-4.172a2.25 2.25 0 013.182 0L12 14.75l2.646-2.422a2.25 2.25 0 013.182.1L21 16.5M8.25 10.5h.008v.008H8.25V10.5z"/>
                                    </svg>
                                </span>
                                <span class="mt-3 text-sm font-semibold text-slate-900">Добавить фото</span>
                                <span class="mt-2 max-w-[8.5rem] text-[11px] leading-4 text-slate-500" data-product-input-caption>
                                    JPG, PNG, WEBP
                                </span>
                            </label>
                        </div>

                        <div class="{{ $existingImages->isEmpty() ? '' : 'hidden ' }}rounded-3xl border border-dashed border-slate-200 bg-slate-50 px-5 py-10 text-center" data-existing-photos-empty>
                            <p class="text-sm font-semibold text-slate-800">Фотографии еще не добавлены</p>
                            <p class="mt-1 text-xs leading-5 text-slate-500">
                                Добавьте первое фото товара, чтобы карточка выглядела аккуратнее в каталоге и на витрине.
                            </p>
                        </div>

                        <div class="hidden rounded-3xl border border-emerald-200 bg-emerald-50/70 p-4" data-product-upload-preview>
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <p class="text-sm font-semibold text-slate-900">Новые фото</p>
                                    <p class="mt-1 text-xs leading-5 text-slate-600">
                                        Эти изображения появятся в карточке после нажатия «{{ $submitLabel }}». Ненужные можно убрать крестиком на карточке.
                                    </p>
                                </div>
                                <span class="rounded-full bg-white px-2.5 py-1 text-xs font-semibold text-emerald-700 ring-1 ring-emerald-200" data-product-upload-count>0 фото</span>
                            </div>
                            <div class="mt-4 grid justify-start gap-3" style="grid-template-columns: repeat(auto-fit, minmax(11rem, 11rem));" data-product-upload-grid></div>
                        </div>

                    </div>
                </section>

                <section class="rounded-[1.75rem] border border-slate-200 bg-white p-5 shadow-[0_10px_24px_rgba(15,23,42,0.06)] md:p-6">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h3 class="text-base font-semibold text-slate-900">Публикация</h3>
                            <p class="mt-1 text-xs leading-5 text-slate-500">
                                Видимость товара и участие в подборках.
                            </p>
                        </div>
                    </div>

                    <div class="mt-4 grid gap-3">
                        <label class="flex items-center gap-3 rounded-2xl border border-slate-200 bg-slate-50/80 px-4 py-3 transition hover:border-sky-200 hover:bg-sky-50/60">
                            <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-md border border-slate-300 bg-white">
                                <input
                                    type="checkbox"
                                    name="is_active"
                                    value="1"
                                    @checked($currentIsActive)
                                    class="{{ $checkboxClass }}"
                                >
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block text-sm font-semibold text-slate-900">Показывать в маркетплейсе</span>
                                <span class="mt-0.5 block text-xs text-slate-500">
                                    Товар виден покупателям
                                </span>
                            </span>
                            <span class="inline-flex h-8 items-center rounded-full px-3 text-xs font-semibold {{ $currentIsActive ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600' }}">
                                {{ $currentIsActive ? 'Включено' : 'Выключено' }}
                            </span>
                        </label>

                        <label class="flex items-center gap-3 rounded-2xl border border-slate-200 bg-slate-50/80 px-4 py-3 transition hover:border-amber-200 hover:bg-amber-50/60">
                            <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-md border border-slate-300 bg-white">
                                <input
                                    type="checkbox"
                                    name="is_featured"
                                    value="1"
                                    @checked($currentIsFeatured)
                                    class="{{ $checkboxClass }}"
                                >
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block text-sm font-semibold text-slate-900">Показывать в подборках</span>
                                <span class="mt-0.5 block text-xs text-slate-500">
                                    Дополнительное продвижение на витрине
                                </span>
                            </span>
                            <span class="inline-flex h-8 items-center rounded-full px-3 text-xs font-semibold {{ $currentIsFeatured ? 'bg-amber-100 text-amber-700' : 'bg-slate-100 text-slate-600' }}">
                                {{ $currentIsFeatured ? 'Да' : 'Нет' }}
                            </span>
                        </label>
                    </div>
                </section>
            </div>
        </div>

        <div class="sticky bottom-3 z-20 rounded-3xl border border-slate-200 bg-white/95 p-3 shadow-[0_12px_28px_rgba(15,23,42,0.10)] backdrop-blur sm:static sm:border-0 sm:bg-transparent sm:p-0 sm:shadow-none">
            <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <a
                    href="{{ route('cabinet.products.index', $backParams) }}"
                    class="inline-flex items-center justify-center rounded-2xl border border-slate-300 bg-white px-4 py-3 text-sm font-semibold text-slate-700 transition hover:border-slate-400 hover:text-slate-900"
                >
                    Отмена
                </a>
                <button
                    class="inline-flex items-center justify-center rounded-2xl border border-sky-600 bg-sky-600 px-5 py-3 text-sm font-semibold text-white shadow-[0_10px_22px_rgba(2,132,199,0.22)] transition hover:border-sky-700 hover:bg-sky-700"
                    type="submit"
                >
                    {{ $submitLabel }}
                </button>
            </div>
        </div>
    </form>

    @if($productShareUrl)
        <div id="product-share-modal" class="cabinet-share-modal">
            <a href="#" class="absolute inset-0"></a>
            <div class="relative z-10 w-full max-w-md rounded-[2rem] border border-slate-200 bg-white p-5 shadow-[0_24px_60px_rgba(15,23,42,0.24)] md:p-6">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-500">Поделиться</p>
                        <h3 class="mt-2 text-xl font-semibold text-slate-900">QR-код товара</h3>
                        <p class="mt-2 text-sm leading-6 text-slate-500">Покупатель сможет открыть карточку товара по ссылке или QR-коду.</p>
                    </div>
                    <a href="#" class="inline-flex h-10 w-10 items-center justify-center rounded-full border border-slate-200 bg-white text-slate-500 transition hover:bg-slate-50 hover:text-slate-700" aria-label="Закрыть окно">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M4.22 4.22a.75.75 0 011.06 0L10 8.94l4.72-4.72a.75.75 0 111.06 1.06L11.06 10l4.72 4.72a.75.75 0 11-1.06 1.06L10 11.06l-4.72 4.72a.75.75 0 11-1.06-1.06L8.94 10 4.22 5.28a.75.75 0 010-1.06z" clip-rule="evenodd"/>
                        </svg>
                    </a>
                </div>

                <div class="mt-5 rounded-[1.5rem] border border-slate-200 bg-slate-50 p-4">
                    <div class="mx-auto flex h-[18rem] w-[18rem] max-w-full items-center justify-center rounded-[1.5rem] bg-white p-4 shadow-sm ring-1 ring-slate-100">
                        <img src="{{ $productShareQr }}" alt="QR-код товара" class="h-full w-full object-contain">
                    </div>
                </div>

                <div class="mt-4 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Ссылка</p>
                    <p class="mt-2 break-all text-sm text-slate-700">{{ $productShareUrl }}</p>
                </div>

                <div class="mt-4 flex flex-col gap-2 sm:flex-row">
                    <a href="{{ $productShareUrl }}" target="_blank" rel="noreferrer" class="inline-flex flex-1 items-center justify-center rounded-2xl border border-sky-600 bg-sky-600 px-4 py-3 text-sm font-semibold text-white transition hover:bg-sky-700">
                        Открыть ссылку
                    </a>
                    <a href="#" class="inline-flex items-center justify-center rounded-2xl border border-slate-300 bg-white px-4 py-3 text-sm font-semibold text-slate-700 transition hover:border-slate-400 hover:text-slate-900">
                        Закрыть
                    </a>
                </div>
            </div>
        </div>
    @endif

    <script>
        (() => {
            const form = document.querySelector('form[action="{{ $formAction }}"]');
            const input = document.querySelector('[data-product-image-input]');
            const preview = document.querySelector('[data-product-upload-preview]');
            const grid = document.querySelector('[data-product-upload-grid]');
            const count = document.querySelector('[data-product-upload-count]');
            const caption = document.querySelector('[data-product-input-caption]');
            const scrollContainer = document.querySelector('.cabinet-main');
            const existingPhotosGrid = document.querySelector('[data-existing-photos-grid]');
            const existingPhotosEmpty = document.querySelector('[data-existing-photos-empty]');
            const csrfMeta = document.querySelector('meta[name="csrf-token"]');

            if (!input || !preview || !grid || !count || !caption) {
                return;
            }

            const readCookie = (name) => {
                const escapedName = name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
                const match = document.cookie.match(new RegExp(`(?:^|; )${escapedName}=([^;]*)`));

                if (!match) {
                    return null;
                }

                try {
                    return decodeURIComponent(match[1]);
                } catch (error) {
                    return match[1];
                }
            };

            const syncCsrfToken = () => {
                const cookieToken = readCookie('XSRF-TOKEN');
                const token = cookieToken || csrfMeta?.getAttribute('content') || '';

                if (!token) {
                    return '';
                }

                if (csrfMeta) {
                    csrfMeta.setAttribute('content', token);
                }

                if (form) {
                    const tokenInput = form.querySelector('input[name="_token"]');

                    if (tokenInput) {
                        tokenInput.value = token;
                    }
                }

                if (existingPhotosGrid) {
                    existingPhotosGrid.dataset.csrfToken = token;
                }

                return token;
            };

            const captureScrollState = () => ({
                windowY: window.scrollY || window.pageYOffset || 0,
                containerY: scrollContainer ? scrollContainer.scrollTop : 0,
            });

            const restoreScrollState = (state) => {
                const apply = () => {
                    if (scrollContainer) {
                        scrollContainer.scrollTop = state.containerY;
                    }

                    window.scrollTo(0, state.windowY);
                };

                requestAnimationFrame(() => {
                    apply();
                    requestAnimationFrame(apply);
                });
            };

            const syncExistingPhotoState = () => {
                if (!existingPhotosGrid) {
                    return;
                }

                const cards = Array.from(existingPhotosGrid.querySelectorAll('[data-existing-photo-card]'));

                cards.forEach((card, index) => {
                    const badge = card.querySelector('[data-existing-photo-badge]');
                    const title = card.querySelector('[data-existing-photo-title]');
                    const description = card.querySelector('[data-existing-photo-description]');
                    const removeButton = card.querySelector('[data-remove-existing-photo]');
                    const isCover = index === 0;

                    if (badge) {
                        badge.textContent = isCover ? 'Основное фото' : `Фото ${index + 1}`;
                        badge.title = badge.textContent;
                    }

                    if (title) {
                        title.textContent = isCover ? 'Основное фото' : `Фото ${index + 1}`;
                    }

                    if (description) {
                        description.textContent = isCover
                            ? 'Используется как главное изображение товара.'
                            : 'Дополнительное фото товара.';
                    }

                    if (removeButton) {
                        removeButton.setAttribute('aria-label', `Удалить фото ${index + 1}`);
                    }
                });

                if (existingPhotosEmpty) {
                    existingPhotosEmpty.classList.toggle('hidden', cards.length > 0);
                }
            };

            const assignFiles = (files) => {
                if (typeof DataTransfer === 'undefined') {
                    return;
                }

                const transfer = new DataTransfer();

                files.forEach((file) => transfer.items.add(file));

                input.files = transfer.files;
            };

            const render = () => {
                const scrollState = captureScrollState();
                const files = Array.from(input.files || []).filter((file) => file.type.startsWith('image/'));

                grid.innerHTML = '';

                if (files.length === 0) {
                    preview.classList.add('hidden');
                    count.textContent = '0 фото';
                    caption.textContent = 'Файлы еще не выбраны';
                    restoreScrollState(scrollState);
                    return;
                }

                preview.classList.remove('hidden');
                count.textContent = files.length + ' фото';
                caption.textContent = files.length === 1
                    ? files[0].name
                    : 'Выбрано файлов: ' + files.length;

                files.forEach((file, index) => {
                    const url = URL.createObjectURL(file);
                    const item = document.createElement('div');
                    item.className = 'relative overflow-hidden rounded-[1.5rem] border border-emerald-200 bg-white shadow-sm';
                    item.innerHTML = `
                        <button type="button" class="absolute right-3 top-3 z-10 inline-flex h-8 w-8 items-center justify-center rounded-full bg-white/95 text-slate-500 shadow-sm ring-1 ring-slate-200 transition hover:bg-rose-50 hover:text-rose-600" data-remove-upload-index="${index}" aria-label="Убрать фото ${index + 1}">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M4.22 4.22a.75.75 0 011.06 0L10 8.94l4.72-4.72a.75.75 0 111.06 1.06L11.06 10l4.72 4.72a.75.75 0 11-1.06 1.06L10 11.06l-4.72 4.72a.75.75 0 11-1.06-1.06L8.94 10 4.22 5.28a.75.75 0 010-1.06z" clip-rule="evenodd"/>
                            </svg>
                        </button>
                        <div class="absolute left-3 top-3 z-10 inline-flex items-center rounded-full bg-white/95 px-2.5 py-1 text-[11px] font-semibold text-slate-700 shadow-sm ring-1 ring-slate-200">
                            Новое фото
                        </div>
                        <div style="aspect-ratio: 4 / 3; overflow: hidden; background: #f8fafc;">
                            <img src="${url}" alt="" class="h-full w-full object-cover">
                        </div>
                        <div class="border-t border-emerald-200 px-4 py-3">
                            <div class="flex items-center justify-between gap-2">
                                <span class="min-w-0 truncate text-sm font-semibold text-slate-900">${file.name}</span>
                                <span class="shrink-0 rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-semibold text-emerald-700">${index + 1}</span>
                            </div>
                            <p class="mt-1 text-xs leading-5 text-slate-500">Будет добавлено после сохранения товара.</p>
                        </div>
                    `;
                    grid.appendChild(item);
                });

                grid.querySelectorAll('[data-remove-upload-index]').forEach((button) => {
                    button.addEventListener('click', () => {
                        const removeIndex = Number(button.getAttribute('data-remove-upload-index'));
                        const nextFiles = files.filter((_, fileIndex) => fileIndex !== removeIndex);

                        assignFiles(nextFiles);
                        render();
                    });
                });

                restoreScrollState(scrollState);
            };

            if (existingPhotosGrid) {
                existingPhotosGrid.querySelectorAll('[data-remove-existing-photo]').forEach((button) => {
                    button.addEventListener('click', async () => {
                        const card = button.closest('[data-existing-photo-card]');

                        if (!card || button.dataset.loading === '1') {
                            return;
                        }

                        const url = existingPhotosGrid.dataset.imageDeleteUrl;
                        const csrfToken = existingPhotosGrid.dataset.csrfToken;
                        const imagePath = card.dataset.imagePath;

                        if (!url || !csrfToken || !imagePath) {
                            return;
                        }

                        button.dataset.loading = '1';
                        button.disabled = true;
                        button.classList.add('opacity-60');

                        try {
                            const formData = new FormData();
                            formData.append('_token', csrfToken);
                            formData.append('path', imagePath);

                            const response = await fetch(url, {
                                method: 'POST',
                                headers: {
                                    'Accept': 'application/json',
                                    'X-CSRF-TOKEN': syncCsrfToken() || csrfToken,
                                },
                                body: formData,
                            });

                            if (response.status === 419) {
                                window.location.replace(window.location.pathname + window.location.search);
                                return;
                            }

                            if (!response.ok) {
                                throw new Error('Delete failed');
                            }

                            const scrollState = captureScrollState();
                            card.remove();
                            syncExistingPhotoState();
                            restoreScrollState(scrollState);
                        } catch (error) {
                            button.disabled = false;
                            button.dataset.loading = '0';
                            button.classList.remove('opacity-60');
                            window.alert('Не удалось удалить фото. Обновите страницу и попробуйте еще раз.');
                            return;
                        }
                    });
                });

                syncExistingPhotoState();
            }

            syncCsrfToken();

            if (form) {
                form.addEventListener('submit', () => {
                    syncCsrfToken();
                });
            }

            window.addEventListener('pageshow', () => {
                syncCsrfToken();
            });

            input.addEventListener('change', render);
        })();
    </script>

</x-cabinet-layout>
