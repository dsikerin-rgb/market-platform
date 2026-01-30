<?php

# routes/web.php

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
        $selectedMarketId = session("filament.{$panelId}.selected_market_id") ?? session('filament.admin.selected_market_id');

        if ($isSuperAdmin) {
            $market = filled($selectedMarketId)
                ? Market::query()->whereKey((int) $selectedMarketId)->first()
                : Market::query()->orderBy('id')->first();
        } else {
            $marketId = (int) ($user->market_id ?? 0);
            $market = $marketId > 0 ? Market::query()->whereKey($marketId)->first() : null;

            $hasRoleAccess = method_exists($user, 'hasAnyRole')
                && $user->hasAnyRole(['market-admin', 'market-maintenance']);

            $hasPermissionAccess =
                $user->can('markets.view') ||
                $user->can('markets.update') ||
                $user->can('markets.viewAny');

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
            'item' => $shape->fresh()->toArray(),
        ]);
    })->name('filament.admin.market-map.shapes.store');

    /**
     * UPDATE shape.
     * Важно: не используем implicit model binding, чтобы не зависеть от таблицы до проверки Schema::hasTable().
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

            $shapeModel->market_space_id = $marketSpaceId !== null ? (int) $marketSpaceId : null;
        }

        if (array_key_exists('page', $validated)) {
            $shapeModel->page = (int) ($validated['page'] ?? 1);
        }
        if (array_key_exists('version', $validated)) {
            $shapeModel->version = (int) ($validated['version'] ?? 1);
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
            return [
                'id' => (int) $s->id,
                'market_space_id' => $s->market_space_id ? (int) $s->market_space_id : null,
                'page' => (int) ($s->page ?? 1),
                'version' => (int) ($s->version ?? 1),
                'polygon' => is_array($s->polygon) ? $s->polygon : [],

                'stroke_color' => (string) ($s->stroke_color ?: '#00A3FF'),
                'fill_color' => (string) ($s->fill_color ?: '#00A3FF'),
                'fill_opacity' => $s->fill_opacity !== null ? (float) $s->fill_opacity : 0.12,
                'stroke_width' => $s->stroke_width !== null ? (float) $s->stroke_width : 1.5,

                'sort_order' => (int) ($s->sort_order ?? 0),
                'is_active' => (bool) ($s->is_active ?? true),
                'meta' => is_array($s->meta) ? $s->meta : [],
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
     * Быстрая проверка/подсказка по MarketSpace ID (для поля "Место ID" в режиме разметки).
     */
    Route::get('/admin/market-map/space', function (Request $request) use ($resolveMarketForMap) {
        $market = $resolveMarketForMap();

        $validated = $request->validate([
            'id' => ['required', 'integer', 'min:1'],
        ]);

        $id = (int) $validated['id'];

        $space = MarketSpace::query()
            ->with(['tenant'])
            ->where('market_id', (int) $market->id)
            ->whereKey($id)
            ->first();

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
            if ($polygon === [] || count($polygon) < 3) {
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
                ] : null,

                'debt' => null,
                'debt_overdue_days' => null,
                'color' => null,
            ],
            'meta' => compact('x', 'y', 'page', 'version'),
        ]);
    })->name('filament.admin.market-map.hit');

    /**
     * Viewer карты рынка — pan + zoom + hit-test + svg слой + режим разметки (Shift+Drag).
     * Улучшение: поле "Место ID" реагирует на Enter, валидирует ID и запоминает в localStorage.
     */
    Route::get('/admin/market-map', function () use ($resolveMarketForMap, $canEditShapes) {
        $market = $resolveMarketForMap();

        $mapPath = data_get($market->settings ?? [], 'map_pdf_path');
        $hasMap = is_string($mapPath) && $mapPath !== '';

        $pdfUrl = route('filament.admin.market-map.pdf');
        $hitUrl = route('filament.admin.market-map.hit');
        $shapesUrl = route('filament.admin.market-map.shapes');
        $spaceUrl = route('filament.admin.market-map.space');

        $canEdit = $canEditShapes();

        $editControls = $canEdit ? <<<HTML
<button id="toggleEdit" type="button">Разметка: выкл</button>
<label class="pill" style="display:inline-flex; gap:8px; align-items:center;">
  Место ID:
  <input id="marketSpaceId" type="number" min="1" step="1" inputmode="numeric"
         placeholder="ID"
         style="width:92px; padding:6px 8px; border-radius:10px; border:1px solid rgba(120,120,120,.25); background:rgba(120,120,120,.06); color:inherit;">
</label>
<span class="pill" id="spaceIdState" style="display:none;">ID: —</span>
<span class="pill" id="editHint" style="display:none;">Shift+drag: создать прямоугольник • Клик по месту: карточка • В карточке: “Взять ID/Удалить”</span>
HTML : '';

        $repl = [
            '__MARKET_NAME__' => e((string) ($market->name ?? 'Рынок')),
            '__PDF_URL_ATTR__' => e($pdfUrl),
            '__SETTINGS_URL_ATTR__' => e(url('/admin/market-settings')),
            '__CSRF_TOKEN_ATTR__' => e(csrf_token()),

            '__PDF_URL_JSON__' => json_encode($pdfUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            '__HIT_URL_JSON__' => json_encode($hitUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            '__SHAPES_URL_JSON__' => json_encode($shapesUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            '__SPACE_URL_JSON__' => json_encode($spaceUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            '__CAN_EDIT_JSON__' => $canEdit ? 'true' : 'false',
            '__MARKET_ID_JSON__' => json_encode((int) $market->id),
            '__EDIT_CONTROLS__' => $editControls,
        ];

        if (! $hasMap) {
            $html = <<<'HTML'
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="__CSRF_TOKEN_ATTR__">
  <title>Карта рынка — __MARKET_NAME__</title>
  <style>
    :root { color-scheme: light dark; }
    body { margin: 0; font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Arial; }
    .wrap { padding: 16px; max-width: 1400px; margin: 0 auto; }
    .top { display:flex; gap:12px; align-items:flex-start; justify-content:space-between; flex-wrap:wrap; }
    .title { font-size: 18px; font-weight: 700; }
    .pill { font-size: 12px; opacity: .8; padding: 6px 10px; border-radius: 999px; border: 1px solid rgba(120,120,120,.25); }
    .btnrow { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
    .empty {
      padding: 14px;
      border-radius: 14px;
      border: 1px solid rgba(120,120,120,.25);
      background: rgba(120,120,120,.06);
      margin-top: 14px;
      font-size: 14px;
    }
    button {
      border: 1px solid rgba(120,120,120,.35);
      background: rgba(120,120,120,.10);
      padding: 8px 10px;
      border-radius: 10px;
      cursor: pointer;
      font-size: 13px;
    }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="top">
      <div>
        <div class="title">Карта рынка</div>
      </div>
      <div class="btnrow">
        <button id="closeBtn" type="button" class="pill" style="background:transparent;">✕ Закрыть</button>
        <a id="toSettingsLink" href="__SETTINGS_URL_ATTR__" class="pill" style="text-decoration:none; display:none;">К настройкам</a>
      </div>
    </div>

    <script>
      (function () {
        const btn = document.getElementById('closeBtn');
        const toSettings = document.getElementById('toSettingsLink');

        btn.addEventListener('click', function () {
          try { window.close(); } catch (e) { /* ignore */ }
          setTimeout(function () {
            if (toSettings) toSettings.style.display = 'inline-flex';
          }, 150);
        });
      })();
    </script>

    <div class="empty">
      <strong>Карта не загружена.</strong>
      <div style="margin-top:6px; opacity:.8;">
        Загрузите PDF-карту в настройках рынка (поле “Карта (PDF)”), нажмите “Сохранить”, затем откройте просмотр снова.
      </div>
    </div>

  </div>
</body>
</html>
HTML;

            return response(strtr($html, $repl))->header('Content-Type', 'text/html; charset=UTF-8');
        }

        $html = <<<'HTML'
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="__CSRF_TOKEN_ATTR__">
  <title>Карта рынка — __MARKET_NAME__</title>
  <style>
    :root { color-scheme: light dark; }
    body { margin: 0; font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Arial; }
    .wrap { padding: 16px; max-width: 1400px; margin: 0 auto; }
    .top { display:flex; gap:12px; align-items:flex-start; justify-content:space-between; flex-wrap:wrap; }
    .title { font-size: 18px; font-weight: 700; }
    .btnrow { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
    button {
      border: 1px solid rgba(120,120,120,.35);
      background: rgba(120,120,120,.10);
      padding: 8px 10px;
      border-radius: 10px;
      cursor: pointer;
      font-size: 13px;
    }
    button:hover { background: rgba(120,120,120,.18); }
    button:disabled { opacity:.5; cursor:not-allowed; }
    .pill { font-size: 12px; opacity: .8; padding: 6px 10px; border-radius: 999px; border: 1px solid rgba(120,120,120,.25); }

    .viewer {
      margin-top: 14px;
      border: 1px solid rgba(120,120,120,.25);
      border-radius: 14px;
      overflow: hidden;
      background: rgba(120,120,120,.06);
    }
    .toolbar {
      padding: 10px 12px;
      display:flex;
      gap:10px;
      align-items:flex-start;
      justify-content:space-between;
      border-bottom: 1px solid rgba(120,120,120,.18);
      background: rgba(120,120,120,.06);
      flex-wrap:wrap;
    }
    .stage {
      height: calc(100vh - 190px);
      min-height: 420px;
      overflow: auto;
      background: rgba(120,120,120,.04);
    }
    .stage.grabbing { cursor: grabbing; }

    .canvasWrap {
      position: relative;
      width: max-content;
      margin: 0 auto;
    }
    canvas { display:block; background: #fff; }

    .shapesSvg{
      position:absolute;
      inset:0;
      pointer-events:none;
    }

    .overlay {
      position: absolute;
      inset: 0;
      cursor: crosshair;
      background: transparent;
      user-select: none;
    }
    .overlay.grabbing { cursor: grabbing; }

    .drawBox{
      position:absolute;
      border:2px dashed rgba(0,163,255,.95);
      background: rgba(0,163,255,.12);
      pointer-events:none;
      display:none;
      z-index: 50;
    }

    .iframe {
      width: 100%;
      height: calc(100vh - 190px);
      min-height: 420px;
      border: 0;
      background: #fff;
    }

    .popover {
      position: fixed;
      z-index: 10000;
      min-width: 220px;
      max-width: min(380px, calc(100vw - 24px));
      padding: 10px 12px;
      border-radius: 12px;
      border: 1px solid rgba(120,120,120,.25);
      background: rgba(20,20,20,.92);
      color: #fff;
      font-size: 12px;
      line-height: 1.35;
      box-shadow: 0 12px 34px rgba(0,0,0,.28);
      display: none;
    }
    .popover.show { display: block; }
    .popover .t { font-weight: 700; font-size: 12px; }
    .popover .row { margin-top: 6px; opacity: .92; }
    .popover .muted { opacity: .72; }
    .popover .xbtn {
      position: absolute;
      top: 8px;
      right: 8px;
      width: 22px;
      height: 22px;
      border-radius: 999px;
      border: 1px solid rgba(255,255,255,.20);
      background: rgba(255,255,255,.06);
      color: #fff;
      cursor: pointer;
      display:flex;
      align-items:center;
      justify-content:center;
      font-size: 12px;
    }
    .popover .xbtn:hover { background: rgba(255,255,255,.12); }

    .popover .act{
      margin-top: 10px;
      display:flex;
      gap:8px;
      flex-wrap:wrap;
    }
    .popover .act button{
      border: 1px solid rgba(255,255,255,.20);
      background: rgba(255,255,255,.08);
      color:#fff;
      padding: 7px 10px;
      border-radius: 10px;
      cursor:pointer;
      font-size: 12px;
    }
    .popover .act button:hover{ background: rgba(255,255,255,.14); }

    .toast {
      position: fixed;
      right: 14px;
      bottom: 14px;
      z-index: 9999;
      max-width: min(560px, calc(100vw - 28px));
      padding: 10px 12px;
      border-radius: 12px;
      border: 1px solid rgba(120,120,120,.25);
      background: rgba(20,20,20,.86);
      color: #fff;
      font-size: 12px;
      line-height: 1.35;
      box-shadow: 0 10px 30px rgba(0,0,0,.25);
      opacity: 0;
      transform: translateY(6px);
      transition: opacity .12s ease, transform .12s ease;
      pointer-events: none;
    }
    .toast.show { opacity: 1; transform: translateY(0); }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="top">
      <div>
        <div class="title">Карта рынка</div>
      </div>
      <div class="btnrow">
        <button id="closeBtn" type="button" class="pill" style="background:transparent;">✕ Закрыть</button>
        <a id="toSettingsLink" href="__SETTINGS_URL_ATTR__" class="pill" style="text-decoration:none; display:none;">К настройкам</a>
        <span class="pill">Перетаскивание: зажми мышь и тяни • Клик: карточка • Масштаб: +/−</span>
      </div>
    </div>

    <script>
      (function () {
        const btn = document.getElementById('closeBtn');
        const toSettings = document.getElementById('toSettingsLink');

        btn.addEventListener('click', function () {
          try { window.close(); } catch (e) { /* ignore */ }
          setTimeout(function () {
            if (toSettings) toSettings.style.display = 'inline-flex';
          }, 150);
        });
      })();
    </script>

    <div class="viewer">
      <div class="toolbar">
        <div class="btnrow">
          <button id="zoomOut" type="button">−</button>
          <button id="zoomIn" type="button">+</button>
          <button id="zoomReset" type="button">100%</button>
          <button id="fitWidth" type="button">По ширине</button>
          <a class="pill" href="__PDF_URL_ATTR__" target="_blank" rel="noopener">Открыть PDF</a>
        </div>
        <div class="btnrow">
          <span class="pill" id="scaleLabel">Масштаб: 100%</span>
          __EDIT_CONTROLS__
        </div>
      </div>

      <div id="viewerRoot">
        <div class="stage" id="stage">
          <div class="canvasWrap" id="canvasWrap">
            <canvas id="canvas"></canvas>
            <svg id="shapesSvg" class="shapesSvg" aria-hidden="true"></svg>
            <div id="drawBox" class="drawBox" aria-hidden="true"></div>
            <div id="overlay" class="overlay" aria-label="map-overlay"></div>
          </div>
        </div>
      </div>
    </div>

    <div id="popover" class="popover" role="dialog" aria-live="polite">
      <button id="popoverClose" class="xbtn" type="button" aria-label="Закрыть">×</button>
      <div id="popoverBody"></div>
    </div>

    <div id="toast" class="toast" role="status" aria-live="polite"></div>

    <script type="module">
      const PDF_URL = __PDF_URL_JSON__;
      const HIT_URL = __HIT_URL_JSON__;
      const SHAPES_URL = __SHAPES_URL_JSON__;
      const SPACE_URL = __SPACE_URL_JSON__;
      const CAN_EDIT = __CAN_EDIT_JSON__;
      const MARKET_ID = __MARKET_ID_JSON__;

      const csrfMeta = document.querySelector('meta[name="csrf-token"]');
      const CSRF_TOKEN = csrfMeta ? csrfMeta.getAttribute('content') : '';

      const viewerRoot = document.getElementById('viewerRoot');

      const zoomInBtn = document.getElementById('zoomIn');
      const zoomOutBtn = document.getElementById('zoomOut');
      const zoomResetBtn = document.getElementById('zoomReset');
      const fitWidthBtn = document.getElementById('fitWidth');
      const scaleLabel = document.getElementById('scaleLabel');

      const popover = document.getElementById('popover');
      const popoverBody = document.getElementById('popoverBody');
      const popoverClose = document.getElementById('popoverClose');

      const toggleEditBtn = document.getElementById('toggleEdit');
      const marketSpaceIdInput = document.getElementById('marketSpaceId');
      const editHint = document.getElementById('editHint');
      const spaceIdState = document.getElementById('spaceIdState');

      const LS_KEY = 'mp.marketMap.market_' + String(MARKET_ID) + '.spaceId';

      function escapeHtml(s) {
        return String(s ?? '')
          .replaceAll('&', '&amp;')
          .replaceAll('<', '&lt;')
          .replaceAll('>', '&gt;')
          .replaceAll('"', '&quot;')
          .replaceAll("'", '&#039;');
      }

      function disablePdfJsControls() {
        zoomInBtn.disabled = true;
        zoomOutBtn.disabled = true;
        zoomResetBtn.disabled = true;
        fitWidthBtn.disabled = true;
        scaleLabel.textContent = 'Масштаб: (встроенный просмотр)';
      }

      function fallbackToIframe(reason) {
        console.warn('PDF.js fallback:', reason);
        disablePdfJsControls();
        viewerRoot.innerHTML = '<iframe class="iframe" src="' + PDF_URL + '" loading="lazy"></iframe>';
      }

      function toast(text) {
        const el = document.getElementById('toast');
        if (!el) return;

        el.textContent = text;
        el.classList.add('show');

        if (el._t) clearTimeout(el._t);
        el._t = setTimeout(() => el.classList.remove('show'), 1400);
      }

      function hidePopover() {
        popover.classList.remove('show');
      }

      function showPopoverAt(clientX, clientY, html) {
        popoverBody.innerHTML = html;

        const pad = 10;
        popover.classList.add('show');

        popover.style.left = (clientX + 12) + 'px';
        popover.style.top  = (clientY + 12) + 'px';

        const r = popover.getBoundingClientRect();
        let left = r.left;
        let top = r.top;

        if (r.right > window.innerWidth - pad) {
          left = Math.max(pad, window.innerWidth - pad - r.width);
        }
        if (r.bottom > window.innerHeight - pad) {
          top = Math.max(pad, window.innerHeight - pad - r.height);
        }

        popover.style.left = left + 'px';
        popover.style.top = top + 'px';
      }

      popoverClose.addEventListener('click', hidePopover);
      window.addEventListener('keydown', (e) => { if (e.key === 'Escape') hidePopover(); });
      window.addEventListener('click', (e) => {
        if (!popover.classList.contains('show')) return;
        if (popover.contains(e.target)) return;
        hidePopover();
      });

      async function apiFetch(url, options = {}) {
        const opts = { ...options };
        const method = String(opts.method || 'GET').toUpperCase();

        const headers = { ...(opts.headers || {}) };
        headers['Accept'] = headers['Accept'] || 'application/json';
        headers['X-Requested-With'] = headers['X-Requested-With'] || 'XMLHttpRequest';

        if (method !== 'GET' && method !== 'HEAD') {
          if (CSRF_TOKEN) headers['X-CSRF-TOKEN'] = CSRF_TOKEN;
        }

        opts.headers = headers;
        opts.credentials = 'same-origin';

        return fetch(url, opts);
      }

      function getChosenSpaceId() {
        if (!marketSpaceIdInput) return null;
        const raw = String(marketSpaceIdInput.value || '').trim();
        if (!raw) return null;
        const n = Number(raw);
        if (!Number.isFinite(n) || n <= 0) return null;
        return Math.trunc(n);
      }

      function saveChosenSpaceIdToLS() {
        if (!marketSpaceIdInput) return;
        const raw = String(marketSpaceIdInput.value || '').trim();
        if (!raw) {
          try { localStorage.removeItem(LS_KEY); } catch {}
          return;
        }
        try { localStorage.setItem(LS_KEY, raw); } catch {}
      }

      function setSpaceIdState(text, visible = true) {
        if (!spaceIdState) return;
        spaceIdState.textContent = text;
        spaceIdState.style.display = visible ? 'inline-flex' : 'none';
      }

      function markSpaceIdOk(ok) {
        if (!marketSpaceIdInput) return;
        if (ok === null) {
          marketSpaceIdInput.style.borderColor = 'rgba(120,120,120,.25)';
          return;
        }
        marketSpaceIdInput.style.borderColor = ok ? 'rgba(60,200,120,.85)' : 'rgba(240,80,80,.85)';
      }

      async function validateSpaceId(showToast = true) {
        const id = getChosenSpaceId();
        saveChosenSpaceIdToLS();

        if (!id) {
          markSpaceIdOk(null);
          setSpaceIdState('ID: —', false);
          if (showToast) toast('Место ID очищено');
          return { ok: true, found: false, item: null };
        }

        try {
          const url = new URL(SPACE_URL, window.location.origin);
          url.searchParams.set('id', String(id));

          const res = await apiFetch(url.toString(), { headers: { 'Accept': 'application/json' } });
          const json = await res.json();

          if (!res.ok || !json || json.ok !== true) {
            markSpaceIdOk(false);
            setSpaceIdState('ID: ' + String(id) + ' • ошибка', true);
            if (showToast) toast('Ошибка проверки ID');
            return { ok: false, found: false, item: null };
          }

          if (!json.found) {
            markSpaceIdOk(false);
            setSpaceIdState('ID: ' + String(id) + ' • не найдено', true);
            if (showToast) toast('ID ' + String(id) + ' не найден на этом рынке');
            return { ok: true, found: false, item: null };
          }

          const item = json.item || null;
          const label = item?.number?.trim?.() ? item.number : (item?.code || '');
          const tenant = item?.tenant?.name ? (' • ' + item.tenant.name) : '';
          markSpaceIdOk(true);
          setSpaceIdState('ID: ' + String(id) + (label ? (' • ' + String(label)) : '') + tenant, true);
          if (showToast) toast('Выбрано место ID ' + String(id) + (label ? (' (' + String(label) + ')') : ''));
          return { ok: true, found: true, item };
        } catch (e) {
          console.error(e);
          markSpaceIdOk(false);
          setSpaceIdState('ID: ' + String(id) + ' • ошибка', true);
          if (showToast) toast('Ошибка проверки ID');
          return { ok: false, found: false, item: null };
        }
      }

      // Restore saved ID on load
      if (CAN_EDIT && marketSpaceIdInput) {
        try {
          const saved = localStorage.getItem(LS_KEY);
          if (saved) marketSpaceIdInput.value = saved;
        } catch {}
      }

      function startPdfJs(pdfjsLib, workerSrc) {
        const stage = document.getElementById('stage');
        const canvas = document.getElementById('canvas');
        const ctx = canvas.getContext('2d');
        const overlay = document.getElementById('overlay');
        const shapesSvg = document.getElementById('shapesSvg');
        const drawBox = document.getElementById('drawBox');

        pdfjsLib.GlobalWorkerOptions.workerSrc = workerSrc;

        let page = null;
        let scale = 1.0;
        let currentViewport = null;

        let shapes = [];
        let editMode = false;

        function setScaleLabel() {
          scaleLabel.textContent = 'Масштаб: ' + Math.round(scale * 100) + '%';
        }

        async function loadShapes() {
          try {
            const url = new URL(SHAPES_URL, window.location.origin);
            url.searchParams.set('page', '1');
            url.searchParams.set('version', '1');

            const res = await apiFetch(url.toString(), { headers: { 'Accept': 'application/json' } });
            const json = await res.json();

            shapes = (json && json.ok === true && Array.isArray(json.items)) ? json.items : [];
          } catch (e) {
            console.error(e);
            shapes = [];
          }
        }

        function redrawShapes() {
          if (!shapesSvg || !currentViewport) return;

          shapesSvg.setAttribute('width', String(canvas.width));
          shapesSvg.setAttribute('height', String(canvas.height));
          shapesSvg.setAttribute('viewBox', '0 0 ' + canvas.width + ' ' + canvas.height);

          const parts = [];

          for (const s of shapes) {
            const poly = Array.isArray(s.polygon) ? s.polygon : [];
            if (poly.length < 3) continue;

            const pts = poly.map((p) => {
              const x = (p && (p.x ?? p[0])) ?? null;
              const y = (p && (p.y ?? p[1])) ?? null;
              if (x === null || y === null) return null;

              const v = currentViewport.convertToViewportPoint(Number(x), Number(y));
              const vx = Array.isArray(v) ? v[0] : null;
              const vy = Array.isArray(v) ? v[1] : null;

              if (vx === null || vy === null) return null;
              return Number(vx).toFixed(2) + ',' + Number(vy).toFixed(2);
            }).filter(Boolean).join(' ');

            if (!pts) continue;

            const fill = s.fill_color || '#00A3FF';
            const stroke = s.stroke_color || fill;
            const fo = (typeof s.fill_opacity === 'number') ? s.fill_opacity : 0.12;
            const sw = (typeof s.stroke_width === 'number') ? s.stroke_width : 1.5;

            parts.push(
              '<polygon points="' + pts +
              '" fill="' + fill +
              '" fill-opacity="' + fo +
              '" stroke="' + stroke +
              '" stroke-width="' + sw +
              '"></polygon>'
            );
          }

          shapesSvg.innerHTML = parts.join('');
        }

        async function render() {
          if (!page) return;

          const centerX = stage.scrollLeft + stage.clientWidth / 2;
          const centerY = stage.scrollTop + stage.clientHeight / 2;

          const prevW = canvas.width || 1;
          const prevH = canvas.height || 1;
          const relX = centerX / prevW;
          const relY = centerY / prevH;

          const viewport = page.getViewport({ scale });
          currentViewport = viewport;

          canvas.width = Math.floor(viewport.width);
          canvas.height = Math.floor(viewport.height);

          ctx.save();
          ctx.fillStyle = '#ffffff';
          ctx.fillRect(0, 0, canvas.width, canvas.height);
          ctx.restore();

          await page.render({ canvasContext: ctx, viewport }).promise;

          stage.scrollLeft = Math.max(0, relX * canvas.width - stage.clientWidth / 2);
          stage.scrollTop  = Math.max(0, relY * canvas.height - stage.clientHeight / 2);

          setScaleLabel();
          redrawShapes();
        }

        async function fitWidth() {
          if (!page) return;
          const viewport = page.getViewport({ scale: 1.0 });
          const padding = 24;
          const available = Math.max(200, stage.clientWidth - padding);
          scale = available / viewport.width;
          await render();
        }

        async function createRectShape(pdfPolygon) {
          const msId = getChosenSpaceId();

          const payload = {
            market_space_id: msId,
            page: 1,
            version: 1,
            polygon: pdfPolygon,
          };

          const res = await apiFetch(SHAPES_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
          });

          let json = null;
          try { json = await res.json(); } catch { json = null; }

          if (!res.ok || !json || json.ok !== true) {
            const msg = escapeHtml(json?.message || ('HTTP ' + res.status));
            throw new Error(msg);
          }

          await loadShapes();
          redrawShapes();
          toast(msId ? ('Разметка сохранена (ID ' + String(msId) + ')') : 'Разметка сохранена (без привязки)');
        }

        async function deleteShape(shapeId) {
          const id = Number(shapeId);
          if (!Number.isFinite(id) || id <= 0) return;

          const ok = confirm('Удалить этот полигон?');
          if (!ok) return;

          const url = SHAPES_URL.replace(/\/$/, '') + '/' + String(Math.trunc(id));
          const res = await apiFetch(url, { method: 'DELETE' });

          let json = null;
          try { json = await res.json(); } catch { json = null; }

          if (!res.ok || !json || json.ok !== true) {
            const msg = escapeHtml(json?.message || ('HTTP ' + res.status));
            throw new Error(msg);
          }

          await loadShapes();
          redrawShapes();
          toast('Полигон удалён');
        }

        async function init() {
          const loadingTask = pdfjsLib.getDocument(PDF_URL);
          const pdfDoc = await loadingTask.promise;
          page = await pdfDoc.getPage(1);

          await fitWidth();

          await loadShapes();
          redrawShapes();

          if (CAN_EDIT && marketSpaceIdInput) {
            // тихо проверим сохранённое значение (без тоста)
            await validateSpaceId(false);
          }

          toast('Клик по месту откроет карточку.');
        }

        zoomInBtn.addEventListener('click', async () => { scale = Math.min(6, scale * 1.2); await render(); });
        zoomOutBtn.addEventListener('click', async () => { scale = Math.max(0.2, scale / 1.2); await render(); });
        zoomResetBtn.addEventListener('click', async () => { scale = 1.0; await render(); });
        fitWidthBtn.addEventListener('click', async () => { await fitWidth(); });

        if (CAN_EDIT && toggleEditBtn) {
          toggleEditBtn.addEventListener('click', async () => {
            editMode = !editMode;
            toggleEditBtn.textContent = editMode ? 'Разметка: вкл' : 'Разметка: выкл';
            if (editHint) editHint.style.display = editMode ? 'inline-flex' : 'none';
            if (spaceIdState) spaceIdState.style.display = editMode ? 'inline-flex' : 'none';

            if (editMode) {
              await validateSpaceId(false);
              toast('Режим разметки: Shift+drag для прямоугольника');
            } else {
              toast('Режим разметки выключен');
            }
          });
        }

        // Input UX: Enter валидирует, change сохраняет
        if (CAN_EDIT && marketSpaceIdInput) {
          marketSpaceIdInput.addEventListener('change', () => validateSpaceId(false));
          marketSpaceIdInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
              e.preventDefault();
              e.stopPropagation();
              validateSpaceId(true);
            }
          });
        }

        // grab-to-pan + click + edit (Shift+drag)
        let isDown = false;
        let moved = false;
        let startX = 0, startY = 0, startLeft = 0, startTop = 0;
        const MOVE_THRESHOLD = 6;

        let drawing = false;
        let drawStart = null;

        function getCanvasPoint(e) {
          const rect = canvas.getBoundingClientRect();
          return {
            x: e.clientX - rect.left,
            y: e.clientY - rect.top,
          };
        }

        function showDrawBox(x1, y1, x2, y2) {
          if (!drawBox) return;
          const left = Math.min(x1, x2);
          const top = Math.min(y1, y2);
          const w = Math.abs(x2 - x1);
          const h = Math.abs(y2 - y1);

          drawBox.style.left = left.toFixed(1) + 'px';
          drawBox.style.top = top.toFixed(1) + 'px';
          drawBox.style.width = w.toFixed(1) + 'px';
          drawBox.style.height = h.toFixed(1) + 'px';
          drawBox.style.display = 'block';
        }

        function hideDrawBox() {
          if (!drawBox) return;
          drawBox.style.display = 'none';
        }

        function onDown(e) {
          // edit: Shift+drag => рисуем прямоугольник
          if (CAN_EDIT && editMode && e.shiftKey) {
            drawing = true;
            moved = true; // чтобы click handler не сработал
            drawStart = getCanvasPoint(e);
            showDrawBox(drawStart.x, drawStart.y, drawStart.x, drawStart.y);
            e.preventDefault();
            return;
          }

          // pan
          isDown = true;
          moved = false;

          overlay.classList.add('grabbing');
          stage.classList.add('grabbing');

          startX = e.clientX;
          startY = e.clientY;
          startLeft = stage.scrollLeft;
          startTop = stage.scrollTop;

          e.preventDefault();
        }

        function onUp(e) {
          if (drawing) {
            drawing = false;

            const end = getCanvasPoint(e);
            const s = drawStart;
            drawStart = null;

            hideDrawBox();

            if (!page || !currentViewport || !s) return;

            const left = Math.min(s.x, end.x);
            const top = Math.min(s.y, end.y);
            const right = Math.max(s.x, end.x);
            const bottom = Math.max(s.y, end.y);

            const w = right - left;
            const h = bottom - top;

            // слишком маленькие прямоугольники игнорим
            if (w < 10 || h < 10) {
              toast('Слишком маленькая область');
              return;
            }

            // конвертируем 4 угла в PDF координаты
            const p1 = currentViewport.convertToPdfPoint(left, top);
            const p2 = currentViewport.convertToPdfPoint(right, top);
            const p3 = currentViewport.convertToPdfPoint(right, bottom);
            const p4 = currentViewport.convertToPdfPoint(left, bottom);

            const poly = [
              { x: Number(p1[0]), y: Number(p1[1]) },
              { x: Number(p2[0]), y: Number(p2[1]) },
              { x: Number(p3[0]), y: Number(p3[1]) },
              { x: Number(p4[0]), y: Number(p4[1]) },
            ];

            createRectShape(poly).catch((err) => {
              console.error(err);
              toast('Ошибка сохранения: ' + String(err?.message || err));
            });

            return;
          }

          isDown = false;
          overlay.classList.remove('grabbing');
          stage.classList.remove('grabbing');
        }

        function onMove(e) {
          if (drawing) {
            const s = drawStart;
            if (!s) return;
            const p = getCanvasPoint(e);
            showDrawBox(s.x, s.y, p.x, p.y);
            return;
          }

          if (!isDown) return;

          const dx = e.clientX - startX;
          const dy = e.clientY - startY;

          if (!moved && (Math.abs(dx) > MOVE_THRESHOLD || Math.abs(dy) > MOVE_THRESHOLD)) {
            moved = true;
          }

          stage.scrollLeft = startLeft - dx;
          stage.scrollTop = startTop - dy;
        }

        async function onClick(e) {
          e.stopPropagation();

          if (drawing) return;
          if (moved) return;
          if (!page || !currentViewport) return;

          const rect = canvas.getBoundingClientRect();
          const xCanvas = e.clientX - rect.left;
          const yCanvas = e.clientY - rect.top;

          const p = currentViewport.convertToPdfPoint(xCanvas, yCanvas);
          const xPdf = Array.isArray(p) ? Number(p[0]) : 0;
          const yPdf = Array.isArray(p) ? Number(p[1]) : 0;

          showPopoverAt(
            e.clientX, e.clientY,
            '<div class="t">Поиск…</div><div class="row muted">x=' + xPdf.toFixed(1) + ', y=' + yPdf.toFixed(1) + '</div>'
          );

          try {
            const url = new URL(HIT_URL, window.location.origin);
            url.searchParams.set('x', String(xPdf));
            url.searchParams.set('y', String(yPdf));
            url.searchParams.set('page', '1');
            url.searchParams.set('version', '1');

            const res = await apiFetch(url.toString(), { headers: { 'Accept': 'application/json' } });
            const json = await res.json();

            if (!json || json.ok !== true) {
              const msg = escapeHtml(json?.message || 'Ошибка hit-test');
              showPopoverAt(e.clientX, e.clientY, '<div class="t">Ошибка</div><div class="row">' + msg + '</div>');
              return;
            }

            if (!json.hit) {
              const msg = escapeHtml(json?.message || 'Ничего не найдено');
              showPopoverAt(
                e.clientX, e.clientY,
                '<div class="t">Нет попадания</div>' +
                '<div class="row muted">' + msg + '</div>' +
                '<div class="row muted">x=' + xPdf.toFixed(1) + ', y=' + yPdf.toFixed(1) + '</div>'
              );
              return;
            }

            const hit = json.hit;
            const space = hit.space || null;
            const tenant = hit.tenant || null;

            let title = 'Торговое место';
            let line1 = '';
            let line2 = '';

            if (space) {
              const label = (space.number && String(space.number).trim()) ? String(space.number) : (space.code || '');
              title = label ? ('Место: ' + escapeHtml(label)) : 'Торговое место';
              line1 = space.area_sqm ? ('Площадь: ' + escapeHtml(space.area_sqm) + ' м²') : '';
              line2 = tenant?.name ? ('Арендатор: ' + escapeHtml(tenant.name)) : 'Арендатор: —';
            } else {
              line1 = 'Место не привязано (разметка)';
              line2 = '';
            }

            let actions = '';
            if (CAN_EDIT && editMode) {
              const btns = [];
              if (hit.market_space_id) {
                btns.push(
                  '<button type="button" data-action="set-space-id" data-space-id="' + String(hit.market_space_id) + '">Взять ID</button>'
                );
              }
              if (hit.shape_id) {
                btns.push(
                  '<button type="button" data-action="delete-shape" data-shape-id="' + String(hit.shape_id) + '">Удалить разметку</button>'
                );
              }
              if (btns.length) {
                actions = '<div class="act">' + btns.join('') + '</div>';
              }
            }

            showPopoverAt(
              e.clientX, e.clientY,
              '<div class="t">' + title + '</div>' +
              (line2 ? '<div class="row">' + line2 + '</div>' : '') +
              (line1 ? '<div class="row muted">' + escapeHtml(line1) + '</div>' : '') +
              '<div class="row muted">x=' + xPdf.toFixed(1) + ', y=' + yPdf.toFixed(1) + '</div>' +
              actions
            );
          } catch (err) {
            console.error(err);
            showPopoverAt(e.clientX, e.clientY, '<div class="t">Ошибка</div><div class="row">Не удалось выполнить запрос hit-test.</div>');
          }
        }

        popover.addEventListener('click', (e) => {
          const t = e.target;
          if (!(t instanceof HTMLElement)) return;

          const action = t.getAttribute('data-action');

          if (action === 'delete-shape') {
            const id = t.getAttribute('data-shape-id');
            deleteShape(id).then(() => hidePopover()).catch((err) => {
              console.error(err);
              toast('Ошибка удаления: ' + String(err?.message || err));
            });
            return;
          }

          if (action === 'set-space-id') {
            const id = t.getAttribute('data-space-id');
            if (!marketSpaceIdInput) return;
            const n = Number(id);
            if (!Number.isFinite(n) || n <= 0) return;

            marketSpaceIdInput.value = String(Math.trunc(n));
            validateSpaceId(true);
            hidePopover();
            return;
          }
        });

        overlay.addEventListener('mousedown', onDown);
        window.addEventListener('mouseup', onUp);
        window.addEventListener('mousemove', onMove);
        overlay.addEventListener('click', onClick);

        init().catch((err) => {
          console.error(err);
          fallbackToIframe(err?.message || 'init failed');
        });
      }

      async function tryImport(pdfUrl, workerUrl) {
        try {
          const mod = await import(pdfUrl);
          const pdfjsLib = mod?.default ?? mod;
          if (!pdfjsLib || typeof pdfjsLib.getDocument !== 'function') return null;
          return { pdfjsLib, workerSrc: workerUrl };
        } catch {
          return null;
        }
      }

      async function loadPdfJs() {
        const localMjs = await tryImport('/vendor/pdfjs/pdf.min.mjs', '/vendor/pdfjs/pdf.worker.min.mjs');
        if (localMjs) return localMjs;

        const localJs = await tryImport('/vendor/pdfjs/pdf.min.js', '/vendor/pdfjs/pdf.worker.min.js');
        if (localJs) return localJs;

        const cdn = await tryImport(
          'https://cdn.jsdelivr.net/npm/pdfjs-dist@4.10.38/build/pdf.min.mjs',
          'https://cdn.jsdelivr.net/npm/pdfjs-dist@4.10.38/build/pdf.worker.min.mjs'
        );
        if (cdn) return cdn;

        return null;
      }

      const loaded = await loadPdfJs();
      if (!loaded) {
        fallbackToIframe('cannot import pdfjs from local and CDN');
      } else {
        startPdfJs(loaded.pdfjsLib, loaded.workerSrc);
      }
    </script>

  </div>
</body>
</html>
HTML;

        return response(strtr($html, $repl))->header('Content-Type', 'text/html; charset=UTF-8');
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
