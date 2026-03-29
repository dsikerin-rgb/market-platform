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

        $fieldClass = 'mt-1.5 w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-900 shadow-sm outline-none transition placeholder:text-slate-400 focus:border-sky-400 focus:ring-4 focus:ring-sky-100';
        $selectClass = $fieldClass . ' pr-10';
        $textareaClass = $fieldClass . ' min-h-[11rem] resize-y';
        $checkboxClass = 'h-4 w-4 rounded border-slate-300 text-sky-600 focus:ring-4 focus:ring-sky-100';
    @endphp

    <form method="POST" action="{{ $formAction }}" enctype="multipart/form-data" class="space-y-4">
        @csrf

        <section class="overflow-hidden rounded-[2rem] border border-sky-100 bg-gradient-to-br from-white via-sky-50 to-slate-50 p-5 shadow-[0_14px_34px_rgba(15,23,42,0.08)] md:p-6">
            <div>
                <div class="max-w-3xl">
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
                    <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-600">
                        Заполните основные параметры товара, обновите фотографии и настройте показ в витрине.
                        Витрина и карточка товара управляются отдельно, поэтому здесь собраны только рабочие поля.
                    </p>

                    <div class="mt-4 flex flex-wrap gap-2 pb-4 sm:pb-5">
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
                </div>

                <div class="mt-2 grid gap-3 md:grid-cols-2 lg:max-w-4xl">
                    <div class="rounded-3xl border border-white/80 bg-white/80 p-4 shadow-sm backdrop-blur">
                        <div class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-500">Статус товара</div>
                        <div class="mt-2 text-lg font-semibold text-slate-900">
                            {{ $currentIsActive ? 'Активен' : 'Скрыт' }}
                        </div>
                        <div class="mt-1 text-sm text-slate-600">
                            {{ $currentIsActive ? 'Товар виден покупателям' : 'Товар сохранен только в кабинете' }}
                        </div>
                    </div>
                    <div class="rounded-3xl border border-white/80 bg-white/80 p-4 shadow-sm backdrop-blur">
                        <div class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-500">Фото в карточке</div>
                        <div class="mt-2 text-lg font-semibold text-slate-900">
                            {{ $photoCount > 0 ? $photoCount . ' шт.' : 'Нет фото' }}
                        </div>
                        <div class="mt-1 text-sm text-slate-600">
                            Первое изображение показывается как основное
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <div class="grid gap-4 xl:grid-cols-[minmax(0,1.45fr)_minmax(18rem,26rem)]">
            <div class="space-y-4">
                <section class="rounded-[1.75rem] border border-slate-200 bg-white p-5 shadow-[0_10px_24px_rgba(15,23,42,0.06)] md:p-6">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <h3 class="text-base font-semibold text-slate-900">Карточка товара</h3>
                            <p class="mt-1 text-sm leading-6 text-slate-500">
                                Название, цена, остаток, артикул и описание. Эти поля формируют основную карточку товара.
                            </p>
                        </div>
                        <span class="inline-flex w-fit items-center rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-xs font-semibold text-slate-600">
                            Обязательное: название
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
                        @if($existingImages->isNotEmpty())
                            <div class="rounded-2xl border border-slate-200 bg-slate-50/80 px-4 py-3">
                                <p class="text-sm font-semibold text-slate-900">Текущие фото</p>
                                <p class="mt-1 text-xs leading-5 text-slate-500">
                                    Эти изображения уже сохранены у товара. Чтобы удалить фото, отметьте его чекбоксом и нажмите «{{ $submitLabel }}».
                                </p>
                            </div>

                            <div style="max-width: 22rem;">
                                @php
                                    $coverImage = $existingImages->first();
                                    $coverPreview = \App\Support\MarketplaceMediaStorage::previewUrl($coverImage) ?? \App\Support\MarketplaceMediaStorage::url($coverImage);
                                @endphp

                                <div class="overflow-hidden rounded-[1.75rem] border border-slate-200 bg-white shadow-sm">
                                    <div class="aspect-[4/3] w-full overflow-hidden rounded-[1.75rem]">
                                        <img
                                            src="{{ $coverPreview }}"
                                            alt="Основное фото товара"
                                            class="block h-full w-full object-cover"
                                            loading="lazy"
                                        >
                                    </div>
                                    <div class="space-y-3 border-t border-slate-200 px-4 py-3">
                                        <div class="flex items-start justify-between gap-3">
                                            <div>
                                                <p class="text-sm font-semibold text-slate-900">Основное фото</p>
                                                <p class="mt-1 text-xs leading-5 text-slate-500">Используется как главное изображение товара в карточке и каталоге.</p>
                                            </div>
                                            <span class="inline-flex h-8 items-center justify-center rounded-full bg-slate-100 px-3 text-xs font-semibold text-slate-600">
                                                1
                                            </span>
                                        </div>
                                        <label class="inline-flex cursor-pointer items-center gap-2 rounded-full bg-rose-50 px-3 py-1.5 text-xs font-semibold text-rose-700 ring-1 ring-rose-200">
                                            <input type="checkbox" name="remove_images[]" value="{{ $coverImage }}" class="{{ $checkboxClass }}">
                                            <span>Удалить после сохранения</span>
                                        </label>
                                    </div>
                                </div>
                            </div>

                            @if($existingImages->count() > 1)
                                <div class="grid gap-3" style="grid-template-columns: repeat(auto-fit, minmax(9.5rem, 1fr)); max-width: 22rem;">
                                    @foreach($existingImages->skip(1) as $index => $imagePath)
                                        @php
                                            $imagePreview = \App\Support\MarketplaceMediaStorage::previewUrl($imagePath) ?? \App\Support\MarketplaceMediaStorage::url($imagePath);
                                        @endphp

                                        <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                                            <img
                                                src="{{ $imagePreview }}"
                                                alt="Фото товара {{ $index + 2 }}"
                                                class="block w-full object-cover"
                                                style="height: 88px;"
                                                loading="lazy"
                                            >
                                            <div class="space-y-2 border-t border-slate-200 px-3 py-2">
                                                <div class="flex items-center justify-between gap-2">
                                                    <span class="text-xs font-semibold text-slate-900">Фото {{ $index + 2 }}</span>
                                                    <span class="inline-flex h-6 items-center justify-center rounded-full bg-slate-100 px-2 text-[11px] font-semibold text-slate-600">
                                                        {{ $index + 2 }}
                                                    </span>
                                                </div>
                                                <label class="inline-flex cursor-pointer items-center gap-2 rounded-full bg-rose-50 px-2.5 py-1 text-[11px] font-semibold text-rose-700 ring-1 ring-rose-200">
                                                    <input type="checkbox" name="remove_images[]" value="{{ $imagePath }}" class="{{ $checkboxClass }}">
                                                    <span>Удалить</span>
                                                </label>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        @else
                            <div class="rounded-3xl border border-dashed border-slate-200 bg-slate-50 px-5 py-10 text-center">
                                <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-white text-sky-600 shadow-sm ring-1 ring-sky-100">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5V6.75A2.25 2.25 0 015.25 4.5h13.5A2.25 2.25 0 0121 6.75v9.75M3 16.5l4.172-4.172a2.25 2.25 0 013.182 0L12 14.75l2.646-2.422a2.25 2.25 0 013.182.1L21 16.5M8.25 10.5h.008v.008H8.25V10.5z"/>
                                    </svg>
                                </div>
                                <p class="mt-4 text-sm font-semibold text-slate-800">Фотографии еще не добавлены</p>
                                <p class="mt-1 text-xs leading-5 text-slate-500">
                                    Загрузите изображения товара, чтобы карточка выглядела аккуратнее в каталоге и на витрине.
                                </p>
                            </div>
                        @endif

                        <div class="rounded-2xl border border-sky-200 bg-sky-50/70 px-4 py-3">
                            <p class="text-sm font-semibold text-slate-900">Новые фото к загрузке</p>
                            <p class="mt-1 text-xs leading-5 text-slate-500">
                                Выбранные здесь файлы еще не сохранены. Они появятся в товаре только после нажатия «{{ $submitLabel }}».
                            </p>
                        </div>

                        <label class="group relative flex cursor-pointer flex-col items-center justify-center rounded-3xl border-2 border-dashed border-sky-200 bg-sky-50/60 px-5 py-6 text-center transition hover:border-sky-400 hover:bg-sky-50">
                            <input
                                type="file"
                                name="new_images[]"
                                multiple
                                accept="image/*"
                                style="position:absolute;inset:0;width:100%;height:100%;opacity:0;cursor:pointer;"
                                data-product-image-input
                            >

                            <span class="inline-flex items-center justify-center rounded-2xl border border-sky-300 bg-white px-4 py-2 text-sm font-semibold text-sky-700 shadow-sm transition group-hover:border-sky-400 group-hover:text-sky-800">
                                &#1042;&#1099;&#1073;&#1088;&#1072;&#1090;&#1100; &#1092;&#1086;&#1090;&#1086;
                            </span>
                            <span class="mt-3 text-sm font-semibold text-slate-900" data-product-input-caption>&#1060;&#1072;&#1081;&#1083;&#1099; &#1077;&#1097;&#1077; &#1085;&#1077; &#1074;&#1099;&#1073;&#1088;&#1072;&#1085;&#1099;</span>
                            <span class="mt-1 max-w-sm text-xs leading-5 text-slate-500">
                                &#1052;&#1086;&#1078;&#1085;&#1086; &#1074;&#1099;&#1073;&#1088;&#1072;&#1090;&#1100; &#1085;&#1077;&#1089;&#1082;&#1086;&#1083;&#1100;&#1082;&#1086; &#1092;&#1072;&#1081;&#1083;&#1086;&#1074; &#1089;&#1088;&#1072;&#1079;&#1091;. &#1055;&#1086;&#1076;&#1086;&#1081;&#1076;&#1091;&#1090; JPG, PNG &#1080; WEBP.
                            </span>
                        </label>

                        <div class="hidden rounded-3xl border border-emerald-200 bg-emerald-50/70 p-4" data-product-upload-preview>
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <p class="text-sm font-semibold text-slate-900">Выбрано к загрузке</p>
                                    <p class="mt-1 text-xs leading-5 text-slate-600">
                                        Эти изображения появятся в карточке после нажатия «{{ $submitLabel }}». Ненужные можно убрать до сохранения.
                                    </p>
                                </div>
                                <span class="rounded-full bg-white px-2.5 py-1 text-xs font-semibold text-emerald-700 ring-1 ring-emerald-200" data-product-upload-count>0 фото</span>
                            </div>
                            <div class="mt-4 grid gap-3" style="grid-template-columns: repeat(auto-fit, minmax(10rem, 1fr)); max-width: 22rem;" data-product-upload-grid></div>
                        </div>
                    </div>
                </section>

                <section class="rounded-[1.75rem] border border-slate-200 bg-white p-5 shadow-[0_10px_24px_rgba(15,23,42,0.06)] md:p-6">
                    <h3 class="text-base font-semibold text-slate-900">Публикация</h3>
                    <p class="mt-1 text-sm leading-6 text-slate-500">
                        Управляйте видимостью товара на витрине и попаданием в подборки.
                    </p>

                    <div class="mt-5 space-y-3">
                        <label class="flex items-start gap-3 rounded-3xl border border-slate-200 bg-slate-50/80 px-4 py-4 transition hover:border-sky-200 hover:bg-sky-50/60">
                            <span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-md border border-slate-300 bg-white">
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
                                <span class="mt-1 block text-xs leading-5 text-slate-500">
                                    Если отключить, товар останется в кабинете, но покупатели его не увидят.
                                </span>
                            </span>
                            <span class="inline-flex h-8 items-center rounded-full px-3 text-xs font-semibold {{ $currentIsActive ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600' }}">
                                {{ $currentIsActive ? 'Включено' : 'Выключено' }}
                            </span>
                        </label>

                        <label class="flex items-start gap-3 rounded-3xl border border-slate-200 bg-slate-50/80 px-4 py-4 transition hover:border-amber-200 hover:bg-amber-50/60">
                            <span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-md border border-slate-300 bg-white">
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
                                <span class="mt-1 block text-xs leading-5 text-slate-500">
                                    Товар может попасть в выделенные блоки на главной странице маркетплейса.
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

    <script>
        (() => {
            const input = document.querySelector('[data-product-image-input]');
            const preview = document.querySelector('[data-product-upload-preview]');
            const grid = document.querySelector('[data-product-upload-grid]');
            const count = document.querySelector('[data-product-upload-count]');
            const caption = document.querySelector('[data-product-input-caption]');
            const scrollContainer = document.querySelector('.cabinet-main');

            if (!input || !preview || !grid || !count || !caption) {
                return;
            }

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
                    item.className = 'overflow-hidden rounded-2xl border border-emerald-200 bg-white shadow-sm';
                    item.innerHTML = `
                        <div style="aspect-ratio: 4 / 3; overflow: hidden; background: #f8fafc;">
                            <img src="${url}" alt="" class="h-full w-full object-cover">
                        </div>
                        <div class="space-y-2 px-3 py-3">
                            <div class="flex items-center justify-between gap-2">
                                <span class="min-w-0 truncate text-xs font-semibold text-slate-700">${file.name}</span>
                                <span class="shrink-0 rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-semibold text-emerald-700">${index + 1}</span>
                            </div>
                            <button type="button" class="inline-flex items-center gap-2 rounded-full bg-rose-50 px-3 py-1 text-[11px] font-semibold text-rose-700 ring-1 ring-rose-200" data-remove-upload-index="${index}">
                                Убрать из загрузки
                            </button>
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

            input.addEventListener('change', render);
        })();
    </script>
</x-cabinet-layout>
