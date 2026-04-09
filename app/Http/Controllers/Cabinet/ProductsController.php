<?php
# app/Http/Controllers/Cabinet/ProductsController.php

declare(strict_types=1);

namespace App\Http\Controllers\Cabinet;

use App\Http\Controllers\Controller;
use App\Models\MarketSpace;
use App\Models\MarketplaceCategory;
use App\Models\MarketplaceProduct;
use App\Models\Tenant;
use App\Models\User;
use App\Support\MarketplaceMediaStorage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ProductsController extends Controller
{
    private const MAX_IMAGES = 8;

    public function index(Request $request): View
    {
        $authUser = $request->user();
        $tenant = $authUser->tenant;

        [$spaces, $canManageGlobalProducts] = $this->resolveAccessibleSpaces($authUser, $tenant);
        $selectedSpaceId = $this->resolveSelectedSpaceId($request, $spaces, $canManageGlobalProducts);
        $search = trim((string) $request->query('q', ''));

        $products = $this->baseProductsQuery($tenant, $spaces, $canManageGlobalProducts)
            ->when($selectedSpaceId > 0, fn ($query) => $query->where('market_space_id', $selectedSpaceId))
            ->when($selectedSpaceId === -1, fn ($query) => $query->whereNull('market_space_id'))
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($inner) use ($search): void {
                    $inner
                        ->where('title', 'like', '%' . $search . '%')
                        ->orWhere('description', 'like', '%' . $search . '%')
                        ->orWhere('sku', 'like', '%' . $search . '%');
                });
            })
            ->with(['marketSpace:id,display_name,number,code', 'category:id,name'])
            ->orderByDesc('is_active')
            ->orderByDesc('updated_at')
            ->paginate(18)
            ->withQueryString();

        return view('cabinet.products.index', [
            'tenant' => $tenant,
            'spaces' => $spaces,
            'selectedSpaceId' => $selectedSpaceId,
            'search' => $search,
            'products' => $products,
            'canManageGlobalProducts' => $canManageGlobalProducts,
        ]);
    }

    public function create(Request $request): View
    {
        $authUser = $request->user();
        $tenant = $authUser->tenant;

        [$spaces, $canManageGlobalProducts] = $this->resolveAccessibleSpaces($authUser, $tenant);
        $categories = $this->resolveCategories($tenant);
        $selectedSpaceId = $this->resolveCreateSpaceId($request, $spaces, $canManageGlobalProducts);

        $product = new MarketplaceProduct([
            'currency' => 'RUB',
            'stock_qty' => 0,
            'is_active' => true,
            'is_featured' => false,
            'market_space_id' => $selectedSpaceId > 0 ? $selectedSpaceId : null,
        ]);

        return view('cabinet.products.form', [
            'tenant' => $tenant,
            'product' => $product,
            'spaces' => $spaces,
            'categories' => $categories,
            'canManageGlobalProducts' => $canManageGlobalProducts,
            'formAction' => route('cabinet.products.store'),
            'formMethod' => 'POST',
            'submitLabel' => 'Сохранить товар',
            'isEdit' => false,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $authUser = $request->user();
        $tenant = $authUser->tenant;

        [$spaces, $canManageGlobalProducts] = $this->resolveAccessibleSpaces($authUser, $tenant);
        $validated = $this->validateProductPayload($request, $tenant, $spaces, $canManageGlobalProducts);

        $product = new MarketplaceProduct();
        $product->fill($this->buildProductAttributes($validated, $tenant));
        $product->slug = $this->makeUniqueSlug((int) $tenant->market_id, (string) $product->title);
        $product->published_at = $product->is_active ? now() : null;
        $product->images = $this->storeUploadedImages($request);
        $product->save();

        return redirect()
            ->route('cabinet.products.index', array_filter([
                'space_id' => $product->market_space_id ? (int) $product->market_space_id : null,
            ], static fn ($value): bool => $value !== null))
            ->with('success', 'Товар добавлен.');
    }

    public function edit(Request $request, int $product): View
    {
        $authUser = $request->user();
        $tenant = $authUser->tenant;

        [$spaces, $canManageGlobalProducts] = $this->resolveAccessibleSpaces($authUser, $tenant);
        $productModel = $this->resolveProductOrFail($tenant, $spaces, $canManageGlobalProducts, $product);
        $categories = $this->resolveCategories($tenant);

        return view('cabinet.products.form', [
            'tenant' => $tenant,
            'product' => $productModel,
            'spaces' => $spaces,
            'categories' => $categories,
            'canManageGlobalProducts' => $canManageGlobalProducts,
            'formAction' => route('cabinet.products.update', ['product' => (int) $productModel->id]),
            'formMethod' => 'POST',
            'submitLabel' => 'Сохранить изменения',
            'isEdit' => true,
        ]);
    }

    public function update(Request $request, int $product): RedirectResponse
    {
        $authUser = $request->user();
        $tenant = $authUser->tenant;

        [$spaces, $canManageGlobalProducts] = $this->resolveAccessibleSpaces($authUser, $tenant);
        $productModel = $this->resolveProductOrFail($tenant, $spaces, $canManageGlobalProducts, $product);
        $validated = $this->validateProductPayload($request, $tenant, $spaces, $canManageGlobalProducts);
        $existingImages = collect($productModel->images ?? [])
            ->filter(static fn ($path): bool => is_string($path) && $path !== '')
            ->values();

        $removeImages = $this->resolveRemovableImages($request, $existingImages);
        $remainingImagesCount = $existingImages->count() - $removeImages->count();
        $newImagesCount = $this->countUploadedImages($request);

        if (($remainingImagesCount + $newImagesCount) > self::MAX_IMAGES) {
            throw ValidationException::withMessages([
                'new_images' => 'Можно сохранить не более ' . self::MAX_IMAGES . ' изображений у одного товара.',
            ]);
        }

        $images = $existingImages
            ->reject(static fn (string $path): bool => $removeImages->contains($path))
            ->values();

        foreach ($removeImages as $path) {
            MarketplaceMediaStorage::delete($path);
        }

        $newImages = $this->storeUploadedImages($request);
        $images = $images->concat($newImages)->values();

        $wasInactive = ! (bool) $productModel->is_active;
        $wasDemo = (bool) $productModel->is_demo;

        $productModel->fill($this->buildProductAttributes($validated, $tenant));
        $productModel->is_demo = $wasDemo;

        if (! filled($productModel->slug)) {
            $productModel->slug = $this->makeUniqueSlug(
                (int) $tenant->market_id,
                (string) $productModel->title,
                (int) $productModel->id
            );
        }

        if ($wasInactive && (bool) $productModel->is_active && ! $productModel->published_at) {
            $productModel->published_at = now();
        }

        $productModel->images = $images->all();
        $productModel->save();

        return redirect()
            ->route('cabinet.products.index', array_filter([
                'space_id' => $productModel->market_space_id ? (int) $productModel->market_space_id : null,
            ], static fn ($value): bool => $value !== null))
            ->with('success', 'Товар обновлён.');
    }

    public function destroy(Request $request, int $product): RedirectResponse
    {
        $authUser = $request->user();
        $tenant = $authUser->tenant;

        [$spaces, $canManageGlobalProducts] = $this->resolveAccessibleSpaces($authUser, $tenant);
        $productModel = $this->resolveProductOrFail($tenant, $spaces, $canManageGlobalProducts, $product);

        foreach ((array) ($productModel->images ?? []) as $path) {
            if (is_string($path) && $path !== '') {
                MarketplaceMediaStorage::delete($path);
            }
        }

        $productModel->delete();

        return redirect()
            ->route('cabinet.products.index')
            ->with('success', 'Товар удалён.');
    }

    public function destroyImage(Request $request, int $product): JsonResponse
    {
        $authUser = $request->user();
        $tenant = $authUser->tenant;

        [$spaces, $canManageGlobalProducts] = $this->resolveAccessibleSpaces($authUser, $tenant);
        $productModel = $this->resolveProductOrFail($tenant, $spaces, $canManageGlobalProducts, $product);

        $validated = $request->validate([
            'path' => ['required', 'string'],
        ]);

        $path = trim((string) $validated['path']);
        $images = collect($productModel->images ?? [])
            ->filter(static fn ($imagePath): bool => is_string($imagePath) && $imagePath !== '')
            ->values();

        abort_unless($images->contains($path), 422, 'Передано неизвестное изображение.');

        MarketplaceMediaStorage::delete($path);

        $productModel->images = $images
            ->reject(static fn (string $imagePath): bool => $imagePath === $path)
            ->values()
            ->all();
        $productModel->save();

        return response()->json([
            'ok' => true,
            'images_count' => count((array) $productModel->images),
        ]);
    }

    private function resolveAccessibleSpaces(User $user, Tenant $tenant): array
    {
        $allSpaces = MarketSpace::query()
            ->where('tenant_id', (int) $tenant->id)
            ->when((int) ($tenant->market_id ?? 0) > 0, fn ($query) => $query->where('market_id', (int) $tenant->market_id))
            ->orderByRaw('COALESCE(code, number, display_name) asc')
            ->get(['id', 'code', 'number', 'display_name']);

        $allowedSpaceIds = $user->allowedTenantSpaceIds();
        $spaces = $allSpaces
            ->when($allowedSpaceIds !== [], fn (Collection $collection) => $collection->whereIn('id', $allowedSpaceIds))
            ->values();

        $canManageGlobalProducts = $user->hasRole('merchant')
            || $spaces->count() === $allSpaces->count();

        return [$spaces, $canManageGlobalProducts];
    }

    private function resolveSelectedSpaceId(Request $request, Collection $spaces, bool $canManageGlobalProducts): int
    {
        $selectedSpaceId = (int) $request->integer('space_id', 0);

        if ($selectedSpaceId === -1 && $canManageGlobalProducts) {
            return -1;
        }

        return $spaces->contains('id', $selectedSpaceId) ? $selectedSpaceId : 0;
    }

    private function resolveCreateSpaceId(Request $request, Collection $spaces, bool $canManageGlobalProducts): int
    {
        $selectedSpaceId = (int) $request->integer('space_id', 0);

        if ($selectedSpaceId === -1 && ! $canManageGlobalProducts) {
            return 0;
        }

        return $spaces->contains('id', $selectedSpaceId) ? $selectedSpaceId : 0;
    }

    private function resolveCategories(Tenant $tenant): Collection
    {
        return MarketplaceCategory::query()
            ->where('is_active', true)
            ->where(function ($query) use ($tenant): void {
                $query
                    ->whereNull('market_id')
                    ->orWhere('market_id', (int) ($tenant->market_id ?? 0));
            })
            ->orderByRaw('CASE WHEN market_id = ? THEN 0 ELSE 1 END', [(int) ($tenant->market_id ?? 0)])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'market_id']);
    }

    private function baseProductsQuery(Tenant $tenant, Collection $spaces, bool $canManageGlobalProducts): Builder
    {
        $query = MarketplaceProduct::query()
            ->where('tenant_id', (int) $tenant->id)
            ->where('market_id', (int) $tenant->market_id);

        if ($canManageGlobalProducts) {
            return $query;
        }

        $spaceIds = $spaces
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->values()
            ->all();

        return $query->whereIn('market_space_id', $spaceIds);
    }

    private function resolveProductOrFail(Tenant $tenant, Collection $spaces, bool $canManageGlobalProducts, int $productId): MarketplaceProduct
    {
        $product = $this->baseProductsQuery($tenant, $spaces, $canManageGlobalProducts)
            ->whereKey($productId)
            ->firstOrFail();

        if (! $canManageGlobalProducts && ! $product->market_space_id) {
            abort(403);
        }

        return $product;
    }

    private function validateProductPayload(Request $request, Tenant $tenant, Collection $spaces, bool $canManageGlobalProducts): array
    {
        $spaceIds = $spaces
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->values()
            ->all();

        $categoryIds = $this->resolveCategories($tenant)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->values()
            ->all();

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:190'],
            'description' => ['nullable', 'string'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'stock_qty' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'sku' => ['nullable', 'string', 'max:120'],
            'unit' => ['nullable', 'string', 'max:40'],
            'category_id' => ['nullable', 'integer'],
            'market_space_id' => ['nullable', 'integer'],
            'is_active' => ['nullable', 'boolean'],
            'is_featured' => ['nullable', 'boolean'],
            'new_images' => ['nullable', 'array', 'max:' . self::MAX_IMAGES],
            'new_images.*' => ['image', 'max:4096'],
            'remove_images' => ['nullable', 'array'],
            'remove_images.*' => ['string'],
        ]);

        $categoryId = (int) ($validated['category_id'] ?? 0);
        if ($categoryId > 0 && ! in_array($categoryId, $categoryIds, true)) {
            throw ValidationException::withMessages([
                'category_id' => 'Выбрана недопустимая категория.',
            ]);
        }

        $marketSpaceId = (int) ($validated['market_space_id'] ?? 0);
        if ($marketSpaceId > 0 && ! in_array($marketSpaceId, $spaceIds, true)) {
            throw ValidationException::withMessages([
                'market_space_id' => 'Выбрано недопустимое торговое место.',
            ]);
        }

        if ($marketSpaceId <= 0 && ! $canManageGlobalProducts) {
            throw ValidationException::withMessages([
                'market_space_id' => 'Сотруднику нужно выбрать доступное торговое место.',
            ]);
        }

        return $validated;
    }

    private function buildProductAttributes(array $validated, Tenant $tenant): array
    {
        $title = trim((string) ($validated['title'] ?? ''));
        $description = trim((string) ($validated['description'] ?? ''));
        $sku = trim((string) ($validated['sku'] ?? ''));
        $unit = trim((string) ($validated['unit'] ?? ''));
        $marketSpaceId = (int) ($validated['market_space_id'] ?? 0);
        $categoryId = (int) ($validated['category_id'] ?? 0);

        return [
            'market_id' => (int) ($tenant->market_id ?? 0),
            'tenant_id' => (int) $tenant->id,
            'market_space_id' => $marketSpaceId > 0 ? $marketSpaceId : null,
            'category_id' => $categoryId > 0 ? $categoryId : null,
            'title' => $title,
            'description' => $description !== '' ? $description : null,
            'price' => array_key_exists('price', $validated) && $validated['price'] !== null && $validated['price'] !== ''
                ? (float) $validated['price']
                : null,
            'currency' => 'RUB',
            'stock_qty' => max(0, (int) ($validated['stock_qty'] ?? 0)),
            'sku' => $sku !== '' ? $sku : null,
            'unit' => $unit !== '' ? $unit : null,
            'is_active' => (bool) ($validated['is_active'] ?? false),
            'is_featured' => (bool) ($validated['is_featured'] ?? false),
            'is_demo' => false,
        ];
    }

    /**
     * @return list<string>
     */
    private function storeUploadedImages(Request $request): array
    {
        $paths = [];

        foreach ($request->file('new_images', []) as $file) {
            if (! $file) {
                continue;
            }

            $paths[] = MarketplaceMediaStorage::store($file, 'marketplace-products');
        }

        return $paths;
    }

    private function resolveRemovableImages(Request $request, Collection $existingImages): Collection
    {
        $requestedRemoveImages = collect($request->input('remove_images', []))
            ->map(static fn ($path): string => is_scalar($path) ? trim((string) $path) : '')
            ->filter(static fn (string $path): bool => $path !== '')
            ->unique()
            ->values();

        return $requestedRemoveImages
            ->intersect($existingImages)
            ->values();
    }

    private function countUploadedImages(Request $request): int
    {
        return collect($request->file('new_images', []))
            ->filter()
            ->count();
    }

    private function makeUniqueSlug(int $marketId, string $title, ?int $ignoreProductId = null): string
    {
        $slugBase = Str::slug($title) ?: 'product';
        $slug = $slugBase;
        $counter = 1;

        while (MarketplaceProduct::query()
            ->where('market_id', $marketId)
            ->where('slug', $slug)
            ->when($ignoreProductId !== null, fn ($query) => $query->whereKeyNot($ignoreProductId))
            ->exists()
        ) {
            $counter += 1;
            $slug = $slugBase . '-' . $counter;
        }

        return $slug;
    }
}
