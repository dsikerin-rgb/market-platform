<x-cabinet-layout :tenant="$tenant" title="Товары">
    @include('cabinet.partials.sales-nav')

    @php
        $selectedSpaceId = (int) ($selectedSpaceId ?? 0);
        $normalizeCabinetProductTitle = static function (
            ?string $title,
            $tenant,
            string $spaceLabel = '',
            ?string $categoryName = null
        ): string {
            $normalized = trim((string) $title);

            if ($normalized === '') {
                $fallback = trim((string) ($categoryName ?? ''));

                return $fallback !== '' ? $fallback : 'Товар без названия';
            }

            $tenantTokens = array_values(array_filter(array_unique([
                trim((string) ($tenant->display_name ?? '')),
                trim((string) ($tenant->short_name ?? '')),
                trim((string) ($tenant->name ?? '')),
            ]), static fn ($value): bool => $value !== ''));

            foreach ($tenantTokens as $token) {
                $quotedToken = preg_quote($token, '/');
                $normalized = preg_replace('/^\s*' . $quotedToken . '\s*[·\\-—,:|\\/]*\s*/u', '', $normalized) ?? $normalized;
                $normalized = preg_replace('/\s*[·\\-—,:|\\/]*\s*' . $quotedToken . '\s*$/u', '', $normalized) ?? $normalized;
            }

            if ($spaceLabel !== '') {
                $quotedSpace = preg_quote($spaceLabel, '/');
                $normalized = preg_replace('/^\s*' . $quotedSpace . '\s*[·\\-—,:|\\/]*\s*/u', '', $normalized) ?? $normalized;
                $normalized = preg_replace('/\s*[·\\-—,:|\\/]*\s*' . $quotedSpace . '\s*$/u', '', $normalized) ?? $normalized;
            }

            $normalized = trim((string) preg_replace('/\s{2,}/u', ' ', $normalized));
            $normalized = trim($normalized, " \t\n\r\0\x0B·-—,:|/");

            if ($normalized !== '') {
                return $normalized;
            }

            $fallback = trim((string) ($categoryName ?? ''));

            return $fallback !== '' ? $fallback : 'Товар без названия';
        };
    @endphp

    <style>
        .cabinet-products-grid {
            display: grid;
            gap: 0.75rem;
        }

        .cabinet-products-card {
            height: 100%;
        }

        .cabinet-products-card__body {
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
        }

        .cabinet-products-card__media {
            width: 96px;
            height: 96px;
            flex: 0 0 96px;
            overflow: hidden;
            border-radius: 16px;
            border: 1px solid #dbe3ef;
            background: #f8fafc;
        }

        .cabinet-products-card__summary {
            min-width: 0;
            flex: 1 1 auto;
        }

        @media (min-width: 1280px) {
            .cabinet-products-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 639px) {
            .cabinet-products-card__body {
                flex-direction: column;
            }

            .cabinet-products-card__media {
                width: 100%;
                height: 180px;
                flex-basis: auto;
            }

            .cabinet-products-card__head {
                display: grid;
                gap: 0.5rem;
            }

            .cabinet-products-card__meta,
            .cabinet-products-card__actions {
                width: 100%;
            }

            .cabinet-products-card__actions {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }
    </style>

    <section class="rounded-3xl bg-white border border-slate-200 p-4 shadow-sm space-y-3">
        <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
            <div>
                <h2 class="text-base font-semibold text-slate-900">Товары продавца</h2>
                <p class="mt-1 text-xs text-slate-500">
                    Управляйте карточками товаров и привязкой к торговым местам. Витрина и товары настраиваются отдельно.
                </p>
            </div>
            <a
                href="{{ route('cabinet.products.create', array_filter(['space_id' => $selectedSpaceId > 0 ? $selectedSpaceId : null], static fn ($value): bool => $value !== null)) }}"
                class="inline-flex w-full shrink-0 items-center justify-center rounded-2xl border border-sky-600 bg-sky-600 px-4 py-2.5 text-sm font-semibold text-white md:w-auto"
            >
                Добавить товар
            </a>
        </div>

        <form method="GET" action="{{ route('cabinet.products.index') }}" class="grid grid-cols-1 gap-3 xl:grid-cols-[minmax(0,1fr)_14rem_auto]">
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

            <div class="grid grid-cols-2 gap-2 xl:flex xl:items-end">
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

    <section class="cabinet-products-grid">
        @forelse($products as $product)
            @php
                $images = collect($product->images ?? [])->filter(fn ($path) => is_string($path) && $path !== '')->values();
                $firstImage = $images->first();
                $spaceLabel = trim((string) ($product->marketSpace?->display_name ?: ($product->marketSpace?->number ?: $product->marketSpace?->code ?: '')));
                $displayTitle = $normalizeCabinetProductTitle(
                    $product->title,
                    $tenant,
                    $spaceLabel,
                    $product->category?->name
                );
            @endphp
            <article class="cabinet-products-card rounded-3xl bg-white border border-slate-200 p-4 shadow-sm">
                <div class="cabinet-products-card__body">
                    <div class="cabinet-products-card__media">
                        @if($firstImage)
                            <img src="{{ \App\Support\MarketplaceMediaStorage::previewUrl($firstImage) }}" alt="{{ $displayTitle }}" style="display:block;width:100%;height:100%;object-fit:cover;" loading="lazy">
                        @else
                            <div class="grid h-full w-full place-items-center text-[11px] font-semibold text-slate-400">Нет фото</div>
                        @endif
                    </div>

                    <div class="cabinet-products-card__summary">
                        <div class="cabinet-products-card__head flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h3 class="text-sm font-semibold leading-5 text-slate-900" style="display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;">
                                    {{ $displayTitle }}
                                </h3>
                                <div class="cabinet-products-card__meta mt-1 flex flex-wrap items-center gap-2 text-[11px] text-slate-500">
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

                            <div class="cabinet-products-card__actions flex flex-wrap items-center gap-2">
                                <a
                                    href="{{ route('cabinet.products.edit', ['product' => (int) $product->id]) }}"
                                    class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700"
                                >
                                    Редактировать
                                </a>
                                <form method="POST" action="{{ route('cabinet.products.destroy', ['product' => (int) $product->id]) }}" onsubmit="return confirm('Удалить товар?');">
                                    @csrf
                                    <button
                                        type="submit"
                                        class="inline-flex items-center justify-center rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-xs font-semibold text-rose-700"
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
