<x-cabinet-layout :tenant="$tenant" title="Товары">
    @include('cabinet.partials.sales-nav')

    @php
        $selectedSpaceId = (int) ($selectedSpaceId ?? 0);
    @endphp

    <section class="rounded-3xl bg-white border border-slate-200 p-4 shadow-sm space-y-3">
        <div class="flex items-start justify-between gap-3">
            <div>
                <h2 class="text-base font-semibold text-slate-900">Товары продавца</h2>
                <p class="mt-1 text-xs text-slate-500">
                    Управляйте карточками товаров и привязкой к торговым местам. Витрина и товары настраиваются отдельно.
                </p>
            </div>
            <a
                href="{{ route('cabinet.products.create', array_filter(['space_id' => $selectedSpaceId > 0 ? $selectedSpaceId : null], static fn ($value): bool => $value !== null)) }}"
                class="inline-flex shrink-0 items-center rounded-2xl border border-sky-600 bg-sky-600 px-4 py-2.5 text-sm font-semibold text-white"
            >
                Добавить товар
            </a>
        </div>

        <form method="GET" action="{{ route('cabinet.products.index') }}" class="grid grid-cols-1 gap-3 md:grid-cols-[minmax(0,1fr)_14rem_auto]">
            <label class="block">
                <span class="text-sm text-slate-600">Поиск</span>
                <input
                    type="text"
                    name="q"
                    value="{{ $search }}"
                    placeholder="Название, описание или артикул"
                    class="mt-1.5 w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm"
                >
            </label>

            <label class="block">
                <span class="text-sm text-slate-600">Торговое место</span>
                <select
                    name="space_id"
                    class="mt-1.5 w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm"
                >
                    <option value="0">Все доступные места</option>
                    @if($canManageGlobalProducts)
                        <option value="-1" @selected($selectedSpaceId === -1)>Без привязки</option>
                    @endif
                    @foreach($spaces as $space)
                        @php
                            $spaceLabel = trim((string) ($space->code ?: $space->number ?: $space->display_name ?: ('#' . $space->id)));
                            $spaceName = trim((string) ($space->display_name ?? ''));
                        @endphp
                        <option value="{{ $space->id }}" @selected($selectedSpaceId === (int) $space->id)>
                            {{ $spaceLabel }}{{ $spaceName !== '' ? ' · ' . $spaceName : '' }}
                        </option>
                    @endforeach
                </select>
            </label>

            <div class="flex items-end gap-2">
                <button
                    type="submit"
                    class="inline-flex h-[3.25rem] items-center justify-center rounded-2xl border border-slate-300 bg-white px-4 text-sm font-semibold text-slate-700"
                >
                    Показать
                </button>
                <a
                    href="{{ route('cabinet.products.index') }}"
                    class="inline-flex h-[3.25rem] items-center justify-center rounded-2xl border border-slate-200 bg-slate-50 px-4 text-sm font-semibold text-slate-600"
                >
                    Сбросить
                </a>
            </div>
        </form>
    </section>

    <section class="space-y-3">
        @forelse($products as $product)
            @php
                $images = collect($product->images ?? [])->filter(fn ($path) => is_string($path) && $path !== '')->values();
                $firstImage = $images->first();
                $spaceLabel = trim((string) ($product->marketSpace?->display_name ?: ($product->marketSpace?->number ?: $product->marketSpace?->code ?: '')));
            @endphp
            <article class="rounded-3xl bg-white border border-slate-200 p-4 shadow-sm">
                <div class="flex gap-3">
                    <div class="h-24 w-24 shrink-0 overflow-hidden rounded-2xl border border-slate-200 bg-slate-50">
                        @if($firstImage)
                            <img src="{{ \Illuminate\Support\Facades\Storage::url($firstImage) }}" alt="{{ $product->title }}" class="h-full w-full object-cover" loading="lazy">
                        @else
                            <div class="grid h-full w-full place-items-center text-[11px] font-semibold text-slate-400">Нет фото</div>
                        @endif
                    </div>

                    <div class="min-w-0 flex-1">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h3 class="truncate text-sm font-semibold text-slate-900">{{ $product->title }}</h3>
                                <div class="mt-1 flex flex-wrap items-center gap-2 text-[11px] text-slate-500">
                                    @if($product->category?->name)
                                        <span class="inline-flex rounded-full border border-slate-200 bg-slate-50 px-2 py-0.5">{{ $product->category->name }}</span>
                                    @endif
                                    @if($spaceLabel !== '')
                                        <span class="inline-flex rounded-full border border-sky-200 bg-sky-50 px-2 py-0.5 text-sky-700">{{ $spaceLabel }}</span>
                                    @else
                                        <span class="inline-flex rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 text-amber-700">Общая витрина</span>
                                    @endif
                                </div>
                            </div>
                            <div class="text-right">
                                <div class="text-sm font-semibold text-slate-900">
                                    {{ $product->price !== null ? number_format((float) $product->price, 0, '.', ' ') . ' ₽' : 'Цена не указана' }}
                                </div>
                                <div class="mt-1 text-[11px] text-slate-500">Остаток: {{ (int) ($product->stock_qty ?? 0) }}</div>
                            </div>
                        </div>

                        @if(filled($product->description))
                            <p class="mt-2 text-xs text-slate-600" style="display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;">
                                {{ $product->description }}
                            </p>
                        @endif

                        <div class="mt-3 flex flex-wrap items-center justify-between gap-2">
                            <div class="flex flex-wrap items-center gap-2 text-[11px]">
                                @if($product->is_active)
                                    <span class="inline-flex rounded-full border border-emerald-300 bg-emerald-50 px-2 py-0.5 font-semibold text-emerald-700">Опубликован</span>
                                @else
                                    <span class="inline-flex rounded-full border border-slate-300 bg-slate-100 px-2 py-0.5 font-semibold text-slate-600">Скрыт</span>
                                @endif
                                @if($product->is_featured)
                                    <span class="inline-flex rounded-full border border-sky-300 bg-sky-50 px-2 py-0.5 font-semibold text-sky-700">В подборке</span>
                                @endif
                                @if(filled($product->sku))
                                    <span class="text-slate-500">SKU: {{ $product->sku }}</span>
                                @endif
                            </div>

                            <div class="flex flex-wrap items-center gap-2">
                                <a
                                    href="{{ route('cabinet.products.edit', ['product' => (int) $product->id]) }}"
                                    class="inline-flex items-center rounded-xl border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700"
                                >
                                    Редактировать
                                </a>
                                <form method="POST" action="{{ route('cabinet.products.destroy', ['product' => (int) $product->id]) }}" onsubmit="return confirm('Удалить товар?');">
                                    @csrf
                                    <button
                                        type="submit"
                                        class="inline-flex items-center rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-xs font-semibold text-rose-700"
                                    >
                                        Удалить
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </article>
        @empty
            <div class="rounded-3xl border border-dashed border-slate-300 bg-white px-5 py-8 text-center">
                <h3 class="text-base font-semibold text-slate-900">Товаров пока нет</h3>
                <p class="mt-2 text-sm text-slate-500">
                    Добавьте первые карточки товаров, чтобы наполнять витрину и каталог маркетплейса.
                </p>
                <a
                    href="{{ route('cabinet.products.create', array_filter(['space_id' => $selectedSpaceId > 0 ? $selectedSpaceId : null], static fn ($value): bool => $value !== null)) }}"
                    class="mt-4 inline-flex items-center rounded-2xl border border-sky-600 bg-sky-600 px-4 py-2.5 text-sm font-semibold text-white"
                >
                    Добавить товар
                </a>
            </div>
        @endforelse
    </section>

    {{ $products->links() }}
</x-cabinet-layout>
