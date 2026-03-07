<x-cabinet-layout :tenant="$tenant" :title="$isEdit ? 'Редактирование товара' : 'Новый товар'">
    @include('cabinet.partials.sales-nav')

    @php
        $existingImages = collect($product->images ?? [])
            ->filter(fn ($path) => is_string($path) && $path !== '')
            ->values();
    @endphp

    <form method="POST" action="{{ $formAction }}" enctype="multipart/form-data" class="space-y-3">
        @csrf

        <section class="rounded-3xl bg-white border border-slate-200 p-4 shadow-sm space-y-3">
            <div>
                <h2 class="text-base font-semibold text-slate-900">{{ $isEdit ? 'Карточка товара' : 'Добавление товара' }}</h2>
                <p class="mt-1 text-xs text-slate-500">
                    Заполните основные параметры товара. Витрина и товары настраиваются отдельно.
                </p>
            </div>

            <label class="block">
                <span class="text-sm text-slate-600">Название товара</span>
                <input
                    type="text"
                    name="title"
                    value="{{ old('title', $product->title) }}"
                    maxlength="190"
                    required
                    class="mt-1.5 w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm"
                    placeholder="Например: Домашний сыр"
                >
            </label>

            <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                <label class="block">
                    <span class="text-sm text-slate-600">Категория</span>
                    <select
                        name="category_id"
                        class="mt-1.5 w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm"
                    >
                        <option value="">Без категории</option>
                        @foreach($categories as $category)
                            <option value="{{ $category->id }}" @selected((int) old('category_id', (int) ($product->category_id ?? 0)) === (int) $category->id)>
                                {{ $category->name }}
                            </option>
                        @endforeach
                    </select>
                </label>

                <label class="block">
                    <span class="text-sm text-slate-600">Торговое место</span>
                    <select
                        name="market_space_id"
                        class="mt-1.5 w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm"
                    >
                        @if($canManageGlobalProducts)
                            <option value="">Без привязки / вся витрина</option>
                        @else
                            <option value="">Выберите торговое место</option>
                        @endif
                        @foreach($spaces as $space)
                            @php
                                $spaceLabel = trim((string) ($space->code ?: $space->number ?: $space->display_name ?: ('#' . $space->id)));
                                $spaceName = trim((string) ($space->display_name ?? ''));
                            @endphp
                            <option value="{{ $space->id }}" @selected((int) old('market_space_id', (int) ($product->market_space_id ?? 0)) === (int) $space->id)>
                                {{ $spaceLabel }}{{ $spaceName !== '' ? ' · ' . $spaceName : '' }}
                            </option>
                        @endforeach
                    </select>
                </label>
            </div>

            <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                <label class="block">
                    <span class="text-sm text-slate-600">Цена</span>
                    <input
                        type="number"
                        name="price"
                        value="{{ old('price', $product->price !== null ? number_format((float) $product->price, 2, '.', '') : '') }}"
                        min="0"
                        step="0.01"
                        class="mt-1.5 w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm"
                        placeholder="0.00"
                        inputmode="decimal"
                    >
                </label>

                <label class="block">
                    <span class="text-sm text-slate-600">Остаток</span>
                    <input
                        type="number"
                        name="stock_qty"
                        value="{{ old('stock_qty', (int) ($product->stock_qty ?? 0)) }}"
                        min="0"
                        step="1"
                        class="mt-1.5 w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm"
                        inputmode="numeric"
                    >
                </label>
            </div>

            <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                <label class="block">
                    <span class="text-sm text-slate-600">Артикул</span>
                    <input
                        type="text"
                        name="sku"
                        value="{{ old('sku', $product->sku) }}"
                        maxlength="120"
                        class="mt-1.5 w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm"
                        placeholder="Например: CHEESE-001"
                    >
                </label>

                <label class="block">
                    <span class="text-sm text-slate-600">Единица</span>
                    <input
                        type="text"
                        name="unit"
                        value="{{ old('unit', $product->unit) }}"
                        maxlength="40"
                        class="mt-1.5 w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm"
                        placeholder="шт, кг, упаковка"
                    >
                </label>
            </div>

            <label class="block">
                <span class="text-sm text-slate-600">Описание</span>
                <textarea
                    name="description"
                    rows="5"
                    class="mt-1.5 w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm"
                    placeholder="Кратко опишите товар, преимущества, условия выдачи"
                >{{ old('description', $product->description) }}</textarea>
            </label>
        </section>

        <section class="rounded-3xl bg-white border border-slate-200 p-4 shadow-sm space-y-3">
            <div>
                <h3 class="text-sm font-semibold text-slate-900">Фото товара</h3>
                <p class="mt-1 text-xs text-slate-500">До 8 изображений. Можно добавить новые и удалить старые.</p>
            </div>

            @if($existingImages->isNotEmpty())
                <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
                    @foreach($existingImages as $imagePath)
                        <label class="overflow-hidden rounded-2xl border border-slate-200 bg-slate-50">
                            <img
                                src="{{ \Illuminate\Support\Facades\Storage::url($imagePath) }}"
                                alt="Фото товара"
                                class="h-28 w-full object-cover"
                                loading="lazy"
                            >
                            <span class="flex items-center gap-2 px-3 py-2 text-xs text-slate-600">
                                <input type="checkbox" name="remove_images[]" value="{{ $imagePath }}" class="h-4 w-4 rounded border-slate-300 text-sky-600 focus:ring-sky-400">
                                Удалить фото
                            </span>
                        </label>
                    @endforeach
                </div>
            @endif

            <label class="block">
                <span class="text-sm text-slate-600">Добавить новые фото</span>
                <input
                    type="file"
                    name="new_images[]"
                    multiple
                    accept="image/*"
                    class="mt-1.5 w-full text-sm"
                >
            </label>
        </section>

        <section class="rounded-3xl bg-white border border-slate-200 p-4 shadow-sm space-y-3">
            <h3 class="text-sm font-semibold text-slate-900">Публикация</h3>

            <label class="flex items-start gap-3 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                <input
                    type="checkbox"
                    name="is_active"
                    value="1"
                    @checked((bool) old('is_active', (bool) $product->is_active))
                    class="mt-1 h-4 w-4 rounded border-slate-300 text-sky-600 focus:ring-sky-400"
                >
                <span>
                    <span class="block text-sm font-semibold text-slate-800">Показывать в маркетплейсе</span>
                    <span class="mt-1 block text-xs text-slate-500">Если отключить, товар останется в кабинете, но не будет виден покупателям.</span>
                </span>
            </label>

            <label class="flex items-start gap-3 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                <input
                    type="checkbox"
                    name="is_featured"
                    value="1"
                    @checked((bool) old('is_featured', (bool) $product->is_featured))
                    class="mt-1 h-4 w-4 rounded border-slate-300 text-sky-600 focus:ring-sky-400"
                >
                <span>
                    <span class="block text-sm font-semibold text-slate-800">Показывать в подборках</span>
                    <span class="mt-1 block text-xs text-slate-500">Товар может попасть в выделенные блоки на главной странице маркетплейса.</span>
                </span>
            </label>
        </section>

        <div class="flex flex-wrap items-center gap-2">
            <button class="inline-flex items-center rounded-2xl border border-sky-600 bg-sky-600 px-4 py-3 text-sm font-semibold text-white" type="submit">
                {{ $submitLabel }}
            </button>
            <a
                href="{{ route('cabinet.products.index', array_filter(['space_id' => (int) ($product->market_space_id ?? 0) > 0 ? (int) $product->market_space_id : null], static fn ($value): bool => $value !== null)) }}"
                class="inline-flex items-center rounded-2xl border border-slate-300 bg-white px-4 py-3 text-sm font-semibold text-slate-700"
            >
                Отмена
            </a>
        </div>
    </form>
</x-cabinet-layout>
