<?php

# routes/web.php

declare(strict_types=1);

use App\Http\Controllers\Auth\MarketRegistrationController;
use App\Models\Market;
use App\Models\MarketSpace;
use App\Models\MarketSpaceMapShape;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate as FilamentAuthenticate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

Route::view('/', 'welcome')->name('home');

Route::middleware(['web', 'panel:admin', FilamentAuthenticate::class])->group(function () {
    /**
     * Переключатель рынка для super-admin (используется в topbar-user-info.blade.php).
     * Сохраняет выбранный market_id в сессии.
     */
    Route::post('/admin/switch-market', function (Request $request) {
        $user = Filament::auth()->user();

        abort_unless($user && method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin(), 403);

        $validated = $request->validate([
            'market_id' => ['nullable', 'integer', 'exists:markets,id'],
        ]);

        $marketId = $validated['market_id'] ?? null;

        if (blank($marketId)) {
            $request->session()->forget('filament.admin.selected_market_id');
        } else {
            $request->session()->put('filament.admin.selected_market_id', (int) $marketId);
        }

        return back();
    })->name('filament.admin.switch-market');

    /**
     * Единая логика выбора рынка + проверка доступа (просмотр карты).
     */
    $resolveMarketForMap = function (): Market {
        $user = Filament::auth()->user();
        abort_unless($user, 403);

        $isSuperAdmin = method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin();

        $panelId = Filament::getCurrentPanel()?->getId() ?? 'admin';
        $selectedMarketId = session("filament.{$panelId}.selected_market_id")
            ?? session('filament.admin.selected_market_id');

        if ($isSuperAdmin) {
            $market = filled($selectedMarketId)
                ? Market::query()->whereKey((int) $selectedMarketId)->first()
                : Market::query()->orderBy('id')->first();

            // если выбранный market_id удалён/не найден — fallback на первый
            if (! $market) {
                $market = Market::query()->orderBy('id')->first();
            }
        } else {
            $marketId = (int) ($user->market_id ?? 0);
            $market = $marketId > 0 ? Market::query()->whereKey($marketId)->first() : null;

            $hasRoleAccess = method_exists($user, 'hasAnyRole')
                && $user->hasAnyRole(['market-admin', 'market-maintenance']);

            $hasPermissionAccess = method_exists($user, 'can') && (
                $user->can('markets.view')
                || $user->can('markets.update')
                || $user->can('markets.viewAny')
            );

            abort_unless($market && ($hasRoleAccess || $hasPermissionAccess), 403);
        }

        abort_unless($market, 404);

        return $market;
    };

    /**
     * Редактирование разметки: market-admin + super-admin (+ markets.update как запасной вариант).
     */
    $ensureCanEditShapes = static function (): void {
        $user = Filament::auth()->user();
        abort_unless($user, 403);

        $isSuperAdmin = method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin();
        $isMarketAdmin = method_exists($user, 'hasAnyRole') && $user->hasAnyRole(['market-admin']);
        $canByPermission = method_exists($user, 'can') && $user->can('markets.update');

        abort_unless($isSuperAdmin || $isMarketAdmin || $canByPermission, 403);
    };

    /**
     * Та же логика, но без abort — для UI (показать/скрыть кнопку "Разметка").
     */
    $canEditShapes = static function (): bool {
        $user = Filament::auth()->user();
        if (! $user) {
            return false;
        }

        $isSuperAdmin = method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin();
        $isMarketAdmin = method_exists($user, 'hasAnyRole') && $user->hasAnyRole(['market-admin']);
        $canByPermission = method_exists($user, 'can') && $user->can('markets.update');

        return $isSuperAdmin || $isMarketAdmin || $canByPermission;
    };

    /**
     * Нормализуем polygon к формату [{x,y}, ...] и считаем bbox.
     *
     * @return array{0: array<int, array{x: float, y: float}>, 1: array{bbox_x1: float, bbox_y1: float, bbox_x2: float, bbox_y2: float}}
     */
    $normalizePolygonAndBbox = static function (array $rawPolygon): array {
        $poly = [];

        foreach ($rawPolygon as $p) {
            if (! is_array($p)) {
                continue;
            }

            $x = $p['x'] ?? $p[0] ?? null;
            $y = $p['y'] ?? $p[1] ?? null;

            if (! is_numeric($x) || ! is_numeric($y)) {
                continue;
            }

            $poly[] = [
                'x' => (float) $x,
                'y' => (float) $y,
            ];
        }

        if (count($poly) < 3) {
            throw ValidationException::withMessages([
                'polygon' => 'polygon должен содержать минимум 3 корректные точки',
            ]);
        }

        $xs = array_map(static fn ($p) => $p['x'], $poly);
        $ys = array_map(static fn ($p) => $p['y'], $poly);

        $bbox = [
            'bbox_x1' => round(min($xs), 2),
            'bbox_y1' => round(min($ys), 2),
            'bbox_x2' => round(max($xs), 2),
            'bbox_y2' => round(max($ys), 2),
        ];

        return [$poly, $bbox];
    };

    /**
     * CREATE shape.
     *
     * Важно: эти endpoints НЕ должны создавать/обновлять MarketSpace.
     * Они работают только с MarketSpaceMapShape и привязкой market_space_id к существующему месту.
     */
    Route::post('/admin/market-map/shapes', function (Request $request) use (
        $resolveMarketForMap,
        $ensureCanEditShapes,
        $normalizePolygonAndBbox
    ) {
        $ensureCanEditShapes();
        $market = $resolveMarketForMap();

        if (! Schema::hasTable('market_space_map_shapes')) {
            return response()->json([
                'ok' => false,
                'message' => 'Таблица market_space_map_shapes ещё не создана (выполни миграции).',
            ], 422);
        }

        $validated = $request->validate([
            'market_space_id' => ['nullable', 'integer', 'exists:market_spaces,id'],
            'page' => ['nullable', 'integer', 'min:1'],
            'version' => ['nullable', 'integer', 'min:1'],

            'polygon' => ['required', 'array', 'min:3'],

            'stroke_color' => ['nullable', 'string', 'max:32'],
            'fill_color' => ['nullable', 'string', 'max:32'],
            'fill_opacity' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'stroke_width' => ['nullable', 'numeric', 'min:0', 'max:50'],

            'meta' => ['nullable', 'array'],
            'sort_order' => ['nullable', 'integer'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $page = (int) ($validated['page'] ?? 1);
        $version = (int) ($validated['version'] ?? 1);

        $marketSpaceId = $validated['market_space_id'] ?? null;

        if ($marketSpaceId !== null) {
            $belongs = MarketSpace::query()
                ->where('market_id', (int) $market->id)
                ->whereKey((int) $marketSpaceId)
                ->exists();

            if (! $belongs) {
                throw ValidationException::withMessages([
                    'market_space_id' => 'market_space_id не принадлежит текущему рынку',
                ]);
            }
        }

        [$polygon, $bbox] = $normalizePolygonAndBbox($validated['polygon']);

        // ВАЖНО: в БД есть UNIQUE(market_id, version, page, market_space_id).
        // Если shape ранее "удалили" через is_active=0, запись всё равно остаётся и мешает создать новую.
        // Поэтому при market_space_id делаем upsert: если запись с таким ключом уже есть — обновляем её.
        if ($marketSpaceId !== null) {
            $existing = MarketSpaceMapShape::query()
                ->where('market_id', (int) $market->id)
                ->where('page', $page)
                ->where('version', $version)
                ->where('market_space_id', (int) $marketSpaceId)
                ->first();

            if ($existing) {
                $existing->polygon = $polygon;
                $existing->bbox_x1 = $bbox['bbox_x1'];
                $existing->bbox_y1 = $bbox['bbox_y1'];
                $existing->bbox_x2 = $bbox['bbox_x2'];
                $existing->bbox_y2 = $bbox['bbox_y2'];

                $existing->stroke_color = $validated['stroke_color'] ?? ($existing->stroke_color ?: '#00A3FF');
                $existing->fill_color = $validated['fill_color'] ?? ($existing->fill_color ?: '#00A3FF');
                $existing->fill_opacity = array_key_exists('fill_opacity', $validated)
                    ? (float) $validated['fill_opacity']
                    : (float) ($existing->fill_opacity ?? 0.12);
                $existing->stroke_width = array_key_exists('stroke_width', $validated)
                    ? (float) $validated['stroke_width']
                    : (float) ($existing->stroke_width ?? 1.5);

                $existing->meta = $validated['meta'] ?? $existing->meta;
                $existing->sort_order = array_key_exists('sort_order', $validated)
                    ? (int) $validated['sort_order']
                    : (int) ($existing->sort_order ?? 0);

                $existing->is_active = array_key_exists('is_active', $validated)
                    ? (bool) $validated['is_active']
                    : true;

                try {
                    $existing->save();
                } catch (\Throwable $e) {
                    return response()->json([
                        'ok' => false,
                        'message' => 'Не удалось обновить существующий shape (upsert): ' . $e->getMessage(),
                    ], 500);
                }

                return response()->json([
                    'ok' => true,
                    'mode' => 'updated',
                    'item' => $existing->fresh()->toArray(),
                ]);
            }
        }

        try {
            $shape = MarketSpaceMapShape::query()->create([
                'market_id' => (int) $market->id,
                'market_space_id' => $marketSpaceId !== null ? (int) $marketSpaceId : null,
                'page' => $page,
                'version' => $version,

                'polygon' => $polygon,
                ...$bbox,

                'stroke_color' => $validated['stroke_color'] ?? '#00A3FF',
                'fill_color' => $validated['fill_color'] ?? '#00A3FF',
                'fill_opacity' => array_key_exists('fill_opacity', $validated) ? (float) $validated['fill_opacity'] : 0.12,
                'stroke_width' => array_key_exists('stroke_width', $validated) ? (float) $validated['stroke_width'] : 1.5,

                'meta' => $validated['meta'] ?? null,
                'sort_order' => array_key_exists('sort_order', $validated) ? (int) $validated['sort_order'] : 0,
                'is_active' => array_key_exists('is_active', $validated) ? (bool) $validated['is_active'] : true,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => 'Не удалось создать shape: ' . $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'ok' => true,
            'mode' => 'created',
            'item' => $shape->fresh()->toArray(),
        ]);
    })->name('filament.admin.market-map.shapes.store');

    /**
     * UPDATE shape.
     */
    Route::patch('/admin/market-map/shapes/{shape}', function (Request $request, $shape) use (
        $resolveMarketForMap,
        $ensureCanEditShapes,
        $normalizePolygonAndBbox
    ) {
        $ensureCanEditShapes();
        $market = $resolveMarketForMap();

        if (! Schema::hasTable('market_space_map_shapes')) {
            return response()->json([
                'ok' => false,
                'message' => 'Таблица market_space_map_shapes ещё не создана (выполни миграции).',
            ], 422);
        }

        $shapeId = (int) $shape;
        abort_unless($shapeId > 0, 404);

        $shapeModel = MarketSpaceMapShape::query()
            ->where('market_id', (int) $market->id)
            ->whereKey($shapeId)
            ->firstOrFail();

        $validated = $request->validate([
            'market_space_id' => ['nullable', 'integer', 'exists:market_spaces,id'],
            'page' => ['nullable', 'integer', 'min:1'],
            'version' => ['nullable', 'integer', 'min:1'],

            'polygon' => ['nullable', 'array', 'min:3'],

            'stroke_color' => ['nullable', 'string', 'max:32'],
            'fill_color' => ['nullable', 'string', 'max:32'],
            'fill_opacity' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'stroke_width' => ['nullable', 'numeric', 'min:0', 'max:50'],

            'meta' => ['nullable', 'array'],
            'sort_order' => ['nullable', 'integer'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        // Считаем будущие значения ключа уникальности.
        $nextPage = array_key_exists('page', $validated)
            ? (int) ($validated['page'] ?? 1)
            : (int) ($shapeModel->page ?? 1);

        $nextVersion = array_key_exists('version', $validated)
            ? (int) ($validated['version'] ?? 1)
            : (int) ($shapeModel->version ?? 1);

        $nextMarketSpaceId = array_key_exists('market_space_id', $validated)
            ? $validated['market_space_id']
            : $shapeModel->market_space_id;

        // Проверяем, что market_space_id принадлежит рынку.
        if (array_key_exists('market_space_id', $validated)) {
            $marketSpaceId = $validated['market_space_id'];

            if ($marketSpaceId !== null) {
                $belongs = MarketSpace::query()
                    ->where('market_id', (int) $market->id)
                    ->whereKey((int) $marketSpaceId)
                    ->exists();

                if (! $belongs) {
                    throw ValidationException::withMessages([
                        'market_space_id' => 'market_space_id не принадлежит текущему рынку',
                    ]);
                }
            }
        }

        // ВАЖНО: защитимся от UNIQUE(market_id, version, page, market_space_id).
        // Если пытаемся привязать к месту, которое уже занято (часто это "удалённая" запись), освобождаем конфликт.
        if ($nextMarketSpaceId !== null) {
            $conflict = MarketSpaceMapShape::query()
                ->where('market_id', (int) $market->id)
                ->where('page', $nextPage)
                ->where('version', $nextVersion)
                ->where('market_space_id', (int) $nextMarketSpaceId)
                ->where('id', '!=', (int) $shapeModel->id)
                ->first();

            if ($conflict) {
                try {
                    $conflict->market_space_id = null;
                    $conflict->is_active = false;
                    $conflict->save();
                } catch (\Throwable $e) {
                    return response()->json([
                        'ok' => false,
                        'message' => 'Не удалось освободить конфликтующую привязку: ' . $e->getMessage(),
                    ], 500);
                }
            }
        }

        if (array_key_exists('market_space_id', $validated)) {
            $shapeModel->market_space_id = $validated['market_space_id'] !== null
                ? (int) $validated['market_space_id']
                : null;
        }

        if (array_key_exists('page', $validated)) {
            $shapeModel->page = $nextPage;
        }

        if (array_key_exists('version', $validated)) {
            $shapeModel->version = $nextVersion;
        }

        if (array_key_exists('polygon', $validated) && $validated['polygon'] !== null) {
            [$polygon, $bbox] = $normalizePolygonAndBbox($validated['polygon']);
            $shapeModel->polygon = $polygon;
            $shapeModel->bbox_x1 = $bbox['bbox_x1'];
            $shapeModel->bbox_y1 = $bbox['bbox_y1'];
            $shapeModel->bbox_x2 = $bbox['bbox_x2'];
            $shapeModel->bbox_y2 = $bbox['bbox_y2'];
        }

        foreach (['stroke_color', 'fill_color', 'meta'] as $k) {
            if (array_key_exists($k, $validated)) {
                $shapeModel->{$k} = $validated[$k];
            }
        }

        if (array_key_exists('fill_opacity', $validated)) {
            $shapeModel->fill_opacity = $validated['fill_opacity'] !== null ? (float) $validated['fill_opacity'] : null;
        }

        if (array_key_exists('stroke_width', $validated)) {
            $shapeModel->stroke_width = $validated['stroke_width'] !== null ? (float) $validated['stroke_width'] : null;
        }

        if (array_key_exists('sort_order', $validated)) {
            $shapeModel->sort_order = (int) ($validated['sort_order'] ?? 0);
        }

        if (array_key_exists('is_active', $validated)) {
            $shapeModel->is_active = (bool) $validated['is_active'];
        }

        try {
            $shapeModel->save();
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => 'Не удалось обновить shape: ' . $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'ok' => true,
            'item' => $shapeModel->fresh()->toArray(),
        ]);
    })->name('filament.admin.market-map.shapes.update');

    /**
     * DELETE shape (soft через is_active=0).
     */
    Route::delete('/admin/market-map/shapes/{shape}', function ($shape) use (
        $resolveMarketForMap,
        $ensureCanEditShapes
    ) {
        $ensureCanEditShapes();
        $market = $resolveMarketForMap();

        if (! Schema::hasTable('market_space_map_shapes')) {
            return response()->json([
                'ok' => false,
                'message' => 'Таблица market_space_map_shapes ещё не создана (выполни миграции).',
            ], 422);
        }

        $shapeId = (int) $shape;
        abort_unless($shapeId > 0, 404);

        $shapeModel = MarketSpaceMapShape::query()
            ->where('market_id', (int) $market->id)
            ->whereKey($shapeId)
            ->firstOrFail();

        try {
            // ВАЖНО: освобождаем уникальный ключ (market_id, version, page, market_space_id)
            // чтобы можно было снова привязать это же место к новой фигуре.
            $shapeModel->market_space_id = null;
            $shapeModel->is_active = false;
            $shapeModel->save();
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => 'Не удалось удалить shape: ' . $e->getMessage(),
            ], 500);
        }

        return response()->json(['ok' => true]);
    })->name('filament.admin.market-map.shapes.destroy');

    /**
     * Слой разметки: список полигонов (PDF-координаты) для отрисовки поверх canvas.
     */
    Route::get('/admin/market-map/shapes', function (Request $request) use ($resolveMarketForMap) {
        $market = $resolveMarketForMap();

        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'version' => ['nullable', 'integer', 'min:1'],
        ]);

        $page = (int) ($validated['page'] ?? 1);
        $version = (int) ($validated['version'] ?? 1);

        if (! Schema::hasTable('market_space_map_shapes')) {
            return response()->json([
                'ok' => true,
                'items' => [],
                'message' => 'Таблица market_space_map_shapes ещё не создана (выполни миграции).',
                'meta' => compact('page', 'version'),
            ]);
        }

        try {
            $rows = MarketSpaceMapShape::query()
                ->with(['marketSpace.tenant'])
                ->where('market_id', (int) $market->id)
                ->where('page', $page)
                ->where('version', $version)
                ->where('is_active', true)
                ->orderByDesc('sort_order')
                ->orderByDesc('id')
                ->limit(5000)
                ->get([
                    'id',
                    'market_space_id',
                    'page',
                    'version',
                    'polygon',
                    'stroke_color',
                    'fill_color',
                    'fill_opacity',
                    'stroke_width',
                    'sort_order',
                    'is_active',
                    'meta',
                ]);
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'items' => [],
                'message' => 'Ошибка чтения слоёв карты: ' . $e->getMessage(),
                'meta' => compact('page', 'version'),
            ], 500);
        }

        $items = $rows->map(static function (MarketSpaceMapShape $s): array {
            $space = $s->marketSpace;
            $tenant = $space?->tenant;

            return [
                'id' => (int) $s->id,
                'market_space_id' => $s->market_space_id ? (int) $s->market_space_id : null,
                'page' => (int) ($s->page ?? 1),
                'version' => (int) ($s->version ?? 1),
                'polygon' => is_array($s->polygon) ? $s->polygon : [],
                'bbox_x1' => $s->bbox_x1 !== null ? (float) $s->bbox_x1 : null,
                'bbox_y1' => $s->bbox_y1 !== null ? (float) $s->bbox_y1 : null,
                'bbox_x2' => $s->bbox_x2 !== null ? (float) $s->bbox_x2 : null,
                'bbox_y2' => $s->bbox_y2 !== null ? (float) $s->bbox_y2 : null,

                'stroke_color' => (string) ($s->stroke_color ?: '#00A3FF'),
                'fill_color' => (string) ($s->fill_color ?: '#00A3FF'),
                'fill_opacity' => $s->fill_opacity !== null ? (float) $s->fill_opacity : 0.12,
                'stroke_width' => $s->stroke_width !== null ? (float) $s->stroke_width : 1.5,

                'sort_order' => (int) ($s->sort_order ?? 0),
                'is_active' => (bool) ($s->is_active ?? true),
                'meta' => is_array($s->meta) ? $s->meta : [],
                'debt_status' => $tenant?->debt_status,
            ];
        })->values();

        return response()->json([
            'ok' => true,
            'items' => $items,
            'meta' => [
                'market_id' => (int) $market->id,
                'page' => $page,
                'version' => $version,
            ],
        ]);
    })->name('filament.admin.market-map.shapes');

    /**
     * Быстрая проверка места для поля "Место ID" (поддерживает ?id= и ?number=/ ?code=).
     * - ?id=123
     * - ?number=П3/2  (по точному совпадению number либо code)
     */
    Route::get('/admin/market-map/space', function (Request $request) use ($resolveMarketForMap) {
        $market = $resolveMarketForMap();

        $validated = $request->validate([
            'id' => ['nullable', 'integer', 'min:1', 'required_without:number'],
            'number' => ['nullable', 'string', 'max:120', 'required_without:id'],
        ]);

        $space = null;

        if (! empty($validated['id'])) {
            $id = (int) $validated['id'];

            $space = MarketSpace::query()
                ->with(['tenant'])
                ->where('market_id', (int) $market->id)
                ->whereKey($id)
                ->first();
        } else {
            $number = trim((string) ($validated['number'] ?? ''));
            $number = str_replace(["\n", "\r", "\t"], ' ', $number);
            $number = trim($number);

            $space = MarketSpace::query()
                ->with(['tenant'])
                ->where('market_id', (int) $market->id)
                ->where(function ($q) use ($number) {
                    $q->where('number', '=', $number)
                        ->orWhere('code', '=', $number);
                })
                ->orderBy('id')
                ->first();
        }

        if (! $space) {
            return response()->json([
                'ok' => true,
                'found' => false,
                'item' => null,
            ]);
        }

        $tenant = $space->tenant;
        $tenantName = null;

        if ($tenant) {
            $tenantName = (string) ($tenant->display_name ?? '');
            if ($tenantName === '') {
                $tenantName = (string) ($tenant->name ?? '');
            }
        }

        return response()->json([
            'ok' => true,
            'found' => true,
            'item' => [
                'id' => (int) $space->id,
                'number' => (string) ($space->number ?? ''),
                'code' => (string) ($space->code ?? ''),
                'area_sqm' => (string) ($space->area_sqm ?? ''),
                'status' => (string) ($space->status ?? ''),
                'tenant' => $tenant ? [
                    'id' => (int) ($tenant->id ?? 0),
                    'name' => (string) ($tenantName ?? ''),
                ] : null,
            ],
        ]);
    })->name('filament.admin.market-map.space');

    /**
     * Поиск мест для автокомплита (номер/код/арендатор/ID).
     * Поддерживает ?number= и ?q=.
     */
    Route::get('/admin/market-map/spaces', function (Request $request) use ($resolveMarketForMap) {
        $market = $resolveMarketForMap();

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:64'],
            'number' => ['nullable', 'string', 'max:64'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $raw = trim((string) ($validated['q'] ?? $validated['number'] ?? ''));
        $raw = str_replace(["\n", "\r", "\t"], ' ', $raw);
        $q = trim(str_replace(['№', '#'], '', $raw));

        $limit = (int) ($validated['limit'] ?? 15);

        if ($q === '') {
            return response()->json(['ok' => true, 'items' => []]);
        }

        $isNumeric = ctype_digit($q);
        $qEsc = str_replace(['%', '_'], ['\%', '\\_'], $q);
        $qLike = '%' . $qEsc . '%';

        $rows = MarketSpace::query()
            ->with(['tenant'])
            ->where('market_id', (int) $market->id)
            ->where(function ($qq) use ($isNumeric, $q, $qLike) {
                if ($isNumeric) {
                    $qq->orWhere('id', '=', (int) $q);
                }

                $qq->orWhere('number', 'like', $qLike)
                    ->orWhere('code', 'like', $qLike)
                    ->orWhereHas('tenant', function ($tq) use ($qLike) {
                        $tq->where('name', 'like', $qLike)
                            ->orWhere('display_name', 'like', $qLike);
                    });
            })
            ->orderByRaw('CASE WHEN number = ? THEN 0 ELSE 1 END', [$q])
            ->orderBy('number')
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'number', 'code', 'area_sqm', 'status', 'tenant_id']);

        $items = $rows->map(static function (MarketSpace $space): array {
            $tenant = $space->tenant;

            $tenantName = null;
            if ($tenant) {
                $tenantName = (string) ($tenant->display_name ?? '');
                if ($tenantName === '') {
                    $tenantName = (string) ($tenant->name ?? '');
                }
            }

            return [
                'id' => (int) $space->id,
                'number' => (string) ($space->number ?? ''),
                'code' => (string) ($space->code ?? ''),
                'area_sqm' => (string) ($space->area_sqm ?? ''),
                'status' => (string) ($space->status ?? ''),
                'tenant' => $tenant ? [
                    'id' => (int) ($tenant->id ?? 0),
                    'name' => (string) ($tenantName ?? ''),
                ] : null,
            ];
        })->values();

        return response()->json(['ok' => true, 'items' => $items]);
    })->name('filament.admin.market-map.spaces');

    /**
     * HIT-test: клик по карте -> поиск места по bbox + polygon.
     */
    Route::get('/admin/market-map/hit', function (Request $request) use ($resolveMarketForMap) {
        $market = $resolveMarketForMap();

        $validated = $request->validate([
            'x' => ['required', 'numeric'],
            'y' => ['required', 'numeric'],
            'page' => ['nullable', 'integer', 'min:1'],
            'version' => ['nullable', 'integer', 'min:1'],
        ]);

        $x = (float) $validated['x'];
        $y = (float) $validated['y'];
        $page = (int) ($validated['page'] ?? 1);
        $version = (int) ($validated['version'] ?? 1);

        if (! Schema::hasTable('market_space_map_shapes')) {
            return response()->json([
                'ok' => true,
                'hit' => null,
                'message' => 'Таблица market_space_map_shapes ещё не создана (выполни миграции).',
                'meta' => compact('x', 'y', 'page', 'version'),
            ]);
        }

        foreach (['bbox_x1', 'bbox_y1', 'bbox_x2', 'bbox_y2'] as $bboxCol) {
            if (! Schema::hasColumn('market_space_map_shapes', $bboxCol)) {
                return response()->json([
                    'ok' => false,
                    'hit' => null,
                    'message' => 'В таблице market_space_map_shapes нет колонки ' . $bboxCol . ' (нужны миграции/обновление структуры).',
                    'meta' => compact('x', 'y', 'page', 'version'),
                ], 500);
            }
        }

        // point-in-polygon (ray casting)
        $pointInPolygon = static function (float $px, float $py, array $polygon): bool {
            $n = count($polygon);
            if ($n < 3) {
                return false;
            }

            $inside = false;

            for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
                $pi = $polygon[$i] ?? null;
                $pj = $polygon[$j] ?? null;

                if (! is_array($pi) || ! is_array($pj)) {
                    continue;
                }

                $xi = isset($pi['x']) ? (float) $pi['x'] : (isset($pi[0]) ? (float) $pi[0] : null);
                $yi = isset($pi['y']) ? (float) $pi['y'] : (isset($pi[1]) ? (float) $pi[1] : null);

                $xj = isset($pj['x']) ? (float) $pj['x'] : (isset($pj[0]) ? (float) $pj[0] : null);
                $yj = isset($pj['y']) ? (float) $pj['y'] : (isset($pj[1]) ? (float) $pj[1] : null);

                if ($xi === null || $yi === null || $xj === null || $yj === null) {
                    continue;
                }

                $den = ($yj - $yi) ?: 1e-12;

                $intersect = (($yi > $py) !== ($yj > $py))
                    && ($px < ($xj - $xi) * ($py - $yi) / $den + $xi);

                if ($intersect) {
                    $inside = ! $inside;
                }
            }

            return $inside;
        };

        try {
            $candidates = MarketSpaceMapShape::query()
                ->where('market_id', (int) $market->id)
                ->where('page', $page)
                ->where('version', $version)
                ->where('is_active', true)
                ->where('bbox_x1', '<=', $x)
                ->where('bbox_x2', '>=', $x)
                ->where('bbox_y1', '<=', $y)
                ->where('bbox_y2', '>=', $y)
                ->orderByDesc('sort_order')
                ->orderByDesc('id')
                ->limit(30)
                ->get(['id', 'market_space_id', 'polygon']);
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'hit' => null,
                'message' => 'Ошибка чтения слоёв карты: ' . $e->getMessage(),
                'meta' => compact('x', 'y', 'page', 'version'),
            ], 500);
        }

        $hitShape = null;

        foreach ($candidates as $shape) {
            $polygon = is_array($shape->polygon) ? $shape->polygon : [];
            if (count($polygon) < 3) {
                continue;
            }

            if ($pointInPolygon($x, $y, $polygon)) {
                $hitShape = $shape;
                break;
            }
        }

        if (! $hitShape) {
            return response()->json([
                'ok' => true,
                'hit' => null,
                'message' => 'Ничего не найдено по клику.',
                'meta' => compact('x', 'y', 'page', 'version'),
            ]);
        }

        $space = null;

        if (! empty($hitShape->market_space_id)) {
            $space = MarketSpace::query()
                ->with(['tenant'])
                ->where('market_id', (int) $market->id)
                ->whereKey((int) $hitShape->market_space_id)
                ->first();
        }

        $tenant = $space?->tenant;

        $tenantName = null;
        if ($tenant) {
            $tenantName = (string) ($tenant->display_name ?? '');
            if ($tenantName === '') {
                $tenantName = (string) ($tenant->name ?? '');
            }
        }

        return response()->json([
            'ok' => true,
            'hit' => [
                'shape_id' => (int) $hitShape->id,
                'market_space_id' => $space?->id ? (int) $space->id : null,

                'space' => $space ? [
                    'id' => (int) $space->id,
                    'number' => (string) ($space->number ?? ''),
                    'code' => (string) ($space->code ?? ''),
                    'area_sqm' => (string) ($space->area_sqm ?? ''),
                    'status' => (string) ($space->status ?? ''),
                ] : null,

                'tenant' => $tenant ? [
                    'id' => (int) ($tenant->id ?? 0),
                    'name' => (string) ($tenantName ?? ''),
                    'debt_status' => $tenant->debt_status,
                    'debt_status_label' => $tenant->debt_status_label,
                ] : null,

                'debt' => null,
                'debt_overdue_days' => null,
                'color' => null,
            ],
            'meta' => compact('x', 'y', 'page', 'version'),
        ]);
    })->name('filament.admin.market-map.hit');

    /**
     * Viewer карты рынка (рендер через Blade).
     */
    Route::get('/admin/market-map', function (Request $request) use ($resolveMarketForMap, $canEditShapes) {
        $market = $resolveMarketForMap();

        $mapPath = data_get($market->settings ?? [], 'map_pdf_path');

        $hasMap = is_string($mapPath)
            && $mapPath !== ''
            && str_starts_with($mapPath, 'market-maps/')
            && Storage::disk('local')->exists($mapPath);

        $validated = $request->validate([
            'market_space_id' => ['nullable', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1'],
            'version' => ['nullable', 'integer', 'min:1'],
            'bbox_x1' => ['nullable', 'numeric'],
            'bbox_y1' => ['nullable', 'numeric'],
            'bbox_x2' => ['nullable', 'numeric'],
            'bbox_y2' => ['nullable', 'numeric'],
        ]);

        $marketSpaceId = isset($validated['market_space_id']) ? (int) $validated['market_space_id'] : null;
        $page = (int) ($validated['page'] ?? 1);
        $version = (int) ($validated['version'] ?? 1);

        $pageRequested = $request->has('page');
        $versionRequested = $request->has('version');
        $bboxRequested = $request->has('bbox_x1')
            && $request->has('bbox_y1')
            && $request->has('bbox_x2')
            && $request->has('bbox_y2');

        $bboxFromRequest = null;

        if ($bboxRequested) {
            $bboxFromRequest = [
                'x1' => (float) ($validated['bbox_x1'] ?? 0),
                'y1' => (float) ($validated['bbox_y1'] ?? 0),
                'x2' => (float) ($validated['bbox_x2'] ?? 0),
                'y2' => (float) ($validated['bbox_y2'] ?? 0),
            ];
        }

        $focusShape = null;
        $marketSpaceNotLinked = false;

        if ($marketSpaceId && Schema::hasTable('market_space_map_shapes')) {
            $shapeQuery = MarketSpaceMapShape::query()
                ->where('market_id', (int) $market->id)
                ->where('market_space_id', $marketSpaceId)
                ->where('is_active', true);

            if ($pageRequested) {
                $shapeQuery->where('page', $page);
            }

            if ($versionRequested) {
                $shapeQuery->where('version', $version);
            }

            $shape = $shapeQuery
                ->orderByDesc('id')
                ->first([
                    'id',
                    'market_space_id',
                    'page',
                    'version',
                    'bbox_x1',
                    'bbox_y1',
                    'bbox_x2',
                    'bbox_y2',
                ]);

            if (! $shape && (! $pageRequested || ! $versionRequested)) {
                $shape = MarketSpaceMapShape::query()
                    ->where('market_id', (int) $market->id)
                    ->where('market_space_id', $marketSpaceId)
                    ->where('is_active', true)
                    ->orderByDesc('id')
                    ->first([
                        'id',
                        'market_space_id',
                        'page',
                        'version',
                        'bbox_x1',
                        'bbox_y1',
                        'bbox_x2',
                        'bbox_y2',
                    ]);
            }

            if ($shape) {
                if (! $pageRequested) {
                    $page = (int) ($shape->page ?? 1);
                }

                if (! $versionRequested) {
                    $version = (int) ($shape->version ?? 1);
                }

                $focusShape = [
                    'id' => (int) $shape->id,
                    'market_space_id' => $shape->market_space_id ? (int) $shape->market_space_id : null,
                    'page' => (int) ($shape->page ?? 1),
                    'version' => (int) ($shape->version ?? 1),
                    'bbox' => [
                        'x1' => $bboxFromRequest['x1'] ?? ($shape->bbox_x1 !== null ? (float) $shape->bbox_x1 : null),
                        'y1' => $bboxFromRequest['y1'] ?? ($shape->bbox_y1 !== null ? (float) $shape->bbox_y1 : null),
                        'x2' => $bboxFromRequest['x2'] ?? ($shape->bbox_x2 !== null ? (float) $shape->bbox_x2 : null),
                        'y2' => $bboxFromRequest['y2'] ?? ($shape->bbox_y2 !== null ? (float) $shape->bbox_y2 : null),
                    ],
                ];
            } else {
                $marketSpaceNotLinked = true;
            }
        } elseif ($marketSpaceId) {
            $marketSpaceNotLinked = true;
        }

        $payload = [
            'market' => $market,
            'marketId' => (int) ($market->id ?? 0),
            'marketName' => (string) ($market->name ?? 'Рынок'),
            'hasMap' => $hasMap,
            'canEdit' => (bool) $canEditShapes(),
            'mapPage' => $page,
            'mapVersion' => $version,
            'marketSpaceId' => $marketSpaceId,
            'focusShape' => $focusShape,
            'marketSpaceNotLinked' => $marketSpaceNotLinked,

            'settingsUrl' => url('/admin/market-settings'),

            'pdfUrl' => route('filament.admin.market-map.pdf'),
            'hitUrl' => route('filament.admin.market-map.hit'),
            'shapesUrl' => route('filament.admin.market-map.shapes'),
            'spaceUrl' => route('filament.admin.market-map.space'),
            'spacesUrl' => route('filament.admin.market-map.spaces'),
        ];

        if (! $hasMap) {
            return response()
                ->view('admin.market-map-empty', $payload)
                ->header('Content-Type', 'text/html; charset=UTF-8');
        }

        if ($marketSpaceNotLinked) {
            return response()
                ->view('admin.market-map-unbound', $payload)
                ->header('Content-Type', 'text/html; charset=UTF-8');
        }

        return response()
            ->view('admin.market-map', $payload)
            ->header('Content-Type', 'text/html; charset=UTF-8');
    })->name('filament.admin.market-map');

    /**
     * Выдача приватного PDF карты для PDF.js (только авторизованным).
     */
    Route::get('/admin/market-map/pdf', function () use ($resolveMarketForMap) {
        $market = $resolveMarketForMap();

        $mapPath = data_get($market->settings ?? [], 'map_pdf_path');

        abort_unless(is_string($mapPath) && $mapPath !== '', 404);
        abort_unless(str_starts_with($mapPath, 'market-maps/'), 404);
        abort_unless(Storage::disk('local')->exists($mapPath), 404);

        $absolute = Storage::disk('local')->path($mapPath);

        return response()->file($absolute, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="market-map.pdf"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    })->name('filament.admin.market-map.pdf');
});

Route::middleware('guest')->group(function () {
    Route::get('/register/market', [MarketRegistrationController::class, 'create'])
        ->name('market.register');

    Route::post('/register/market', [MarketRegistrationController::class, 'store'])
        ->name('market.register.store');
});
