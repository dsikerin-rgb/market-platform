<?php

# routes/web.php

use App\Http\Controllers\Auth\MarketRegistrationController;
use App\Models\Market;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate as FilamentAuthenticate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::view('/', 'welcome')->name('home');

Route::middleware(['web', 'panel:admin', FilamentAuthenticate::class])->group(function () {
    /**
     * Переключатель рынка для super-admin (используется в topbar-user-info.blade.php).
     * Сохраняет выбранный market_id в сессии.
     */
    Route::post('/admin/switch-market', function (Request $request) {
        $user = Filament::auth()->user();

        abort_unless($user && $user->isSuperAdmin(), 403);

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
     * Viewer карты рынка (PDF.js) — pan + zoom.
     * Работает для:
     * - super-admin: выбранный рынок из переключателя (или первый)
     * - остальные: свой market_id
     */
    Route::get('/admin/market-map', function () {
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

            // Базовая проверка доступа (аналогично логике MarketSettings).
            $hasRoleAccess = method_exists($user, 'hasAnyRole')
                && $user->hasAnyRole(['market-admin', 'market-maintenance']);

            $hasPermissionAccess =
                $user->can('markets.view') ||
                $user->can('markets.update') ||
                $user->can('markets.viewAny');

            abort_unless($market && ($hasRoleAccess || $hasPermissionAccess), 403);
        }

        abort_unless($market, 404);

        $mapPath = data_get($market->settings ?? [], 'map_pdf_path');
        $pdfUrl = route('filament.admin.market-map.pdf');

        $marketName = e((string) ($market->name ?? 'Рынок'));
        $marketAddress = e((string) ($market->address ?? ''));

        $hasMap = is_string($mapPath) && $mapPath !== '';

        $html = <<<HTML
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Карта рынка — {$marketName}</title>
  <style>
    :root { color-scheme: light dark; }
    body { margin: 0; font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Arial; }
    .wrap { padding: 16px; max-width: 1400px; margin: 0 auto; }
    .top { display:flex; gap:12px; align-items:flex-start; justify-content:space-between; flex-wrap:wrap; }
    .title { font-size: 18px; font-weight: 700; }
    .sub { font-size: 12px; opacity: .75; margin-top: 2px; }
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
      align-items:center;
      justify-content:space-between;
      border-bottom: 1px solid rgba(120,120,120,.18);
      background: rgba(120,120,120,.06);
      flex-wrap:wrap;
    }
    .stage {
      height: calc(100vh - 170px);
      min-height: 420px;
      overflow: auto;
      cursor: grab;
      background: rgba(120,120,120,.04);
    }
    .stage.grabbing { cursor: grabbing; }
    canvas { display:block; margin: 0 auto; }
    .empty {
      padding: 14px;
      border-radius: 14px;
      border: 1px solid rgba(120,120,120,.25);
      background: rgba(120,120,120,.06);
      margin-top: 14px;
      font-size: 14px;
    }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="top">
      <div>
        <div class="title">Карта рынка</div>
        <div class="sub">{$marketName}{$this->escapeForInline($marketAddress)}</div>
      </div>
      <div class="btnrow">
        <a href="javascript:history.back()" class="pill" style="text-decoration:none;">← Назад</a>
        <span class="pill">Перетаскивание: зажми мышь и тяни • Масштаб: кнопки +/−</span>
      </div>
    </div>

HTML;

        // небольшой хак: чтобы не городить условную конкатенацию внутри heredoc
        // (адрес уже в $marketAddress, можно выводить отдельной строкой в разметке)
        $html .= $hasMap
            ? <<<HTML
    <div class="viewer">
      <div class="toolbar">
        <div class="btnrow">
          <button id="zoomOut" type="button">−</button>
          <button id="zoomIn" type="button">+</button>
          <button id="zoomReset" type="button">100%</button>
          <button id="fitWidth" type="button">По ширине</button>
        </div>
        <div class="btnrow">
          <span class="pill" id="scaleLabel">Масштаб: 100%</span>
        </div>
      </div>
      <div class="stage" id="stage">
        <canvas id="canvas"></canvas>
      </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/pdfjs-dist@4.10.38/build/pdf.min.js"></script>
    <script>
      (function () {
        const PDF_URL = {$this->json($pdfUrl)};

        const stage = document.getElementById('stage');
        const canvas = document.getElementById('canvas');
        const ctx = canvas.getContext('2d');

        const zoomInBtn = document.getElementById('zoomIn');
        const zoomOutBtn = document.getElementById('zoomOut');
        const zoomResetBtn = document.getElementById('zoomReset');
        const fitWidthBtn = document.getElementById('fitWidth');
        const scaleLabel = document.getElementById('scaleLabel');

        // Worker обязателен, иначе будет "fake worker" и тормоза.
        pdfjsLib.GlobalWorkerOptions.workerSrc =
          'https://cdn.jsdelivr.net/npm/pdfjs-dist@4.10.38/build/pdf.worker.min.js';

        let pdfDoc = null;
        let page = null;
        let scale = 1.0;

        function setScaleLabel() {
          scaleLabel.textContent = 'Масштаб: ' + Math.round(scale * 100) + '%';
        }

        async function render() {
          if (!page) return;

          // сохраняем "центр" чтобы после зума не улетать
          const centerX = stage.scrollLeft + stage.clientWidth / 2;
          const centerY = stage.scrollTop + stage.clientHeight / 2;

          const prevW = canvas.width || 1;
          const prevH = canvas.height || 1;
          const relX = centerX / prevW;
          const relY = centerY / prevH;

          const viewport = page.getViewport({ scale });
          canvas.width = Math.floor(viewport.width);
          canvas.height = Math.floor(viewport.height);

          await page.render({ canvasContext: ctx, viewport }).promise;

          // восстановим центр
          stage.scrollLeft = Math.max(0, relX * canvas.width - stage.clientWidth / 2);
          stage.scrollTop  = Math.max(0, relY * canvas.height - stage.clientHeight / 2);

          setScaleLabel();
        }

        async function init() {
          const loadingTask = pdfjsLib.getDocument(PDF_URL);
          pdfDoc = await loadingTask.promise;
          page = await pdfDoc.getPage(1);
          await render();
        }

        zoomInBtn.addEventListener('click', async () => {
          scale = Math.min(6, scale * 1.2);
          await render();
        });

        zoomOutBtn.addEventListener('click', async () => {
          scale = Math.max(0.2, scale / 1.2);
          await render();
        });

        zoomResetBtn.addEventListener('click', async () => {
          scale = 1.0;
          await render();
        });

        fitWidthBtn.addEventListener('click', async () => {
          if (!page) return;
          const viewport = page.getViewport({ scale: 1.0 });
          const padding = 24;
          const available = Math.max(200, stage.clientWidth - padding);
          scale = available / viewport.width;
          await render();
        });

        // grab-to-pan (скролл контейнера)
        let isDown = false;
        let startX = 0, startY = 0, startLeft = 0, startTop = 0;

        stage.addEventListener('mousedown', (e) => {
          isDown = true;
          stage.classList.add('grabbing');
          startX = e.clientX;
          startY = e.clientY;
          startLeft = stage.scrollLeft;
          startTop = stage.scrollTop;
        });

        window.addEventListener('mouseup', () => {
          isDown = false;
          stage.classList.remove('grabbing');
        });

        window.addEventListener('mousemove', (e) => {
          if (!isDown) return;
          const dx = e.clientX - startX;
          const dy = e.clientY - startY;
          stage.scrollLeft = startLeft - dx;
          stage.scrollTop = startTop - dy;
        });

        init().catch((err) => {
          console.error(err);
          alert('Не удалось загрузить PDF. Проверь, что карта загружена и доступна.');
        });
      })();
    </script>

HTML
            : <<<HTML
    <div class="empty">
      <strong>Карта не загружена.</strong>
      <div style="margin-top:6px; opacity:.8;">
        Загрузите PDF-карту в настройках рынка (поле “Карта (PDF)”), затем откройте просмотр снова.
      </div>
    </div>

HTML;

        $html .= <<<HTML
  </div>
</body>
</html>
HTML;

        return response($html)->header('Content-Type', 'text/html; charset=UTF-8');
    })->name('filament.admin.market-map');

    /**
     * Выдача приватного PDF карты для PDF.js (только авторизованным).
     * Всегда отдаёт карту текущего рынка (выбранного super-admin или market пользователя).
     */
    Route::get('/admin/market-map/pdf', function () {
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

        $mapPath = data_get($market->settings ?? [], 'map_pdf_path');

        abort_unless(is_string($mapPath) && $mapPath !== '', 404);

        // Базовая защита: разрешаем только наш каталог.
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
