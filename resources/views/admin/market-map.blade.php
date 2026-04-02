<!doctype html>
<html lang="ru">
<head>
  @php
    /** @var bool $hasMap */
    $marketName = $marketName ?? (isset($market) ? (string) ($market->name ?? 'Рынок') : 'Рынок');
    $marketId   = (int) ($marketId ?? (isset($market) ? (int) ($market->id ?? 0) : 0));

    $hasMap   = isset($hasMap) ? (bool) $hasMap : true;
    $canEdit  = isset($canEdit) ? (bool) $canEdit : false;
    $marketSpaceNotLinked = isset($marketSpaceNotLinked) ? (bool) $marketSpaceNotLinked : false;
    $canOpenPdf = isset($canOpenPdf) ? (bool) $canOpenPdf : false;

    $settingsUrl = $settingsUrl ?? url('/admin/market-settings');

    // URL’ы API/viewer’а (могут отсутствовать при $hasMap=false — это ок)
    $pdfUrl    = $pdfUrl ?? '';
    $hitUrl    = $hitUrl ?? '';
    $shapesUrl = $shapesUrl ?? '';
    $spaceUrl  = $spaceUrl ?? '';
    $spacesUrl = $spacesUrl ?? '';
  @endphp

  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <link rel="modulepreload" href="/vendor/pdfjs/pdf.min.mjs">
  <link rel="modulepreload" href="/vendor/pdfjs/pdf.worker.min.mjs">
  <title>Карта рынка — {{ $marketName }}</title>

  <style>
    :root { color-scheme: light dark; }
    body { margin: 0; font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Arial; }
    .wrap { padding: 16px; max-width: 1400px; margin: 0 auto; }
    .btnrow { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }

    button {
      border: 1px solid rgba(120,120,120,.35);
      background: rgba(120,120,120,.10);
      padding: 6px 9px;
      border-radius: 9px;
      cursor: pointer;
      font-size: 12px;
    }
    button:hover { background: rgba(120,120,120,.18); }
    button:disabled { opacity:.5; cursor:not-allowed; }
    .button-accent {
      background: #f59e0b;
      border-color: #d97706;
      color: #fff;
    }
    .button-accent:hover {
      background: #ea580c;
    }
    .button-toggle.is-active {
      background: #0f172a;
      border-color: #0f172a;
      color: #fff;
    }
    .button-toggle.is-active:hover {
      background: #1e293b;
    }
    .review-progress {
      display: none;
      align-items: center;
      gap: 8px;
      min-width: 220px;
    }
    .review-progress__track {
      position: relative;
      width: 160px;
      height: 8px;
      border-radius: 999px;
      overflow: hidden;
      background: rgba(15, 23, 42, 0.12);
    }
    .review-progress__fill {
      position: absolute;
      inset: 0 auto 0 0;
      width: 0;
      background: linear-gradient(90deg, #0f766e 0%, #14b8a6 100%);
      border-radius: 999px;
    }
    .review-progress__text {
      font-size: 11px;
      font-weight: 600;
      white-space: nowrap;
    }
    .review-summary {
      display: none;
      gap: 6px;
      align-items: center;
      flex-wrap: wrap;
    }
    .map-load-progress {
      display: none;
      flex-direction: column;
      gap: 8px;
      width: calc(100% - 36px);
      box-sizing: border-box;
      margin: 12px 18px 16px;
      padding: 12px 14px;
      border-radius: 14px;
      background: rgba(255, 255, 255, 0.9);
      border: 1px solid rgba(15, 23, 42, 0.08);
      box-shadow: 0 10px 30px rgba(15, 23, 42, 0.06);
      transition: opacity .2s ease, transform .2s ease;
    }
    .map-load-progress__meta {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
    }
    .map-load-progress__track {
      position: relative;
      width: 100%;
      height: 10px;
      border-radius: 999px;
      overflow: hidden;
      background: rgba(15, 23, 42, 0.12);
    }
    .map-load-progress__fill {
      position: absolute;
      inset: 0 auto 0 0;
      width: 0;
      background: linear-gradient(90deg, #2563eb 0%, #38bdf8 100%);
      border-radius: 999px;
    }
    @keyframes mapLoadShimmer {
      0% { background-position: 0% 50%; }
      100% { background-position: 200% 50%; }
    }
    .map-load-progress[data-state="done"] .map-load-progress__fill {
      background: linear-gradient(90deg, #15803d 0%, #22c55e 100%);
    }
    .map-load-progress[data-state="fallback"] .map-load-progress__fill {
      background: linear-gradient(90deg, #9ca3af 0%, #cbd5e1 100%);
    }
    .map-load-progress[data-phase="finalizing"] .map-load-progress__fill {
      width: 100%;
      background: linear-gradient(90deg, #2563eb 0%, #60a5fa 25%, #38bdf8 50%, #60a5fa 75%, #2563eb 100%);
      background-size: 200% 100%;
      animation: mapLoadShimmer 1.1s linear infinite;
    }
    .map-load-progress__text {
      font-size: 12px;
      font-weight: 600;
    }
    .map-load-progress__percent {
      font-size: 12px;
      font-weight: 700;
      color: rgba(15, 23, 42, 0.72);
      white-space: nowrap;
      font-variant-numeric: tabular-nums;
    }

    .pill {
      font-size: 11px;
      opacity: .85;
      padding: 5px 9px;
      border-radius: 999px;
      border: 1px solid rgba(120,120,120,.25);
      display: inline-flex;
      align-items: center;
      gap: 6px;
      text-decoration: none;
      white-space: nowrap;
    }

    .spacePicker {
      position: relative;
      min-width: 180px;
    }

    .spaceSearchInput {
      width: 200px;
      padding: 5px 8px;
      border-radius: 9px;
      border: 1px solid rgba(120,120,120,.25);
      background: rgba(120,120,120,.06);
      color: inherit;
    }

    .spaceDropdown {
      position: absolute;
      top: calc(100% + 6px);
      left: 0;
      right: 0;
      z-index: 120;
      border: 1px solid rgba(120,120,120,.25);
      border-radius: 10px;
      background: rgba(30,30,30,.98);
      color: #fff;
      padding: 4px;
      display: none;
      max-height: 320px;
      overflow-y: auto;
      box-shadow: 0 10px 26px rgba(0,0,0,.28);
    }

    .spaceDropdownItem {
      padding: 6px 8px;
      border-radius: 8px;
      cursor: pointer;
      font-size: 12px;
      display: flex;
      gap: 6px;
      align-items: center;
    }

    .spaceDropdownItem:hover,
    .spaceDropdownItem.active {
      background: rgba(255,255,255,.12);
    }

    .spaceDropdownEmpty {
      padding: 6px 8px;
      font-size: 12px;
      opacity: .75;
    }

    .spacePillButton {
      border: 0;
      background: transparent;
      color: inherit;
      font-size: 12px;
      cursor: pointer;
      padding: 0;
      line-height: 1;
    }

    .viewer {
      margin-top: 14px;
      border: 1px solid rgba(120,120,120,.25);
      border-radius: 14px;
      overflow: hidden;
      background: #fff;
    }
    .toolbar {
      padding: 14px 16px 12px;
      display: grid;
      gap: 10px;
      border-bottom: 1px solid rgba(120,120,120,.18);
      background: linear-gradient(180deg, rgba(255,255,255,.98) 0%, rgba(248,250,252,.98) 100%);
    }
    .toolbar-row {
      display: flex;
      gap: 10px;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
    }
    .toolbar-row.toolbar-row--hero {
      align-items: flex-start;
      gap: 14px;
    }
    .toolbar-group {
      display: flex;
      gap: 6px;
      align-items: center;
      flex-wrap: wrap;
      padding: 0;
      min-width: 0;
    }
    .toolbar-group.toolbar-group--accent {
      padding: 4px 6px;
      border-radius: 10px;
      background: rgba(255,255,255,.92);
      border: 1px solid rgba(120,120,120,.16);
    }
    .toolbar-label {
      display: inline-flex;
      align-items: center;
      padding: 0 4px 0 0;
      color: rgba(15, 23, 42, 0.72);
      font-size: 11px;
      font-weight: 600;
      white-space: nowrap;
    }
    .hero-heading {
      display: grid;
      gap: 4px;
      min-width: 0;
    }
    .hero-kicker {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      color: rgba(15, 23, 42, 0.58);
      font-size: 11px;
      font-weight: 700;
      letter-spacing: .08em;
      text-transform: uppercase;
    }
    .hero-title-line {
      display: flex;
      align-items: baseline;
      flex-wrap: wrap;
      gap: 10px;
    }
    .hero-title {
      margin: 0;
      color: #0f172a;
      font-size: 24px;
      line-height: 1.1;
      font-weight: 700;
    }
    .toolbar-group.toolbar-group--hero-actions {
      margin-left: auto;
      justify-content: flex-end;
      align-self: flex-start;
    }
    .legend[hidden] {
      display: none;
    }
    .legend-note {
      color: rgba(15, 23, 42, 0.72);
      font-size: 10px;
    }
    
    /* Легенда карты */
    .legend {
      padding: 8px 10px;
      border-bottom: 1px solid rgba(120,120,120,.18);
      background: rgba(255,255,255,.95);
      font-size: 11px;
    }
    .legend-items {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
    }
    .legend-item {
      display: flex;
      align-items: center;
      gap: 5px;
    }
    .legend-item.legend-item--note { display: none; }
    .legend-color {
      width: 16px;
      height: 16px;
      border-radius: 4px;
      border: 1px solid rgba(120,120,120,.3);
      flex-shrink: 0;
    }
    .legend-color.legend-vacant {
      background: #e5e7eb;
      border: 1px solid #94a3b8;
    }
    .legend-color.legend-unlinked {
      background:
        repeating-linear-gradient(
          45deg,
          rgba(148, 163, 184, 0.85) 0 2px,
          transparent 2px 6px
        );
      border: 1px solid #94a3b8;
    }
    .legend-color.legend-rate-none {
      background: #cbd5e1;
      border: 1px solid #94a3b8;
    }
    .legend-label {
      white-space: nowrap;
    }
    @media (max-width: 900px) {
      .toolbar {
        padding: 12px 12px 10px;
      }
      .hero-title {
        font-size: 20px;
      }
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
      z-index: 40;
      display: block;
      overflow: visible;
    }

    .overlay {
      position: absolute;
      inset: 0;
      cursor: crosshair;
      background: transparent;
      user-select: none;
      -webkit-user-select: none;
      z-index: 60;
    }
    .overlay.grabbing { cursor: grabbing; }

    .handlesLayer{
      position:absolute;
      inset:0;
      z-index: 80;
      pointer-events: none;
    }

    .handleDot{
      position:absolute;
      width: 12px;
      height: 12px;
      margin-left: -6px;
      margin-top: -6px;
      border-radius: 999px;
      border: 2px solid rgba(255,255,255,.92);
      background: rgba(0,163,255,.92);
      box-shadow: 0 6px 18px rgba(0,0,0,.22);
      cursor: grab;
      pointer-events: auto;
    }
    .handleDot:active{ cursor: grabbing; }

    .handleDot.active{
      background: rgba(255,170,0,.92);
    }

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
      max-width: min(420px, calc(100vw - 24px));
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
    .popover .t { font-weight: 700; font-size: 12px; padding-right: 28px; }
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
    .popover .act button:disabled{ opacity:.5; cursor:not-allowed; }

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

    .empty {
      padding: 14px;
      border-radius: 14px;
      border: 1px solid rgba(120,120,120,.25);
      background: rgba(120,120,120,.06);
      margin-top: 14px;
      font-size: 14px;
    }

    input[type="number"]{
      appearance: textfield;
      -moz-appearance: textfield;
    }
    input[type="number"]::-webkit-outer-spin-button,
    input[type="number"]::-webkit-inner-spin-button{
      -webkit-appearance: none;
      margin: 0;
    }
  </style>
</head>

<body>
  <div class="wrap">

    <script>
      document.addEventListener('DOMContentLoaded', function () {
        const btn = document.getElementById('closeBtn');
        const toSettings = document.getElementById('toSettingsLink');

        btn?.addEventListener('click', function () {
          try { window.close(); } catch (e) { /* ignore */ }

          // Если вкладку закрыть нельзя (не window.open), покажем ссылку на настройки.
          setTimeout(function () {
            if (toSettings) toSettings.style.display = 'inline-flex';
          }, 150);
        });
      });
    </script>

    @if (! $hasMap)
      <div class="empty">
        <strong>Карта не загружена.</strong>
        <div style="margin-top:6px; opacity:.8;">
          Загрузите PDF-карту в настройках рынка (поле “Карта (PDF)”), нажмите “Сохранить”, затем откройте просмотр снова.
        </div>
      </div>
    @elseif ($marketSpaceNotLinked)
      <div class="empty">
        <strong>Торговое место не привязано к объектам карты.</strong>
        <div style="margin-top:6px; opacity:.8;">
          Привяжите место к полигону или прямоугольнику, чтобы открыть карту с фокусом.
        </div>
      </div>
    @else
      <div class="viewer">
        <div class="toolbar">
          <div class="toolbar-row toolbar-row--hero">
            <div class="hero-heading">
              <span class="hero-kicker">Карта</span>
              <div class="hero-title-line">
                <h1 class="hero-title">{{ $marketName }}</h1>
              </div>
            </div>

            <div class="toolbar-group toolbar-group--hero-actions">
              @if ($canEdit)
                <div class="toolbar-group toolbar-group--accent">
                  <span class="toolbar-label">Режим</span>
                  <button id="scenarioMap" type="button" class="button-toggle is-active">Карта</button>
                  <button id="scenarioReview" type="button" class="button-toggle">Ревизия</button>
                </div>
              @endif
              <button id="closeBtn" type="button" class="button-accent">Закрыть</button>
              <a id="toSettingsLink" href="{{ $settingsUrl }}" class="pill" style="display:none;">К настройкам</a>
            </div>
          </div>

          <div class="toolbar-row">
            <div class="toolbar-group toolbar-group--accent">
              <button id="zoomOut" type="button">−</button>
              <button id="zoomIn" type="button">+</button>
              <button id="zoomReset" type="button">100%</button>
              <button id="fitWidth" type="button">По ширине</button>
              @if ($canOpenPdf)
                <a class="pill" href="{{ $pdfUrl }}" target="_blank" rel="noopener noreferrer">Открыть PDF</a>
              @endif
            </div>

            <div class="toolbar-group toolbar-group--accent">
              <span class="toolbar-label">Слои</span>
              <button
                id="layerDebt"
                type="button"
                class="button-toggle is-active"
                title="Слой показывает статус задолженности по занятым местам."
                aria-label="Слой показывает статус задолженности по занятым местам."
              >Задолженность</button>
              <button
                id="layerRent"
                type="button"
                class="button-toggle"
                title="Слой показывает относительную ставку по занятым местам."
                aria-label="Слой показывает относительную ставку по занятым местам."
              >Арендная ставка</button>
            </div>
          </div>

          @if ($canEdit)
            <div class="toolbar-row">
              <div class="toolbar-group toolbar-group--accent">
                <button id="toggleEdit" type="button">Разметка: выкл</button>
                <button id="toolSelect" type="button" style="display:none;">Редактировать</button>
                <button id="toolRect" type="button" style="display:none;">Прямоугольник</button>
                <button id="toolPoly" type="button" style="display:none;">Полигон</button>
              </div>

              <div class="toolbar-group">
                <span class="pill" id="scaleLabel">Масштаб: 100%</span>
                <span class="pill" title="Перетаскивание: зажми мышь и тяни • Клик: карточка • Масштаб: +/−">Навигация</span>
                <div class="spacePicker" style="display:none;" id="spacePicker">
                  <input
                    id="spaceSearch"
                    type="text"
                    class="spaceSearchInput"
                    placeholder="Номер / код / арендатор / ID"
                    autocomplete="off"
                  >
                  <div id="spaceDropdown" class="spaceDropdown" role="listbox" aria-label="Результаты поиска"></div>
                </div>

                <label class="pill" id="spaceNumberPill" style="display:none;">
                  Номер:
                  <input
                    id="marketSpaceNumber"
                    type="text"
                    inputmode="text"
                    placeholder="например 45-4"
                    style="width:120px; padding:6px 8px; border-radius:10px; border:1px solid rgba(120,120,120,.25); background:rgba(120,120,120,.06); color:inherit;"
                  >
                </label>

                <button id="findByNumber" type="button" style="display:none;">Найти ID</button>

                <label class="pill" style="display:none;">
                  Место ID:
                  <input
                    id="marketSpaceId"
                    type="number"
                    min="1"
                    step="1"
                    inputmode="numeric"
                    placeholder="ID"
                    style="width:92px; padding:6px 8px; border-radius:10px; border:1px solid rgba(120,120,120,.25); background:rgba(120,120,120,.06); color:inherit;"
                  >
                </label>

                <span class="pill" id="spaceChosenPill" style="display:none;"></span>
                <span class="pill" id="spaceIdState" style="display:none;">ID: —</span>
                <span class="pill" id="editHint" style="display:none;" title="Редактировать: клик — выбрать • тащи точки • Alt+клик — вставить вершину • Delete — удалить">Режим разметки</span>
              </div>
            </div>
            <div class="toolbar-row" id="reviewToolbarRow">
              <div class="toolbar-group">
                <button id="reviewNotFound" type="button" style="display:none;">Не найдено на карте</button>
                <div class="review-progress" id="reviewProgress" aria-live="polite">
                  <div class="review-progress__track">
                    <div class="review-progress__fill" id="reviewProgressFill"></div>
                  </div>
                  <span class="review-progress__text" id="reviewProgressText">0 / 0</span>
                </div>
                <div class="review-summary" id="reviewSummary"></div>
              </div>
            </div>
          @endif
          @if (! $canEdit)
            <div class="toolbar-row">
              <div class="toolbar-group">
                <span class="pill" id="scaleLabel">Масштаб: 100%</span>
                <span class="pill" title="Перетаскивание: зажми мышь и тяни • Клик: карточка • Масштаб: +/−">Навигация</span>
              </div>
            </div>
          @endif
        </div>

        <div class="map-load-progress" id="mapLoadProgress" aria-live="polite">
          <div class="map-load-progress__meta">
            <span class="map-load-progress__text" id="mapLoadProgressText">Загрузка карты…</span>
            <span class="map-load-progress__percent" id="mapLoadProgressPercent">0%</span>
          </div>
          <div class="map-load-progress__track">
            <div class="map-load-progress__fill" id="mapLoadProgressFill"></div>
          </div>
        </div>

        <!-- Легенда карты -->
        <div class="legend" id="legendDebt">
          <div class="legend-items">
            <div class="legend-item">
              <span class="legend-color" style="background: #22c55e;"></span>
              <span class="legend-label">Нет просрочки</span>
            </div>
            <div class="legend-item">
              <span class="legend-color" style="background: #f59e0b;"></span>
              <span class="legend-label">
                @if (($debtYellowAfterDays ?? 1) <= 1)
                  Просрочка до {{ ($debtRedAfterDays ?? 30) - 1 }} дней
                @else
                  Просрочка {{ $debtYellowAfterDays ?? 1 }}-{{ ($debtRedAfterDays ?? 30) - 1 }} дней
                @endif
              </span>
            </div>
            <div class="legend-item">
              <span class="legend-color" style="background: #dc2626;"></span>
              <span class="legend-label">Просрочка от {{ $debtRedAfterDays ?? 30 }} дней</span>
            </div>
            <div class="legend-item">
              <span class="legend-color" style="background: #94a3b8;"></span>
              <span class="legend-label">Нет данных 1С</span>
            </div>
            <div class="legend-item">
              <span class="legend-color legend-vacant"></span>
              <span class="legend-label">Свободно</span>
            </div>
            <div class="legend-item">
              <span class="legend-color legend-unlinked"></span>
              <span class="legend-label">Разметка без привязки</span>
            </div>
            <div class="legend-item legend-item--note">
              <span class="legend-note" id="debtLegendNote">Слой показывает статус задолженности по занятым местам.</span>
            </div>
          </div>
        </div>
        <div class="legend" id="legendRent" hidden>
          <div class="legend-items">
            <div class="legend-item">
              <span class="legend-color" style="background: #fef3c7;"></span>
              <span class="legend-label" id="rentLegendLow">Низкая ставка</span>
            </div>
            <div class="legend-item">
              <span class="legend-color" style="background: #fbbf24;"></span>
              <span class="legend-label" id="rentLegendMid">Средняя ставка</span>
            </div>
            <div class="legend-item">
              <span class="legend-color" style="background: #f97316;"></span>
              <span class="legend-label" id="rentLegendHigh">Повышенная ставка</span>
            </div>
            <div class="legend-item">
              <span class="legend-color" style="background: #dc2626;"></span>
              <span class="legend-label" id="rentLegendTop">Высокая ставка</span>
            </div>
            <div class="legend-item">
              <span class="legend-color legend-rate-none"></span>
              <span class="legend-label">Ставка не задана</span>
            </div>
            <div class="legend-item">
              <span class="legend-color legend-vacant"></span>
              <span class="legend-label">Свободно</span>
            </div>
            <div class="legend-item">
              <span class="legend-color legend-unlinked"></span>
              <span class="legend-label">Разметка без привязки</span>
            </div>
            <div class="legend-item legend-item--note">
              <span class="legend-note" id="rentLegendNote">Слой показывает относительную ставку по занятым местам.</span>
            </div>
          </div>
        </div>

        <div id="viewerRoot">
          <div class="stage" id="stage">
            <div class="canvasWrap" id="canvasWrap">
              <canvas id="canvas"></canvas>
              <svg id="shapesSvg" class="shapesSvg" aria-hidden="true"></svg>
              <div id="drawBox" class="drawBox" aria-hidden="true"></div>

              <div id="overlay" class="overlay" aria-label="map-overlay">
                <div id="handlesLayer" class="handlesLayer" aria-hidden="true"></div>
              </div>
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
        const PDF_URL    = @json($pdfUrl,    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        const HIT_URL    = @json($hitUrl,    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        const SHAPES_URL = @json($shapesUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        const SPACE_URL  = @json($spaceUrl,  JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        const SPACES_URL = @json($spacesUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        const REVIEW_DECISION_URL = @json($reviewDecisionUrl ?? '', JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        const CAN_EDIT  = @json((bool) $canEdit);
        const INITIAL_MAP_MODE = @json($mapMode ?? 'map');
        const MARKET_ID = @json((int) $marketId);
        const MAP_PAGE = @json((int) ($mapPage ?? 1));
        const MAP_VERSION = @json((int) ($mapVersion ?? 1));
        const FOCUS_SPACE_ID = @json(isset($marketSpaceId) ? (int) $marketSpaceId : null);
        const FOCUS_SHAPE = @json($focusShape ?? null, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        const INITIAL_REVIEW_PROGRESS = @json($reviewProgress ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        const csrfMeta = document.querySelector('meta[name="csrf-token"]');
        const CSRF_TOKEN = csrfMeta ? csrfMeta.getAttribute('content') : '';

        const viewerRoot = document.getElementById('viewerRoot');

        const zoomInBtn = document.getElementById('zoomIn');
        const zoomOutBtn = document.getElementById('zoomOut');
        const zoomResetBtn = document.getElementById('zoomReset');
        const fitWidthBtn = document.getElementById('fitWidth');
        const scaleLabel = document.getElementById('scaleLabel');
        const layerDebtBtn = document.getElementById('layerDebt');
        const layerRentBtn = document.getElementById('layerRent');
        const legendDebt = document.getElementById('legendDebt');
        const legendRent = document.getElementById('legendRent');
        const rentLegendLow = document.getElementById('rentLegendLow');
        const rentLegendMid = document.getElementById('rentLegendMid');
        const rentLegendHigh = document.getElementById('rentLegendHigh');
        const rentLegendTop = document.getElementById('rentLegendTop');
        const rentLegendNote = document.getElementById('rentLegendNote');

        const popover = document.getElementById('popover');
        const popoverBody = document.getElementById('popoverBody');
        const popoverClose = document.getElementById('popoverClose');

        const toggleEditBtn = document.getElementById('toggleEdit');
        const editToolbarRow = toggleEditBtn?.closest('.toolbar-row') || null;
        const mapEditGroup = toggleEditBtn?.closest('.toolbar-group') || null;
        const toolSelectBtn = document.getElementById('toolSelect');
        const toolRectBtn = document.getElementById('toolRect');
        const toolPolyBtn = document.getElementById('toolPoly');
        const scenarioMapBtn = document.getElementById('scenarioMap');
        const scenarioReviewBtn = document.getElementById('scenarioReview');
        const reviewToolbarRow = document.getElementById('reviewToolbarRow');
        const reviewNotFoundBtn = document.getElementById('reviewNotFound');
        const mapLoadProgress = document.getElementById('mapLoadProgress');
        const mapLoadProgressFill = document.getElementById('mapLoadProgressFill');
        const mapLoadProgressText = document.getElementById('mapLoadProgressText');
        const mapLoadProgressPercent = document.getElementById('mapLoadProgressPercent');
        const reviewProgress = document.getElementById('reviewProgress');
        const reviewProgressFill = document.getElementById('reviewProgressFill');
        const reviewProgressText = document.getElementById('reviewProgressText');
        const reviewSummary = document.getElementById('reviewSummary');

        const spacePicker = document.getElementById('spacePicker');
        const spaceSearchInput = document.getElementById('spaceSearch');
        const spaceDropdown = document.getElementById('spaceDropdown');
        const spaceChosenPill = document.getElementById('spaceChosenPill');

        const editHint = document.getElementById('editHint');

        const LS_KEY_CHOSEN = 'mp.marketMap.market_' + String(MARKET_ID) + '.chosenSpace';
        const LS_KEY_LAYER = 'mp.marketMap.market_' + String(MARKET_ID) + '.layer';

        let chosenSpace = null;
        let isEditMode = false;
        let currentScenario = INITIAL_MAP_MODE === 'review' ? 'review' : 'map';
        let reviewProgressState = INITIAL_REVIEW_PROGRESS || {};
        let currentLayer = 'debt';
        let redrawShapesRef = null;
        let searchResults = [];
        let searchIndex = -1;
        let searchTimer = null;
        let searchController = null;
        let mapLoadProgressHideTimer = null;

        function escapeHtml(s) {
          return String(s ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
        }

        function disablePdfJsControls() {
          if (zoomInBtn) zoomInBtn.disabled = true;
          if (zoomOutBtn) zoomOutBtn.disabled = true;
          if (zoomResetBtn) zoomResetBtn.disabled = true;
          if (fitWidthBtn) fitWidthBtn.disabled = true;
          if (scaleLabel) scaleLabel.textContent = 'Масштаб: (встроенный просмотр)';
        }

        function nextUiFrame() {
          return new Promise((resolve) => {
            requestAnimationFrame(() => resolve());
          });
        }

        function syncLayerButtonHelp() {
          const debtHelp = (debtLegendNote?.textContent || 'Слой показывает статус задолженности по занятым местам.').trim();
          const rentHelp = (rentLegendNote?.textContent || 'Слой показывает относительную ставку по занятым местам.').trim();
          if (layerDebtBtn && debtHelp) {
            layerDebtBtn.title = debtHelp;
            layerDebtBtn.setAttribute('aria-label', debtHelp);
          }
          if (layerRentBtn && rentHelp) {
            layerRentBtn.title = rentHelp;
            layerRentBtn.setAttribute('aria-label', rentHelp);
          }
        }

        function setMapLoadProgress(percent, text, state = 'loading') {
          if (!mapLoadProgress || !mapLoadProgressFill || !mapLoadProgressText) {
            return;
          }

          if (mapLoadProgressHideTimer) {
            clearTimeout(mapLoadProgressHideTimer);
            mapLoadProgressHideTimer = null;
          }

          const safePercent = Math.max(0, Math.min(100, Number(percent) || 0));
          const isFinalizing = state === 'rendering' || (state === 'loading' && safePercent >= 68);
          if (isFinalizing && state === 'loading') {
            text = 'PDF почти загружен, подготавливаем карту…';
          }
          mapLoadProgress.dataset.state = state;
          if (isFinalizing) {
            mapLoadProgress.dataset.phase = 'finalizing';
          } else {
            delete mapLoadProgress.dataset.phase;
          }
          mapLoadProgress.style.display = 'flex';
          mapLoadProgressFill.style.width = safePercent + '%';
          mapLoadProgressText.textContent = text;
          if (mapLoadProgressPercent) {
            mapLoadProgressPercent.textContent = isFinalizing
              ? 'Подготовка…'
              : safePercent + '%';
          }
        }

        function hideMapLoadProgress() {
          if (!mapLoadProgress) {
            return;
          }

          if (mapLoadProgressHideTimer) {
            clearTimeout(mapLoadProgressHideTimer);
            mapLoadProgressHideTimer = null;
          }

          mapLoadProgress.style.display = 'none';
          mapLoadProgressFill && (mapLoadProgressFill.style.width = '0%');
          if (mapLoadProgressPercent) {
            mapLoadProgressPercent.textContent = '0%';
          }
          delete mapLoadProgress.dataset.state;
          delete mapLoadProgress.dataset.phase;
        }

        function completeMapLoadProgress(text = 'Карта загружена') {
          setMapLoadProgress(100, text, 'done');
          mapLoadProgressHideTimer = setTimeout(() => {
            hideMapLoadProgress();
          }, 1200);
        }

        function fallbackToIframe(reason) {
          console.warn('PDF.js fallback:', reason);
          setMapLoadProgress(100, 'Встроенный просмотр PDF', 'fallback');
          disablePdfJsControls();
          if (viewerRoot) {
            viewerRoot.innerHTML = '<iframe class="iframe" src="' + PDF_URL + '" loading="lazy"></iframe>';
          }
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
          if (!popover) return;
          popover.classList.remove('show');
        }

        function showPopoverAt(clientX, clientY, html) {
          if (!popover || !popoverBody) return;

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

        popoverClose?.addEventListener('click', hidePopover);
        window.addEventListener('keydown', (e) => { if (e.key === 'Escape') hidePopover(); });
        window.addEventListener('click', (e) => {
          if (!popover?.classList.contains('show')) return;
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
          return chosenSpace?.id ?? null;
        }

        function normalizeChosenSpace(item) {
          const id = Number(item?.id);
          if (!Number.isFinite(id) || id <= 0) return null;
          return {
            id: Math.trunc(id),
            number: item?.number ? String(item.number) : '',
            code: item?.code ? String(item.code) : '',
            tenantName: item?.tenant?.name ? String(item.tenant.name) : (item?.tenantName ? String(item.tenantName) : null),
            reviewStatus: item?.review_status ? String(item.review_status) : '',
            reviewStatusLabel: item?.review_status_label ? String(item.review_status_label) : '',
          };
        }

        function formatSpaceLabel(space) {
          if (!space) return '—';
          const numberLabel = String(space.number ?? '').trim();
          const codeLabel = String(space.code ?? '').trim();
          const label = numberLabel || codeLabel || '—';
          return '№' + label;
        }

        function formatSpaceDropdownLabel(space) {
          const numberLabel = String(space.number ?? '').trim();
          const codeLabel = String(space.code ?? '').trim();
          const label = numberLabel || codeLabel || '—';
          const tenant = space.tenantName ? space.tenantName : '—';
          return '№' + label + ' — ' + tenant + ' (ID ' + String(space.id) + ')';
        }

        function formatMoneyRu(value) {
          const num = Number(value);
          if (!Number.isFinite(num)) return '';
          return new Intl.NumberFormat('ru-RU', {
            minimumFractionDigits: 0,
            maximumFractionDigits: 2,
          }).format(num) + ' ₽';
        }

        function rentRateUnitLabel(unit) {
          const key = String(unit || '').trim();
          if (!key) return '';

          const map = {
            per_sqm_month: 'за м² в месяц',
            per_space_month: 'за место в месяц',
          };

          return map[key] || key;
        }

        function normalizeLayerMode(value) {
          return value === 'rent' ? 'rent' : 'debt';
        }

        function loadLayerModeFromLS() {
          try {
            currentLayer = normalizeLayerMode(localStorage.getItem(LS_KEY_LAYER));
          } catch {
            currentLayer = 'debt';
          }
        }

        function saveLayerModeToLS() {
          try {
            localStorage.setItem(LS_KEY_LAYER, currentLayer);
          } catch {}
        }

        function formatLegendRateValue(value) {
          const num = Number(value);
          if (!Number.isFinite(num)) return '—';
          return new Intl.NumberFormat('ru-RU', {
            minimumFractionDigits: 0,
            maximumFractionDigits: 2,
          }).format(num);
        }

        function buildRentLayerStats(items) {
          const rates = [];
          const units = new Set();

          for (const item of items) {
            const rate = Number(item?.space_rent_rate_value);
            const hasSpace = !!item?.market_space_id;
            const hasTenant = hasSpace && item?.space_tenant_id !== null && item?.space_tenant_id !== undefined;

            if (!hasTenant || !Number.isFinite(rate) || rate <= 0) continue;

            rates.push(rate);

            const unit = String(item?.space_rent_rate_unit || '').trim();
            if (unit) units.add(unit);
          }

          rates.sort((a, b) => a - b);

          if (!rates.length) {
            return null;
          }

          const quantile = (q) => {
            if (rates.length === 1) return rates[0];
            const pos = (rates.length - 1) * q;
            const base = Math.floor(pos);
            const rest = pos - base;
            const left = rates[base];
            const right = rates[Math.min(base + 1, rates.length - 1)];
            return left + ((right - left) * rest);
          };

          return {
            lowMax: quantile(0.25),
            midMax: quantile(0.5),
            highMax: quantile(0.75),
            unit: units.size === 1 ? Array.from(units)[0] : '',
            mixedUnits: units.size > 1,
          };
        }

        function getRentRateBand(rateValue, stats) {
          const rate = Number(rateValue);
          if (!Number.isFinite(rate) || rate <= 0 || !stats) return 'none';
          if (rate <= stats.lowMax) return 'low';
          if (rate <= stats.midMax) return 'mid';
          if (rate <= stats.highMax) return 'high';
          return 'top';
        }

        function updateLegendVisibility() {
          if (legendDebt) legendDebt.hidden = currentLayer !== 'debt';
          if (legendRent) legendRent.hidden = currentLayer !== 'rent';
          layerDebtBtn?.classList.toggle('is-active', currentLayer === 'debt');
          layerRentBtn?.classList.toggle('is-active', currentLayer === 'rent');
          syncLayerButtonHelp();
        }

        function updateRentLegend(items) {
          const stats = buildRentLayerStats(items);
          const unitText = stats?.unit ? (' (' + rentRateUnitLabel(stats.unit) + ')') : '';

          if (rentLegendLow) {
            rentLegendLow.textContent = stats
              ? ('Низкая ставка до ' + formatLegendRateValue(stats.lowMax) + unitText)
              : 'Низкая ставка';
          }

          if (rentLegendMid) {
            rentLegendMid.textContent = stats
              ? ('Средняя ставка до ' + formatLegendRateValue(stats.midMax) + unitText)
              : 'Средняя ставка';
          }

          if (rentLegendHigh) {
            rentLegendHigh.textContent = stats
              ? ('Повышенная ставка до ' + formatLegendRateValue(stats.highMax) + unitText)
              : 'Повышенная ставка';
          }

          if (rentLegendTop) {
            rentLegendTop.textContent = stats
              ? ('Высокая ставка от ' + formatLegendRateValue(stats.highMax) + unitText)
              : 'Высокая ставка';
          }

          if (rentLegendNote) {
            if (!stats) {
              rentLegendNote.textContent = 'Нет данных по ставке для занятых мест.';
            } else if (stats.mixedUnits) {
              rentLegendNote.textContent = 'Слой показывает относительную ставку; единицы измерения различаются.';
            } else if (stats.unit) {
              rentLegendNote.textContent = 'Слой показывает относительную ставку по занятым местам (' + rentRateUnitLabel(stats.unit) + ').';
            } else {
              rentLegendNote.textContent = 'Слой показывает относительную ставку по занятым местам.';
            }
          }
          syncLayerButtonHelp();
        }

        function setLayerMode(mode) {
          currentLayer = normalizeLayerMode(mode);
          saveLayerModeToLS();
          updateLegendVisibility();
          if (typeof redrawShapesRef === 'function') {
            redrawShapesRef();
          }
        }


        function isReviewMode() {
          return currentScenario === 'review';
        }

        function isSelectionToolbarVisible() {
          return isReviewMode() || isEditMode;
        }

        function updateReviewProgress(nextProgress = null) {
          if (nextProgress && typeof nextProgress === 'object') {
            reviewProgressState = nextProgress;
          }

          if (!reviewProgress || !reviewProgressFill || !reviewProgressText || !reviewSummary) {
            return;
          }

          const total = Number(reviewProgressState?.total || 0);
          const reviewed = Number(reviewProgressState?.reviewed || 0);
          const percent = Number(reviewProgressState?.percent || 0);
          const counts = reviewProgressState?.counts || {};
          const labels = reviewProgressState?.labels || {};

          reviewProgress.style.display = isReviewMode() ? 'inline-flex' : 'none';
          reviewProgressFill.style.width = Math.max(0, Math.min(100, percent)) + '%';
          reviewProgressText.textContent = reviewed + ' / ' + total + ' / ' + percent + '%';

          if (!isReviewMode()) {
            reviewSummary.style.display = 'none';
            reviewSummary.innerHTML = '';
            return;
          }

          const statuses = Object.entries(counts);
          if (!statuses.length) {
            reviewSummary.style.display = 'none';
            reviewSummary.innerHTML = '';
            return;
          }

          reviewSummary.style.display = 'flex';
          reviewSummary.innerHTML = statuses
            .map(([status, count]) => '<span class="pill">' + escapeHtml(labels[status] || status) + ': ' + escapeHtml(String(count)) + '</span>')
            .join('');
        }

        function updateScenarioUi() {
          const reviewMode = isReviewMode();

          scenarioMapBtn?.classList.toggle('is-active', !reviewMode);
          scenarioReviewBtn?.classList.toggle('is-active', reviewMode);

          if (editToolbarRow) {
            editToolbarRow.style.display = 'flex';
          }

          if (mapEditGroup) {
            mapEditGroup.style.display = reviewMode ? 'none' : 'inline-flex';
          }

          if (reviewToolbarRow) {
            reviewToolbarRow.style.display = 'flex';
          }

          if (spacePicker) {
            spacePicker.style.display = isSelectionToolbarVisible() ? 'inline-flex' : 'none';
          }

          if (reviewNotFoundBtn) {
            reviewNotFoundBtn.style.display = reviewMode ? 'inline-flex' : 'none';
            reviewNotFoundBtn.disabled = !chosenSpace;
          }

          if (editHint) {
            editHint.style.display = isEditMode && !reviewMode ? 'inline-flex' : 'none';
          }

          if (reviewMode && isEditMode) {
            isEditMode = false;
          }

          updateChosenPill();
          updateReviewProgress();
        }

        function setScenario(mode) {
          currentScenario = mode === 'review' ? 'review' : 'map';
          hidePopover();
          updateScenarioUi();

          const url = new URL(window.location.href);
          url.searchParams.set('mode', currentScenario);
          window.history.replaceState({}, '', url.toString());
        }

        function updateChosenPill() {
          if (!spaceChosenPill) return;
          if (!chosenSpace || !isSelectionToolbarVisible()) {
            spaceChosenPill.style.display = 'none';
            spaceChosenPill.innerHTML = '';
            return;
          }
          const label = formatSpaceLabel(chosenSpace);
          const tenant = chosenSpace.tenantName ? (' • арендатор ' + chosenSpace.tenantName) : ' • арендатор —';
          const review = chosenSpace.reviewStatusLabel ? (' / ' + chosenSpace.reviewStatusLabel) : '';
          spaceChosenPill.style.display = 'inline-flex';
          spaceChosenPill.innerHTML =
            'Выбрано: ' + escapeHtml(label) + ' (ID ' + escapeHtml(String(chosenSpace.id)) + ')' +
            escapeHtml(tenant) +
            escapeHtml(review) +
            ' <button type="button" class="spacePillButton" data-action="clear-chosen" aria-label="Сбросить">×</button>';
        }

        function saveChosenSpaceToLS() {
          try {
            if (!chosenSpace) {
              localStorage.removeItem(LS_KEY_CHOSEN);
              return;
            }
            const payload = {
              id: chosenSpace.id,
              number: chosenSpace.number,
              code: chosenSpace.code,
              tenantName: chosenSpace.tenantName,
              reviewStatus: chosenSpace.reviewStatus,
              reviewStatusLabel: chosenSpace.reviewStatusLabel,
            };
            localStorage.setItem(LS_KEY_CHOSEN, JSON.stringify(payload));
          } catch {}
        }

        function setChosenSpace(space, opts = {}) {
          const next = space ? {
            id: space.id,
            number: space.number || '',
            code: space.code || '',
            tenantName: space.tenantName ?? null,
            reviewStatus: space.reviewStatus ?? '',
            reviewStatusLabel: space.reviewStatusLabel ?? '',
          } : null;
          chosenSpace = next;
          updateChosenPill();
          saveChosenSpaceToLS();
          if (spaceSearchInput && next) {
            spaceSearchInput.value = '';
          }
          if (reviewNotFoundBtn) {
            reviewNotFoundBtn.disabled = !next;
          }
          if (opts.announce && next) {
            toast('Выбрано место ' + formatSpaceLabel(next) + ' (ID ' + String(next.id) + ')');
          }
        }

        function closeSpaceDropdown() {
          if (!spaceDropdown) return;
          spaceDropdown.style.display = 'none';
          spaceDropdown.innerHTML = '';
          searchResults = [];
          searchIndex = -1;
        }

        function renderSpaceDropdown(items) {
          if (!spaceDropdown) return;
          spaceDropdown.innerHTML = '';
          searchResults = items;
          searchIndex = -1;

          if (!items.length) {
            const empty = document.createElement('div');
            empty.className = 'spaceDropdownEmpty';
            empty.textContent = 'Ничего не найдено';
            spaceDropdown.appendChild(empty);
            spaceDropdown.style.display = 'block';
            return;
          }

          items.forEach((item, idx) => {
            const row = document.createElement('div');
            row.className = 'spaceDropdownItem';
            row.setAttribute('role', 'option');
            row.dataset.index = String(idx);
            row.textContent = formatSpaceDropdownLabel(item);
            row.addEventListener('click', () => {
              setChosenSpace(item, { announce: true });
              closeSpaceDropdown();
            });
            spaceDropdown.appendChild(row);
          });
          spaceDropdown.style.display = 'block';
        }

        function updateDropdownActive() {
          if (!spaceDropdown) return;
          const items = Array.from(spaceDropdown.querySelectorAll('.spaceDropdownItem'));
          items.forEach((el, idx) => {
            if (idx === searchIndex) {
              el.classList.add('active');
              el.scrollIntoView({ block: 'nearest' });
            } else {
              el.classList.remove('active');
            }
          });
        }

        function scheduleSpaceSearch() {
          if (searchTimer) clearTimeout(searchTimer);
          searchTimer = setTimeout(() => runSpaceSearch(), 300);
        }

        async function runSpaceSearch() {
          if (!spaceSearchInput) return;
          const value = String(spaceSearchInput.value || '').trim();

          if (!value) {
            closeSpaceDropdown();
            return;
          }

          if (!SPACES_URL) {
            toast('Не задан SPACES_URL');
            closeSpaceDropdown();
            return;
          }

          if (searchController) searchController.abort();
          searchController = new AbortController();

          try {
            const url = new URL(SPACES_URL, window.location.origin);
            url.searchParams.set('q', value);
            url.searchParams.set('limit', '15');

            const res = await apiFetch(url.toString(), {
              headers: { 'Accept': 'application/json' },
              signal: searchController.signal,
            });
            const json = await res.json();

            if (!res.ok || !json || json.ok !== true) {
              toast('Ошибка поиска');
              closeSpaceDropdown();
              return;
            }

            const items = Array.isArray(json.items) ? json.items : [];
            const normalized = items.map((item) => normalizeChosenSpace(item)).filter(Boolean);
            renderSpaceDropdown(normalized);
          } catch (e) {
            if (e?.name === 'AbortError') return;
            console.error(e);
            toast('Ошибка поиска');
            closeSpaceDropdown();
          }
        }

        async function refreshChosenSpaceFromServer() {
          if (!chosenSpace || !SPACE_URL) return;
          try {
            const url = new URL(SPACE_URL, window.location.origin);
            url.searchParams.set('id', String(chosenSpace.id));
            const res = await apiFetch(url.toString(), { headers: { 'Accept': 'application/json' } });
            const json = await res.json();
            if (!res.ok || !json || json.ok !== true || !json.found) {
              setChosenSpace(null, { announce: false });
              return;
            }
            const normalized = normalizeChosenSpace(json.item || null);
            if (!normalized) {
              setChosenSpace(null, { announce: false });
              return;
            }
            setChosenSpace(normalized, { announce: false });
          } catch (e) {
            console.error(e);
            setChosenSpace(null, { announce: false });
          }
        }

        if (CAN_EDIT) {
          try {
            const saved = localStorage.getItem(LS_KEY_CHOSEN);
            if (saved) {
              const parsed = JSON.parse(saved);
              const restored = normalizeChosenSpace(parsed);
              if (restored) setChosenSpace(restored, { announce: false });
            }
          } catch {}
        }

        loadLayerModeFromLS();
        updateLegendVisibility();

        function startPdfJs(pdfjsLib, workerSrc) {
          const stage = document.getElementById('stage');
          const canvas = document.getElementById('canvas');
          const overlay = document.getElementById('overlay');
          const shapesSvg = document.getElementById('shapesSvg');
          const drawBox = document.getElementById('drawBox');
          const handlesLayer = document.getElementById('handlesLayer');

          if (!stage || !canvas || !overlay) {
            fallbackToIframe('missing DOM nodes');
            return;
          }

          const ctx = canvas.getContext('2d');
          if (!ctx) {
            fallbackToIframe('no 2d context');
            return;
          }

          pdfjsLib.GlobalWorkerOptions.workerSrc = workerSrc;

          let page = null;
          let scale = 1.0;
          let currentViewport = null;
          let shapes = [];
          let lastHit = null;

          let editMode = false;
          let tool = 'select';

          let selectedShapeId = null;
          let activeVertexIndex = null;
          let draggingVertex = null;
          let draggingVertexMoved = false;

          let polyDrawing = false;
          let polyDraft = [];
          let bootstrappingMap = true;

          const SHAPES_BASE = String(SHAPES_URL || '').replace(/\/$/, '');

          function normalizeBbox(raw) {
            if (!raw) return null;
            const x1 = Number(raw.x1 ?? raw.bbox_x1 ?? raw.bboxX1);
            const y1 = Number(raw.y1 ?? raw.bbox_y1 ?? raw.bboxY1);
            const x2 = Number(raw.x2 ?? raw.bbox_x2 ?? raw.bboxX2);
            const y2 = Number(raw.y2 ?? raw.bbox_y2 ?? raw.bboxY2);
            if (![x1, y1, x2, y2].every((v) => Number.isFinite(v))) return null;
            return { x1, y1, x2, y2 };
          }

          function bboxFromPolygon(poly) {
            if (!Array.isArray(poly) || poly.length < 3) return null;
            let minX = Infinity;
            let minY = Infinity;
            let maxX = -Infinity;
            let maxY = -Infinity;
            for (const p of poly) {
              const x = Number((p && (p.x ?? p[0])) ?? NaN);
              const y = Number((p && (p.y ?? p[1])) ?? NaN);
              if (!Number.isFinite(x) || !Number.isFinite(y)) continue;
              minX = Math.min(minX, x);
              minY = Math.min(minY, y);
              maxX = Math.max(maxX, x);
              maxY = Math.max(maxY, y);
            }
            if (![minX, minY, maxX, maxY].every((v) => Number.isFinite(v))) return null;
            return { x1: minX, y1: minY, x2: maxX, y2: maxY };
          }

          function resolveShapeBbox(shape) {
            if (!shape) return null;
            const bbox = normalizeBbox(shape);
            if (bbox) return bbox;
            return bboxFromPolygon(shape.polygon);
          }

          function setScaleLabel() {
            if (scaleLabel) scaleLabel.textContent = 'Масштаб: ' + Math.round(scale * 100) + '%';
          }

          function approximateTextWidth(text, fontSize) {
            return String(text || '').length * fontSize * 0.58;
          }

          function splitLabelToTwoLines(text) {
            const value = String(text || '').trim();
            if (!value) return [value];

            const words = value.split(/\s+/).filter(Boolean);
            if (words.length < 2) return [value];

            let bestLines = [value];
            let bestScore = Infinity;

            for (let i = 1; i < words.length; i++) {
              const line1 = words.slice(0, i).join(' ');
              const line2 = words.slice(i).join(' ');
              const score = Math.abs(line1.length - line2.length);

              if (score < bestScore) {
                bestScore = score;
                bestLines = [line1, line2];
              }
            }

            return bestLines;
          }

          function resolveShapeLabelSpec(shape, boxW, boxH) {
            const rawCandidates = [
              shape?.space_display_name,
              shape?.space_number,
              shape?.space_code,
            ];

            const seen = new Set();
            const candidates = rawCandidates
              .map((value) => String(value || '').trim())
              .filter((value) => {
                if (!value || seen.has(value)) return false;
                seen.add(value);
                return true;
              });

            if (!candidates.length) return null;

            const fontSizes = [11, 10, 9, 8];
            const pad = 6;

            for (const candidate of candidates) {
              const lineOptions = [[candidate]];
              const splitLines = splitLabelToTwoLines(candidate);
              if (splitLines.length > 1) {
                lineOptions.push(splitLines);
              }

              for (const lines of lineOptions) {
                for (const fontSize of fontSizes) {
                  const lineHeight = Math.ceil(fontSize * 1.18);
                  const maxLineWidth = Math.max(...lines.map((line) => approximateTextWidth(line, fontSize)));
                  const totalHeight = lines.length * lineHeight;

                  if (boxW >= maxLineWidth + pad * 2 && boxH >= totalHeight + pad * 2) {
                    return { lines, fontSize, lineHeight };
                  }
                }
              }
            }

            return null;
          }

          function setHint(text) {
            if (!editHint) return;
            editHint.textContent = text;
          }

          function clearHandles() {
            if (!handlesLayer) return;
            handlesLayer.innerHTML = '';
            activeVertexIndex = null;
          }

          function findShapeById(id) {
            const n = Number(id);
            if (!Number.isFinite(n) || n <= 0) return null;
            return shapes.find(s => Number(s.id) === n) || null;
          }

          function setSelectedShape(id) {
            const n = id ? Number(id) : 0;
            selectedShapeId = (Number.isFinite(n) && n > 0) ? Math.trunc(n) : null;
            activeVertexIndex = null;
            redrawShapes();
            renderHandles();
          }

          function setTool(next) {
            tool = next;

            if (toolSelectBtn) toolSelectBtn.style.background = (tool === 'select') ? 'rgba(120,120,120,.18)' : 'rgba(120,120,120,.10)';
            if (toolRectBtn) toolRectBtn.style.background = (tool === 'rect') ? 'rgba(120,120,120,.18)' : 'rgba(120,120,120,.10)';
            if (toolPolyBtn) toolPolyBtn.style.background = (tool === 'poly') ? 'rgba(120,120,120,.18)' : 'rgba(120,120,120,.10)';

            if (tool !== 'select') {
              setSelectedShape(null);
              hidePopover();
            }

            if (tool === 'poly') {
              moved = false;
              isDown = false;
              overlay.classList.remove('grabbing');
              stage.classList.remove('grabbing');

              polyDrawing = true;
              polyDraft = [];
              setHint('Полигон: клик — точка • Enter/клик по первой — сохранить • Backspace — назад • Esc — отмена');
              toast('Полигон: добавляй точки кликом');
            } else {
              polyDrawing = false;
              polyDraft = [];
              if (tool === 'rect') {
                setHint('Прямоугольник: Shift+drag — создать • клик — карточка');
              } else {
                setHint('Редактировать: клик — выбрать • тащи точки • Alt+клик — вставить вершину • Delete — удалить');
              }
              redrawShapes();
            }
          }

          async function loadShapes() {
            try {
              const url = new URL(SHAPES_URL, window.location.origin);
              url.searchParams.set('page', String(MAP_PAGE || 1));
              url.searchParams.set('version', String(MAP_VERSION || 1));

              const res = await apiFetch(url.toString(), { headers: { 'Accept': 'application/json' } });
              const json = await res.json();

              shapes = (json && json.ok === true && Array.isArray(json.items)) ? json.items : [];
              updateRentLegend(shapes);
            } catch (e) {
              console.error(e);
              shapes = [];
              updateRentLegend([]);
            }
          }

          function redrawShapes() {
            if (!shapesSvg || !currentViewport) return;

            shapesSvg.setAttribute('width', String(canvas.width));
            shapesSvg.setAttribute('height', String(canvas.height));
            shapesSvg.setAttribute('viewBox', '0 0 ' + canvas.width + ' ' + canvas.height);

            const parts = [];
            parts.push(
              '<defs>' +
              '<pattern id="unlinkedHatch" patternUnits="userSpaceOnUse" width="8" height="8" patternTransform="rotate(45)">' +
              '<line x1="0" y1="0" x2="0" y2="8" stroke="#94a3b8" stroke-width="2" stroke-opacity="0.85"></line>' +
              '</pattern>' +
              '</defs>'
            );
            const BORDER_COLOR = '#064e3b';
            const BORDER_WIDTH_BASE = 2.5;
            const rentLayerStats = buildRentLayerStats(shapes);
            const rentRateColors = {
              low: '#fef3c7',
              mid: '#fbbf24',
              high: '#f97316',
              top: '#dc2626',
              none: '#cbd5e1',
            };

            const selected = selectedShapeId ? findShapeById(selectedShapeId) : null;

            for (const s of shapes) {
              const poly = Array.isArray(s.polygon) ? s.polygon : [];
              if (poly.length < 3) continue;

              const viewportPoints = poly.map((p) => {
                const x = (p && (p.x ?? p[0])) ?? null;
                const y = (p && (p.y ?? p[1])) ?? null;
                if (x === null || y === null) return null;

                const v = currentViewport.convertToViewportPoint(Number(x), Number(y));
                const vx = Array.isArray(v) ? v[0] : null;
                const vy = Array.isArray(v) ? v[1] : null;

                if (vx === null || vy === null) return null;
                return { x: Number(vx), y: Number(vy) };
              }).filter(Boolean);

              const pts = viewportPoints
                .map((p) => Number(p.x).toFixed(2) + ',' + Number(p.y).toFixed(2))
                .join(' ');

              if (!pts) continue;

              const metaValue = s.meta ?? null;
              let meta = null;
              if (metaValue && typeof metaValue === 'object') {
                meta = metaValue;
              } else if (typeof metaValue === 'string') {
                try {
                  meta = JSON.parse(metaValue);
                } catch (e) {
                  meta = null;
                }
              }

              const isLinked = !!s.market_space_id;
              const isImportedOverlay = Boolean(meta && meta.import_source);
              const isNormalLinked = isLinked && !isImportedOverlay;

              // Проверяем состояние места
              const hasSpace = isLinked;
              const hasTenant = hasSpace && (s.space_tenant_id !== null && s.space_tenant_id !== undefined);
              const debtStatus = typeof s.debt_status === 'string' ? s.debt_status : null;
              const rentRateBand = getRentRateBand(s.space_rent_rate_value, rentLayerStats);
              
              // Цвета для debt status
              const debtColors = {
                green: '#22c55e',
                pending: '#22c55e',
                orange: '#f59e0b',
                red: '#dc2626',
                gray: '#94a3b8',
              };

              // Определяем тип отрисовки
              let fillStyle = 'normal'; // normal, debt, rent, rent-missing, vacant, unlinked
              let debtFill = null;
              let rentFill = null;
              
              if (!hasSpace) {
                // Shape без market_space_id — разметка без привязки
                fillStyle = 'unlinked';
              } else if (!hasTenant) {
                // Место есть, но арендатора нет — свободно
                fillStyle = 'vacant';
              } else if (currentLayer === 'rent') {
                rentFill = rentRateColors[rentRateBand] || rentRateColors.none;
                fillStyle = rentRateBand === 'none' ? 'rent-missing' : 'rent';
              } else if (debtStatus && debtColors[debtStatus]) {
                // Есть арендатор и debt_status — используем debt цвет
                debtFill = debtColors[debtStatus];
                fillStyle = 'debt';
              } else {
                // Место с арендатором, но нет debt_status — normal
                fillStyle = 'normal';
              }
              
              // Применяем стили
              let fill = null;
              let stroke = BORDER_COLOR;
              let strokeDasharray = null;
              let fo = 0.12;
              
              if (fillStyle === 'unlinked') {
                // Разметка без привязки: штриховка 45 градусов и нейтральная обводка
                fill = 'url(#unlinkedHatch)';
                stroke = '#94a3b8';
                fo = 1;
              } else if (fillStyle === 'vacant') {
                // Свободно: плотная светло-серая заливка, чтобы не просвечивала подложка
                fill = '#e5e7eb';
                stroke = '#94a3b8';
                fo = 0.92;
              } else if (fillStyle === 'debt') {
                // Debt status: закрашиваем цветом долга
                fill = debtFill;
                stroke = BORDER_COLOR;
                fo = 1;
              } else if (fillStyle === 'rent' || fillStyle === 'rent-missing') {
                // Слой ставок: чем выше ставка, тем теплее цвет
                fill = rentFill;
                stroke = BORDER_COLOR;
                fo = 0.96;
              } else {
                // Normal: обычная заливка
                fill = s.fill_color || '#00A3FF';
                stroke = BORDER_COLOR;
                fo = typeof s.fill_opacity === 'number' ? s.fill_opacity : 0.12;
              }
              
              const sw = BORDER_WIDTH_BASE;

              const isSel = selected && Number(selected.id) === Number(s.id);

              const strokeDashAttr = strokeDasharray ? (' stroke-dasharray="' + strokeDasharray + '"') : '';

              parts.push(
                '<polygon points="' + pts +
                '" fill="' + fill +
                '" fill-opacity="' + (isSel ? Math.min(1, fo + 0.08) : fo) +
                '" stroke="' + stroke +
                '" stroke-opacity="1"' +
                strokeDashAttr +
                ' stroke-width="' + (isSel ? (sw + 1.0) : sw) +
                '"></polygon>'
              );

              if (viewportPoints.length >= 3) {
                let minX = Infinity;
                let minY = Infinity;
                let maxX = -Infinity;
                let maxY = -Infinity;

                for (const p of viewportPoints) {
                  minX = Math.min(minX, p.x);
                  minY = Math.min(minY, p.y);
                  maxX = Math.max(maxX, p.x);
                  maxY = Math.max(maxY, p.y);
                }

                const boxW = maxX - minX;
                const boxH = maxY - minY;
                const labelSpec = resolveShapeLabelSpec(s, boxW, boxH);

                if (labelSpec) {
                  const cx = (minX + maxX) / 2;
                  const cy = (minY + maxY) / 2;
                  const offsetBase = ((labelSpec.lines.length - 1) * labelSpec.lineHeight) / 2;

                  labelSpec.lines.forEach((line, index) => {
                    const y = cy - offsetBase + (index * labelSpec.lineHeight);
                    parts.push(
                      '<text x="' + cx.toFixed(2) +
                      '" y="' + y.toFixed(2) +
                      '" text-anchor="middle" dominant-baseline="middle"' +
                      ' font-size="' + labelSpec.fontSize +
                      '" fill="#0f172a" opacity="0.9">' +
                      escapeHtml(line) +
                      '</text>'
                    );
                  });
                }
              }
            }

            if (polyDrawing && Array.isArray(polyDraft) && polyDraft.length > 0) {
              const pts = polyDraft.map((p) => {
                const v = currentViewport.convertToViewportPoint(Number(p.x), Number(p.y));
                if (!Array.isArray(v)) return null;
                return Number(v[0]).toFixed(2) + ',' + Number(v[1]).toFixed(2);
              }).filter(Boolean).join(' ');

              if (pts) {
                parts.push(
                  '<polyline points="' + pts + '" fill="none" stroke="#00A3FF" stroke-width="2" stroke-dasharray="6 6" opacity="0.95"></polyline>'
                );
                for (const p of polyDraft) {
                  const v = currentViewport.convertToViewportPoint(Number(p.x), Number(p.y));
                  if (!Array.isArray(v)) continue;
                  const cx = Number(v[0]).toFixed(2);
                  const cy = Number(v[1]).toFixed(2);
                  parts.push('<circle cx="' + cx + '" cy="' + cy + '" r="4" fill="#00A3FF" fill-opacity="0.95" stroke="#fff" stroke-width="2"></circle>');
                }
              }
            }

            shapesSvg.innerHTML = parts.join('');
          }

          redrawShapesRef = redrawShapes;

          function getCanvasPointFromClient(clientX, clientY) {
            const rect = canvas.getBoundingClientRect();
            return { x: clientX - rect.left, y: clientY - rect.top };
          }

          function renderHandles() {
            if (!handlesLayer) return;
            handlesLayer.innerHTML = '';

            if (!editMode || tool !== 'select') return;
            if (!selectedShapeId) return;
            if (!currentViewport) return;

            const shape = findShapeById(selectedShapeId);
            if (!shape) return;

            const poly = Array.isArray(shape.polygon) ? shape.polygon : [];
            if (poly.length < 3) return;

            for (let i = 0; i < poly.length; i++) {
              const p = poly[i];
              const x = (p && (p.x ?? p[0])) ?? null;
              const y = (p && (p.y ?? p[1])) ?? null;
              if (x === null || y === null) continue;

              const v = currentViewport.convertToViewportPoint(Number(x), Number(y));
              if (!Array.isArray(v)) continue;

              const el = document.createElement('div');
              el.className = 'handleDot' + (activeVertexIndex === i ? ' active' : '');
              el.style.left = Number(v[0]).toFixed(2) + 'px';
              el.style.top = Number(v[1]).toFixed(2) + 'px';
              el.dataset.shapeId = String(shape.id);
              el.dataset.index = String(i);

              el.addEventListener('mousedown', (e) => {
                e.preventDefault();
                e.stopPropagation();

                const sid = Number(el.dataset.shapeId || 0);
                const idx = Number(el.dataset.index || -1);
                if (!Number.isFinite(sid) || sid <= 0) return;
                if (!Number.isFinite(idx) || idx < 0) return;

                activeVertexIndex = idx;
                draggingVertex = { shapeId: sid, index: idx };
                draggingVertexMoved = false;

                el.classList.add('active');
              });

              handlesLayer.appendChild(el);
            }
          }

          async function centerOnBbox(bbox, opts = {}) {
            if (!bbox || !page || !currentViewport || !stage) return;

            const padding = Number(opts.padding ?? 40);
            const zoomFactor = Number(opts.zoomFactor ?? 1.15);

            const w = Math.max(1, Math.abs(bbox.x2 - bbox.x1));
            const h = Math.max(1, Math.abs(bbox.y2 - bbox.y1));

            const availableW = Math.max(200, stage.clientWidth - padding);
            const availableH = Math.max(200, stage.clientHeight - padding);

            let nextScale = Math.min(availableW / w, availableH / h) * zoomFactor;
            if (!Number.isFinite(nextScale) || nextScale <= 0) {
              nextScale = 1.0;
            }

            nextScale = Math.max(0.2, Math.min(7, nextScale));

            scale = nextScale;
            await render();

            const centerX = (bbox.x1 + bbox.x2) / 2;
            const centerY = (bbox.y1 + bbox.y2) / 2;

            const v = currentViewport.convertToViewportPoint(centerX, centerY);
            if (Array.isArray(v)) {
              stage.scrollLeft = Math.max(0, v[0] - stage.clientWidth / 2);
              stage.scrollTop = Math.max(0, v[1] - stage.clientHeight / 2);
            }
          }

          async function applyInitialFocus() {
            if (!FOCUS_SPACE_ID && !FOCUS_SHAPE) return;

            let targetShape = null;
            let bbox = null;

            if (FOCUS_SHAPE) {
              bbox = normalizeBbox(FOCUS_SHAPE.bbox || FOCUS_SHAPE);
              if (FOCUS_SHAPE.id) {
                targetShape = findShapeById(FOCUS_SHAPE.id);
              }
            }

            if (!bbox && FOCUS_SPACE_ID) {
              targetShape = shapes.find(s => Number(s.market_space_id) === Number(FOCUS_SPACE_ID)) || null;
              bbox = resolveShapeBbox(targetShape);
            }

            if (!bbox) {
              toast('Не удалось найти место на карте');
              return;
            }

            if (targetShape?.id) {
              setSelectedShape(targetShape.id);
            }

            await centerOnBbox(bbox, { zoomFactor: 1.2 });
          }

          async function render() {
            if (!page) return;

            if (bootstrappingMap) {
              setMapLoadProgress(92, 'Отрисовка карты…', 'rendering');
              await nextUiFrame();
            }

            const centerX = stage.scrollLeft + stage.clientWidth / 2;
            const centerY = stage.scrollTop + stage.clientHeight / 2;

            const prevW = canvas.width || 1;
            const prevH = canvas.height || 1;
            const relX = centerX / prevW;
            const relY = centerY / prevH;

            const viewport = page.getViewport({ scale });
            currentViewport = viewport;

            const viewportWidth = Math.floor(viewport.width);
            const viewportHeight = Math.floor(viewport.height);

            canvas.width = viewportWidth;
            canvas.height = viewportHeight;
            canvas.style.width = viewportWidth + 'px';
            canvas.style.height = viewportHeight + 'px';

            if (canvasWrap) {
              canvasWrap.style.width = viewportWidth + 'px';
              canvasWrap.style.height = viewportHeight + 'px';
            }

            ctx.save();
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(0, 0, canvas.width, canvas.height);
            ctx.restore();

            await page.render({ canvasContext: ctx, viewport }).promise;

            stage.scrollLeft = Math.max(0, relX * canvas.width - stage.clientWidth / 2);
            stage.scrollTop  = Math.max(0, relY * canvas.height - stage.clientHeight / 2);

            setScaleLabel();
            redrawShapes();
            renderHandles();

            if (bootstrappingMap) {
              setMapLoadProgress(96, 'Подготовка слоя мест…', 'rendering');
              await nextUiFrame();
            }
          }

          async function fitWidth() {
            if (!page) return;
            const viewport = page.getViewport({ scale: 1.0 });
            const padding = 24;
            const available = Math.max(200, stage.clientWidth - padding);
            scale = available / viewport.width;
            await render();
          }

          async function createShape(pdfPolygon) {
            const msId = getChosenSpaceId();
            const hasTyped = spaceSearchInput && String(spaceSearchInput.value || '').trim().length > 0;
            if (!msId && hasTyped) {
              toast('Выбери место из списка');
            }

            const payload = {
              page: MAP_PAGE || 1,
              version: MAP_VERSION || 1,
              polygon: pdfPolygon,
            };
            if (msId) payload.market_space_id = msId;

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
            renderHandles();
            toast(msId ? ('Разметка сохранена (ID ' + String(msId) + ')') : 'Разметка сохранена без привязки — выбери место и привяжи');
          }

          async function patchShape(shapeId, payload) {
            const id = Number(shapeId);
            if (!Number.isFinite(id) || id <= 0) throw new Error('Bad shape id');

            const url = String(SHAPES_BASE + '/' + String(Math.trunc(id)));

            const res = await apiFetch(url, {
              method: 'PATCH',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify(payload),
            });

            let json = null;
            try { json = await res.json(); } catch { json = null; }

            if (!res.ok || !json || json.ok !== true) {
              const msg = escapeHtml(json?.message || ('HTTP ' + res.status));
              throw new Error(msg);
            }

            return json.item || null;
          }

          async function deleteShape(shapeId) {
            const id = Number(shapeId);
            if (!Number.isFinite(id) || id <= 0) return;

            const ok = confirm('Удалить этот полигон?');
            if (!ok) return;

            const url = String(SHAPES_BASE + '/' + String(Math.trunc(id)));
            const res = await apiFetch(url, { method: 'DELETE' });

            let json = null;
            try { json = await res.json(); } catch { json = null; }

            if (!res.ok || !json || json.ok !== true) {
              const msg = escapeHtml(json?.message || ('HTTP ' + res.status));
              throw new Error(msg);
            }

            if (selectedShapeId === Math.trunc(id)) {
              setSelectedShape(null);
            }

            await loadShapes();
            redrawShapes();
            renderHandles();
            toast('Полигон удалён');
          }

          function distanceSq(ax, ay, bx, by) {
            const dx = ax - bx;
            const dy = ay - by;
            return dx * dx + dy * dy;
          }

          function distPointToSegSq(px, py, ax, ay, bx, by) {
            const abx = bx - ax;
            const aby = by - ay;
            const apx = px - ax;
            const apy = py - ay;
            const abLenSq = abx*abx + aby*aby;
            if (abLenSq <= 1e-12) return distanceSq(px, py, ax, ay);
            let t = (apx*abx + apy*aby) / abLenSq;
            t = Math.max(0, Math.min(1, t));
            const cx = ax + t * abx;
            const cy = ay + t * aby;
            return distanceSq(px, py, cx, cy);
          }

          async function insertVertexAtClick(xPdf, yPdf) {
            if (!selectedShapeId) return;
            const shape = findShapeById(selectedShapeId);
            if (!shape) return;

            const poly = Array.isArray(shape.polygon) ? [...shape.polygon] : [];
            if (poly.length < 3) return;

            let bestI = -1;
            let bestD = Infinity;

            for (let i = 0; i < poly.length; i++) {
              const a = poly[i];
              const b = poly[(i + 1) % poly.length];

              const ax = Number((a && (a.x ?? a[0])) ?? NaN);
              const ay = Number((a && (a.y ?? a[1])) ?? NaN);
              const bx = Number((b && (b.x ?? b[0])) ?? NaN);
              const by = Number((b && (b.y ?? b[1])) ?? NaN);

              if (!Number.isFinite(ax) || !Number.isFinite(ay) || !Number.isFinite(bx) || !Number.isFinite(by)) continue;

              const d = distPointToSegSq(xPdf, yPdf, ax, ay, bx, by);
              if (d < bestD) {
                bestD = d;
                bestI = i;
              }
            }

            if (bestI < 0) return;

            poly.splice(bestI + 1, 0, { x: Number(xPdf), y: Number(yPdf) });

            shape.polygon = poly;
            redrawShapes();
            renderHandles();

            try {
              await patchShape(shape.id, { polygon: poly });
              await loadShapes();
              redrawShapes();
              renderHandles();
              toast('Точка добавлена');
            } catch (e) {
              console.error(e);
              toast('Ошибка добавления точки: ' + String(e?.message || e));
              await loadShapes();
              redrawShapes();
              renderHandles();
            }
          }

          async function finishPolygon() {
            if (!polyDrawing) return;

            if (!Array.isArray(polyDraft) || polyDraft.length < 3) {
              toast('Нужно минимум 3 точки');
              return;
            }

            const poly = polyDraft.map(p => ({ x: Number(p.x), y: Number(p.y) }));

            polyDrawing = false;
            polyDraft = [];
            redrawShapes();
            renderHandles();

            createShape(poly).catch((err) => {
              console.error(err);
              toast('Ошибка сохранения: ' + String(err?.message || err));
            });
          }

          function cancelPolygon() {
            polyDrawing = false;
            polyDraft = [];
            redrawShapes();
            renderHandles();
            toast('Полигон отменён');
          }


          function syncChosenSpaceReview(item) {
            if (!item || !chosenSpace) {
              return;
            }

            const reviewedSpaceId = Number(item.market_space_id || 0);
            if (!Number.isFinite(reviewedSpaceId) || reviewedSpaceId <= 0 || reviewedSpaceId !== Number(chosenSpace.id)) {
              return;
            }

            setChosenSpace({
              ...chosenSpace,
              reviewStatus: item.review_status || '',
              reviewStatusLabel: item.review_status_label || '',
            }, { announce: false });
          }


          function reviewDecisionSuccessMessage(decision) {
            switch (decision) {
              case 'matched':
                return 'Ревизия: совпадение отмечено';
              case 'mark_space_free':
                return 'Ревизия: место отмечено как свободное';
              case 'mark_space_service':
                return 'Ревизия: место отмечено как служебное';
              case 'fix_space_identity':
                return 'Ревизия: данные места уточнены';
              case 'occupancy_conflict':
                return 'Ревизия: конфликт отправлен в observed';
              case 'tenant_changed_on_site':
                return 'Ревизия: смена арендатора зафиксирована';
              case 'shape_not_found':
                return 'Ревизия: место отмечено как не найденное';
              case 'bind_shape_to_space':
                return 'Ревизия: фигура привязана к месту';
              case 'unbind_shape_from_space':
                return 'Ревизия: фигура отвязана от места';
              default:
                return 'Ревизия обновлена';
            }
          }

          async function submitReviewDecision(decision, options = {}) {
            if (!REVIEW_DECISION_URL) {
              toast('Нет endpoint для ревизии');
              return;
            }

            const sourceHit = options.hit || lastHit || null;
            const sourceSpace = options.space || sourceHit?.space || chosenSpace || null;
            const marketSpaceId = Number(
              options.marketSpaceId
              || sourceSpace?.id
              || sourceHit?.market_space_id
              || chosenSpace?.id
              || 0
            );

            if (!Number.isFinite(marketSpaceId) || marketSpaceId <= 0) {
              toast('Сначала выбери место для ревизии');
              return;
            }

            const payload = {
              decision,
              market_space_id: Math.trunc(marketSpaceId),
            };

            const shapeId = Number(options.shapeId || sourceHit?.shape_id || 0);
            if (Number.isFinite(shapeId) && shapeId > 0 && ['bind_shape_to_space', 'unbind_shape_from_space'].includes(decision)) {
              payload.shape_id = Math.trunc(shapeId);
            }

            if (decision === 'occupancy_conflict' || decision === 'shape_not_found') {
              const reason = window.prompt('Комментарий для ревизии', '');
              if (!reason || !String(reason).trim()) {
                return;
              }
              payload.reason = String(reason).trim();
            }

            if (decision === 'tenant_changed_on_site') {
              const observedTenant = window.prompt('Фактический арендатор', options.observedTenantName || '');
              if (!observedTenant || !String(observedTenant).trim()) {
                return;
              }

              const reason = window.prompt('Комментарий для ревизии', '');
              if (!reason || !String(reason).trim()) {
                return;
              }

              payload.observed_tenant_name = String(observedTenant).trim();
              payload.reason = String(reason).trim();
            }

            if (decision === 'fix_space_identity') {
              const currentNumber = sourceSpace?.number ? String(sourceSpace.number) : '';
              const currentDisplayName = sourceSpace?.display_name ? String(sourceSpace.display_name) : '';
              const nextNumber = window.prompt('Номер места', currentNumber);
              const nextDisplayName = window.prompt('Название места', currentDisplayName);

              if ((!nextNumber || !String(nextNumber).trim()) && (!nextDisplayName || !String(nextDisplayName).trim())) {
                toast('Нужен номер или название места');
                return;
              }

              if (nextNumber && String(nextNumber).trim()) {
                payload.number = String(nextNumber).trim();
              }

              if (nextDisplayName && String(nextDisplayName).trim()) {
                payload.display_name = String(nextDisplayName).trim();
              }
            }

            const res = await apiFetch(REVIEW_DECISION_URL, {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify(payload),
            });

            let json = null;
            try {
              json = await res.json();
            } catch (error) {
              json = null;
            }

            if (!res.ok || !json || json.ok !== true) {
              toast(String(json?.message || 'Не удалось сохранить ревизию'));
              return;
            }

            if (json.progress) {
              updateReviewProgress(json.progress);
            }

            if (json.item) {
              syncChosenSpaceReview(json.item);
            }

            if (decision === 'bind_shape_to_space' || decision === 'unbind_shape_from_space') {
              await loadShapes();
              redrawShapes();
              renderHandles();
            }

            hidePopover();
            toast(reviewDecisionSuccessMessage(decision));
          }

          async function init() {

            const loadingTask = pdfjsLib.getDocument(PDF_URL);
            setMapLoadProgress(8, 'Загрузка карты…', 'loading');
            loadingTask.onProgress = (progressData) => {
              const loaded = Number(progressData?.loaded || 0);
              const total = Number(progressData?.total || 0);
              if (Number.isFinite(total) && total > 0) {
                const ratio = Math.max(0, Math.min(1, loaded / total));
                const percent = Math.round(8 + ratio * 60);
                setMapLoadProgress(percent, 'Загрузка PDF: ' + percent + '%', 'loading');
              } else {
                setMapLoadProgress(24, 'Загрузка карты…', 'loading');
              }
            };
            const shapesPromise = loadShapes()
              .then(() => {
                redrawShapes();
              })
              .catch((err) => {
                console.error(err);
              });
            const pdfDoc = await loadingTask.promise;
            setMapLoadProgress(84, 'PDF загружен, подготовка страницы…', 'rendering');
            await nextUiFrame();
            const requestedPage = MAP_PAGE || 1;
            try {
              page = await pdfDoc.getPage(requestedPage);
            } catch (e) {
              console.error(e);
              page = await pdfDoc.getPage(1);
              if (requestedPage !== 1) {
                toast('Страница карты не найдена, показана первая');
              }
            }

            await fitWidth();
            bootstrappingMap = false;
            completeMapLoadProgress();

            const postBootstrapTasks = [];

            if (FOCUS_SPACE_ID || FOCUS_SHAPE) {
              postBootstrapTasks.push((async () => {
                await shapesPromise;
                await applyInitialFocus();
              })());
            }

            if (CAN_EDIT) {
              postBootstrapTasks.push(refreshChosenSpaceFromServer());
            }

            if (postBootstrapTasks.length) {
              Promise.allSettled(postBootstrapTasks).catch((err) => {
                console.error(err);
              });
            }

            toast('Клик по месту откроет карточку.');
          }

          zoomInBtn?.addEventListener('click', async () => { scale = Math.min(7, scale * 1.2); await render(); });
          zoomOutBtn?.addEventListener('click', async () => { scale = Math.max(0.2, scale / 1.2); await render(); });
          zoomResetBtn?.addEventListener('click', async () => { scale = 1.0; await render(); });
          fitWidthBtn?.addEventListener('click', async () => { await fitWidth(); });
          layerDebtBtn?.addEventListener('click', () => setLayerMode('debt'));
          layerRentBtn?.addEventListener('click', () => setLayerMode('rent'));
          scenarioMapBtn?.addEventListener('click', () => setScenario('map'));
          scenarioReviewBtn?.addEventListener('click', () => setScenario('review'));

          if (CAN_EDIT && toggleEditBtn) {
            toggleEditBtn.addEventListener('click', async () => {
              if (isReviewMode()) {
                return;
              }

              editMode = !editMode;
              isEditMode = editMode;

              toggleEditBtn.textContent = editMode ? 'Разметка: вкл' : 'Разметка: выкл';

              if (toolSelectBtn) toolSelectBtn.style.display = editMode ? 'inline-flex' : 'none';
              if (toolRectBtn) toolRectBtn.style.display = editMode ? 'inline-flex' : 'none';
              if (toolPolyBtn) toolPolyBtn.style.display = editMode ? 'inline-flex' : 'none';

              updateScenarioUi();

              if (editMode) {
                setTool('select');
                setHint('Редактировать: клик — выбрать • тащи точки • Alt+клик — вставить вершину • Delete — удалить');
                toast('Разметка включена');
              } else {
                cancelPolygon();
                setSelectedShape(null);
                clearHandles();
                hidePopover();
                toast('Разметка выключена');
              }
            });
          }

          if (CAN_EDIT && toolSelectBtn) toolSelectBtn.addEventListener('click', () => { if (editMode) setTool('select'); });
          if (CAN_EDIT && toolRectBtn) toolRectBtn.addEventListener('click', () => { if (editMode) setTool('rect'); });
          if (CAN_EDIT && toolPolyBtn) toolPolyBtn.addEventListener('click', () => { if (editMode) setTool('poly'); });

          if (CAN_EDIT && spaceSearchInput) {
            spaceSearchInput.addEventListener('input', () => {
              const currentValue = String(spaceSearchInput.value || '').trim();
              if (currentValue && chosenSpace) {
                setChosenSpace(null, { announce: false });
              }
              scheduleSpaceSearch();
            });
            spaceSearchInput.addEventListener('keydown', (e) => {
              if (e.key === 'Escape') {
                closeSpaceDropdown();
                return;
              }
              if (!spaceDropdown || spaceDropdown.style.display !== 'block') return;
              if (e.key === 'ArrowDown') {
                e.preventDefault();
                searchIndex = Math.min(searchResults.length - 1, searchIndex + 1);
                updateDropdownActive();
              } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                searchIndex = Math.max(0, searchIndex - 1);
                updateDropdownActive();
              } else if (e.key === 'Enter') {
                e.preventDefault();
                const item = searchResults[searchIndex];
                if (item) {
                  setChosenSpace(item, { announce: true });
                  closeSpaceDropdown();
                } else if (String(spaceSearchInput.value || '').trim()) {
                  toast('Выбери место из списка');
                }
              }
            });
          }

          spaceChosenPill?.addEventListener('click', (e) => {
            const target = e.target;
            if (!(target instanceof HTMLElement)) return;
            if (target.getAttribute('data-action') === 'clear-chosen') {
              setChosenSpace(null, { announce: false });
              if (spaceSearchInput) spaceSearchInput.value = '';
              closeSpaceDropdown();
              toast('Выбор сброшен');
            }
          });

          document.addEventListener('click', (e) => {
            if (!spacePicker || !spaceDropdown) return;
            if (spacePicker.contains(e.target)) return;
            closeSpaceDropdown();
          });

          reviewNotFoundBtn?.addEventListener('click', () => {
            if (!chosenSpace) {
              toast('??????? ?????? ????? ??? ???????');
              return;
            }

            submitReviewDecision('shape_not_found', {
              marketSpaceId: chosenSpace.id,
              space: chosenSpace,
            }).catch((err) => {
              console.error(err);
              toast(String(err?.message || err));
            });
          });

          let isDown = false;
          let moved = false;
          let startX = 0, startY = 0, startLeft = 0, startTop = 0;
          const MOVE_THRESHOLD = 6;

          let drawingRect = false;
          let drawStart = null;

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
            if (draggingVertex) return;

            if (CAN_EDIT && editMode && tool === 'rect' && e.shiftKey) {
              drawingRect = true;
              drawStart = getCanvasPointFromClient(e.clientX, e.clientY);
              showDrawBox(drawStart.x, drawStart.y, drawStart.x, drawStart.y);
              e.preventDefault();
              return;
            }

            if (CAN_EDIT && editMode && tool === 'poly') return;

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
            if (drawingRect) {
              drawingRect = false;

              const end = getCanvasPointFromClient(e.clientX, e.clientY);
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

              if (w < 10 || h < 10) {
                toast('Слишком маленькая область');
                return;
              }

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

              createShape(poly).catch((err) => {
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
            if (draggingVertex && currentViewport) {
              draggingVertexMoved = true;

              const sid = draggingVertex.shapeId;
              const idx = draggingVertex.index;

              const shape = findShapeById(sid);
              if (!shape) return;

              const poly = Array.isArray(shape.polygon) ? [...shape.polygon] : [];
              if (poly.length < 3) return;
              if (idx < 0 || idx >= poly.length) return;

              const pCanvas = getCanvasPointFromClient(e.clientX, e.clientY);
              const pPdf = currentViewport.convertToPdfPoint(pCanvas.x, pCanvas.y);

              const nx = Number(pPdf[0]);
              const ny = Number(pPdf[1]);

              if (!Number.isFinite(nx) || !Number.isFinite(ny)) return;

              poly[idx] = { x: nx, y: ny };

              shape.polygon = poly;
              redrawShapes();
              renderHandles();

              return;
            }

            if (drawingRect) {
              const s = drawStart;
              if (!s) return;
              const p = getCanvasPointFromClient(e.clientX, e.clientY);
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

          async function onGlobalUp(e) {
            if (!draggingVertex) return;

            const { shapeId } = draggingVertex;
            draggingVertex = null;

            if (!draggingVertexMoved) {
              draggingVertexMoved = false;
              renderHandles();
              return;
            }

            draggingVertexMoved = false;

            const shape = findShapeById(shapeId);
            if (!shape) {
              renderHandles();
              return;
            }

            const poly = Array.isArray(shape.polygon) ? shape.polygon : [];
            if (poly.length < 3) {
              renderHandles();
              return;
            }

            try {
              await patchShape(shape.id, { polygon: poly });
              await loadShapes();
              redrawShapes();
              renderHandles();
              toast('Полигон обновлён');
            } catch (e2) {
              console.error(e2);
              toast('Ошибка обновления: ' + String(e2?.message || e2));
              await loadShapes();
              redrawShapes();
              renderHandles();
            }
          }

          async function onClick(e) {
            e.stopPropagation();

            if (drawingRect) return;
            if (!page || !currentViewport) return;

            const pCanvas = getCanvasPointFromClient(e.clientX, e.clientY);
            const pPdfArr = currentViewport.convertToPdfPoint(pCanvas.x, pCanvas.y);
            const xPdf = Array.isArray(pPdfArr) ? Number(pPdfArr[0]) : 0;
            const yPdf = Array.isArray(pPdfArr) ? Number(pPdfArr[1]) : 0;

            if (CAN_EDIT && editMode && tool === 'poly' && polyDrawing) {
              if (polyDraft.length >= 3) {
                const first = polyDraft[0];
                const v0 = currentViewport.convertToViewportPoint(Number(first.x), Number(first.y));
                if (Array.isArray(v0)) {
                  const d2 = distanceSq(Number(v0[0]), Number(v0[1]), pCanvas.x, pCanvas.y);
                  if (d2 <= 12 * 12) {
                    await finishPolygon();
                    return;
                  }
                }
              }

              polyDraft.push({ x: xPdf, y: yPdf });
              redrawShapes();
              toast('Точка: ' + String(polyDraft.length));
              return;
            }

            if (moved) return;

            if (CAN_EDIT && editMode && tool === 'select' && e.altKey && selectedShapeId) {
              await insertVertexAtClick(xPdf, yPdf);
              return;
            }

            showPopoverAt(
              e.clientX, e.clientY,
              '<div class="t">Поиск…</div><div class="row muted">x=' + xPdf.toFixed(1) + ', y=' + yPdf.toFixed(1) + '</div>'
            );

            try {
              const url = new URL(HIT_URL, window.location.origin);
              url.searchParams.set('x', String(xPdf));
              url.searchParams.set('y', String(yPdf));
              url.searchParams.set('page', String(MAP_PAGE || 1));
              url.searchParams.set('version', String(MAP_VERSION || 1));

              const res = await apiFetch(url.toString(), { headers: { 'Accept': 'application/json' } });
              const json = await res.json();

              if (!json || json.ok !== true) {
                const msg = escapeHtml(json?.message || 'Ошибка hit-test');
                showPopoverAt(e.clientX, e.clientY, '<div class="t">Ошибка</div><div class="row">' + msg + '</div>');
                return;
              }

              if (!json.hit) {
                lastHit = null;
                if (CAN_EDIT && editMode && tool === 'select') {
                  setSelectedShape(null);
                  clearHandles();
                }

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
              lastHit = hit;
              const space = hit.space || null;
              const tenant = hit.tenant || null;

              if (CAN_EDIT && editMode && tool === 'select' && hit.shape_id) {
                setSelectedShape(hit.shape_id);
              }

              let title = 'Торговое место';
              let line1 = '';
              let line2 = '';
              let line3 = '';
              let line4 = '';
              let line5 = '';
              let line6 = '';
              let line7 = '';

              if (space) {
                const label = (space.number && String(space.number).trim()) ? String(space.number) : (space.code || '');
                title = label ? ('Место: ' + escapeHtml(label)) : 'Торговое место';
                const metaParts = [];
                if (space.location_name) {
                  metaParts.push('Локация: ' + escapeHtml(space.location_name));
                }
                if (space.area_sqm) {
                  metaParts.push('Площадь: ' + escapeHtml(space.area_sqm) + ' м²');
                }
                line1 = metaParts.join(' • ');

                // Проверяем наличие арендатора
                const hasTenant = hit.space_tenant_id !== null && hit.space_tenant_id !== undefined;
                const storefront = space.display_name ? String(space.display_name).trim() : '';
                const activityType = space.activity_type ? String(space.activity_type).trim() : '';
                const storefrontLabel = storefront || activityType;
                const rentRateValue = space.rent_rate_value !== null && space.rent_rate_value !== undefined ? Number(space.rent_rate_value) : null;
                const rentRateUnit = rentRateUnitLabel(space.rent_rate_unit || '');
                const currentAccrualTotal = space.current_accrual_total !== null && space.current_accrual_total !== undefined ? Number(space.current_accrual_total) : null;
                const currentAccrualPeriod = space.current_accrual_period ? String(space.current_accrual_period) : '';
                const currentAccrualMode = space.current_accrual_mode ? String(space.current_accrual_mode) : '';

                if (!hasTenant) {
                  line2 = 'Свободно';
                  line3 = storefrontLabel ? ('Отдел / вывеска: ' + escapeHtml(storefrontLabel)) : '';
                  line4 = '';
                  if (rentRateValue !== null && Number.isFinite(rentRateValue)) {
                    line5 = 'Ставка аренды: ' + formatMoneyRu(rentRateValue) + (rentRateUnit ? ' ' + escapeHtml(rentRateUnit) : '');
                  }
                } else {
                  line2 = tenant?.name ? ('Арендатор: ' + escapeHtml(tenant.name)) : 'Арендатор: —';
                  line3 = storefrontLabel ? ('Отдел / вывеска: ' + escapeHtml(storefrontLabel)) : '';

                  // Информация о задолженности
                  const debtStatus = hit.debt_status || null;
                  const debtLabel = hit.debt_status_label || '';
                  const debtMode = hit.debt_status_mode || 'auto';
                  const debtScope = hit.debt_status_scope || 'none';
                  const overdueDays = hit.debt_overdue_days !== null && hit.debt_overdue_days !== undefined ? Number(hit.debt_overdue_days) : null;
                  const overdueDaysLabel = overdueDays !== null && Number.isFinite(overdueDays)
                    ? String(Math.max(0, Math.round(overdueDays)))
                    : null;
                  const debtSource = hit.debt_status_source || '';

                  // Объяснение режима (отдельная строка, не затирается)
                  let scopeExplanation = '';

                  if (debtScope === 'space') {
                    // Точный статус по месту
                    if (debtStatus === 'green') {
                      line4 = 'Статус по месту: Нет задолженности';
                      scopeExplanation = debtMode === 'manual' ? 'Статус задан вручную' : 'Связь с местом подтверждена в 1С';
                    } else if (debtStatus === 'pending') {
                      line4 = 'Статус по месту: Срок не нарушен';
                      scopeExplanation = 'Связь с местом подтверждена в 1С';
                    } else if (debtStatus === 'orange' || debtStatus === 'red') {
                      line4 = debtMode === 'manual'
                        ? ('Статус по месту: ' + escapeHtml(debtLabel))
                        : ('Просрочка по месту: ' + (overdueDaysLabel !== null ? overdueDaysLabel + ' дн.' : (debtStatus === 'red' ? 'длительная' : 'есть')));
                      scopeExplanation = debtMode === 'manual' ? 'Статус задан вручную' : 'Связь с местом подтверждена в 1С';
                    } else if (debtStatus === 'gray') {
                      line4 = 'Статус по месту: Нет данных 1С';
                      scopeExplanation = debtSource ? ('Причина: ' + escapeHtml(debtSource)) : '';
                    } else {
                      line4 = debtLabel ? ('Задолженность по месту: ' + escapeHtml(debtLabel)) : 'Задолженность по месту: —';
                      scopeExplanation = '';
                    }
                  } else if (debtScope === 'tenant_fallback') {
                    // Статус арендатора (нет точной связи с местом)
                    if (debtStatus === 'green') {
                      line4 = 'Статус арендатора: Нет задолженности';
                      scopeExplanation = 'Нет точной связи с местом';
                    } else if (debtStatus === 'pending') {
                      line4 = 'Статус арендатора: Срок не нарушен';
                      scopeExplanation = 'Нет точной связи с местом';
                    } else if (debtStatus === 'orange' || debtStatus === 'red') {
                      line4 = debtMode === 'manual'
                        ? ('Статус арендатора: ' + escapeHtml(debtLabel))
                        : ('Просрочка арендатора: ' + (overdueDaysLabel !== null ? overdueDaysLabel + ' дн.' : (debtStatus === 'red' ? 'длительная' : 'есть')));
                      scopeExplanation = 'Нет точной связи с местом';
                    } else if (debtStatus === 'gray') {
                      line4 = 'Статус арендатора: Нет данных 1С';
                      scopeExplanation = debtSource ? ('Причина: ' + escapeHtml(debtSource)) : '';
                    } else {
                      line4 = debtLabel ? ('Задолженность арендатора: ' + escapeHtml(debtLabel)) : 'Задолженность арендатора: —';
                      scopeExplanation = 'Нет точной связи с местом';
                    }
                  } else {
                    // scope=none или неизвестный
                    if (debtStatus === 'gray') {
                      line4 = 'Статус: Нет данных';
                      scopeExplanation = debtSource ? ('Причина: ' + escapeHtml(debtSource)) : '';
                    } else {
                      line4 = debtLabel ? ('Статус: ' + escapeHtml(debtLabel)) : 'Статус: —';
                      scopeExplanation = '';
                    }
                  }

                  // line5 — объяснение режима (если есть)
                  line5 = scopeExplanation;

                  // line6 — ставка аренды (если есть)
                  if (rentRateValue !== null && Number.isFinite(rentRateValue)) {
                    line6 = 'Ставка аренды: ' + formatMoneyRu(rentRateValue) + (rentRateUnit ? ' ' + escapeHtml(rentRateUnit) : '');
                  } else {
                    line6 = '';
                  }

                  // line7 — начисления (если есть)
                  if (currentAccrualTotal !== null && Number.isFinite(currentAccrualTotal)) {
                    const accrualSuffix = currentAccrualMode === 'latest' ? 'последний период' : currentAccrualPeriod;
                    line7 = 'Начислено' + (accrualSuffix ? ' (' + escapeHtml(accrualSuffix) + ')' : '') + ': ' + formatMoneyRu(currentAccrualTotal);
                  } else {
                    line7 = '';
                  }
                }
              } else {
                title = 'Разметка';
                line1 = 'Место не привязано (разметка)';
                line2 = '';
                line3 = '';
                line4 = '';
                line5 = '';
                line6 = '';
              }

              let actions = '';
              const btns = [];
              const shapeId = hit.shape_id ? Number(hit.shape_id) : null;
              const hitSpaceId = hit.market_space_id ? Number(hit.market_space_id) : null;
              const hitTenantId = hit?.tenant?.id ? Number(hit.tenant.id) : (hit?.tenant_id ? Number(hit.tenant_id) : null);
              const chosenId = getChosenSpaceId();
              const chosenLabel = chosenSpace ? (formatSpaceLabel(chosenSpace) + ' (ID ' + String(chosenSpace.id) + ')') : '—';

              if (hitSpaceId && Number.isFinite(hitSpaceId) && hitSpaceId > 0) {
                btns.push('<button type="button" data-action="open-space" data-space-id="' + String(hitSpaceId) + '">Открыть место</button>');
              }

              if (hitTenantId && Number.isFinite(hitTenantId) && hitTenantId > 0) {
                btns.push('<button type="button" data-action="open-tenant" data-tenant-id="' + String(hitTenantId) + '">Открыть арендатора</button>');
              }

              if (isReviewMode()) {
                if (hitSpaceId && Number.isFinite(hitSpaceId) && hitSpaceId > 0) {
                  btns.push('<button type="button" data-action="review-decision" data-decision="matched" data-space-id="' + String(hitSpaceId) + '">\u0421\u043e\u0432\u043f\u0430\u043b\u043e</button>');
                  btns.push('<button type="button" data-action="review-decision" data-decision="mark_space_free" data-space-id="' + String(hitSpaceId) + '">\u0421\u0432\u043e\u0431\u043e\u0434\u043d\u043e</button>');
                  btns.push('<button type="button" data-action="review-decision" data-decision="mark_space_service" data-space-id="' + String(hitSpaceId) + '">\u0421\u043b\u0443\u0436\u0435\u0431\u043d\u043e\u0435</button>');
                  btns.push('<button type="button" data-action="review-decision" data-decision="occupancy_conflict" data-space-id="' + String(hitSpaceId) + '">\u041a\u043e\u043d\u0444\u043b\u0438\u043a\u0442</button>');
                  btns.push('<button type="button" data-action="review-decision" data-decision="tenant_changed_on_site" data-space-id="' + String(hitSpaceId) + '">\u0421\u043c\u0435\u043d\u0438\u043b\u0441\u044f \u0430\u0440\u0435\u043d\u0434\u0430\u0442\u043e\u0440</button>');
                  btns.push('<button type="button" data-action="review-decision" data-decision="fix_space_identity" data-space-id="' + String(hitSpaceId) + '">\u0423\u0442\u043e\u0447\u043d\u0438\u0442\u044c \u0434\u0430\u043d\u043d\u044b\u0435</button>');
                }

                if ((!hitSpaceId || hitSpaceId <= 0) && chosenId && Number.isFinite(chosenId) && chosenId > 0 && shapeId && Number.isFinite(shapeId) && shapeId > 0) {
                  btns.push('<button type="button" data-action="review-decision" data-decision="bind_shape_to_space" data-space-id="' + String(chosenId) + '" data-shape-id="' + String(shapeId) + '">\u041f\u0440\u0438\u0432\u044f\u0437\u0430\u0442\u044c \u043a \u0432\u044b\u0431\u0440\u0430\u043d\u043d\u043e\u043c\u0443 \u043c\u0435\u0441\u0442\u0443</button>');
                }
              }

              if (CAN_EDIT && editMode && shapeId && Number.isFinite(shapeId) && shapeId > 0) {
                if (hit.market_space_id) {
                  btns.push('<button type="button" data-action="set-chosen-space" data-space-id="' + String(hit.market_space_id) + '">Выбрать это место</button>');
                }

                btns.push('<button type="button" data-action="delete-shape" data-shape-id="' + String(shapeId) + '">Удалить разметку</button>');
              }
              if (btns.length) {
                actions = '<div class="act">' + btns.join('') + '</div>';
              }

              showPopoverAt(
                e.clientX, e.clientY,
                '<div class="t">' + title + '</div>' +
                (line2 ? '<div class="row">' + line2 + '</div>' : '') +
                (line3 ? '<div class="row">' + line3 + '</div>' : '') +
                (line4 ? '<div class="row">' + line4 + '</div>' : '') +
                (line5 ? '<div class="row">' + line5 + '</div>' : '') +
                (line6 ? '<div class="row">' + line6 + '</div>' : '') +
                (line7 ? '<div class="row">' + line7 + '</div>' : '') +
                (line1 ? '<div class="row muted">' + escapeHtml(line1) + '</div>' : '') +
                actions
              );
            } catch (err) {
              console.error(err);
              showPopoverAt(e.clientX, e.clientY, '<div class="t">Ошибка</div><div class="row">Не удалось выполнить запрос hit-test.</div>');
            }
          }

          popover?.addEventListener('click', (e) => {
            const t = e.target;
            if (!(t instanceof HTMLElement)) return;

            const action = t.getAttribute('data-action');

            if (action === 'review-decision') {
              const decision = String(t.getAttribute('data-decision') || '');
              const marketSpaceId = Number(t.getAttribute('data-space-id') || 0);
              const shapeId = Number(t.getAttribute('data-shape-id') || 0);
              submitReviewDecision(decision, {
                marketSpaceId: Number.isFinite(marketSpaceId) && marketSpaceId > 0 ? marketSpaceId : null,
                shapeId: Number.isFinite(shapeId) && shapeId > 0 ? shapeId : null,
                hit: lastHit,
                space: lastHit?.space || chosenSpace || null,
                observedTenantName: lastHit?.tenant?.name || '',
              }).catch((err) => {
                console.error(err);
                toast(String(err?.message || err));
              });
              return;
            }

            if (action === 'open-space') {
              const id = Number(t.getAttribute('data-space-id') || 0);
              if (!Number.isFinite(id) || id <= 0) return;
              window.open('/admin/market-spaces/' + String(Math.trunc(id)) + '/edit', '_blank', 'noopener');
              return;
            }

            if (action === 'open-tenant') {
              const id = Number(t.getAttribute('data-tenant-id') || 0);
              if (!Number.isFinite(id) || id <= 0) return;
              window.open('/admin/tenants/' + String(Math.trunc(id)) + '/edit', '_blank', 'noopener');
              return;
            }

            if (action === 'delete-shape') {
              const id = t.getAttribute('data-shape-id');
              deleteShape(id).then(() => hidePopover()).catch((err) => {
                console.error(err);
                toast('Ошибка удаления: ' + String(err?.message || err));
              });
              return;
            }

            if (action === 'set-chosen-space') {
              const id = Number(t.getAttribute('data-space-id') || 0);
              if (!Number.isFinite(id) || id <= 0) return;

              const spaceLabel = lastHit?.space || null;
              const hitTenant = lastHit?.tenant || null;
              const next = normalizeChosenSpace({
                id,
                number: spaceLabel?.number ?? '',
                code: spaceLabel?.code ?? '',
                tenantName: hitTenant?.name ?? null,
              });

              if (next) {
                setChosenSpace(next, { announce: true });
                hidePopover();
              }
              return;
            }
          });

          window.addEventListener('keydown', async (e) => {
            if (!CAN_EDIT || !editMode) return;

            if (tool === 'poly' && polyDrawing) {
              if (e.key === 'Enter') {
                e.preventDefault();
                await finishPolygon();
                return;
              }
              if (e.key === 'Escape') {
                e.preventDefault();
                cancelPolygon();
                return;
              }
              if (e.key === 'Backspace') {
                e.preventDefault();
                if (polyDraft.length > 0) {
                  polyDraft.pop();
                  redrawShapes();
                  toast('Точка удалена');
                }
                return;
              }
            }

            if (tool === 'select' && selectedShapeId && (e.key === 'Delete')) {
              e.preventDefault();
              deleteShape(selectedShapeId).catch((err) => {
                console.error(err);
                toast('Ошибка удаления: ' + String(err?.message || err));
              });
              return;
            }
          });

          overlay.addEventListener('mousedown', onDown);
          overlay.addEventListener('click', onClick);

          window.addEventListener('mouseup', onUp);
          window.addEventListener('mouseup', onGlobalUp);
          window.addEventListener('mousemove', onMove);

          handlesLayer?.addEventListener('mousedown', (e) => e.stopPropagation());

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
            'https://cdn.jsdelivr.net/npm/pdfjs-dist@5.4.530/build/pdf.min.mjs',
            'https://cdn.jsdelivr.net/npm/pdfjs-dist@5.4.530/build/pdf.worker.min.mjs'
          );
          if (cdn) return cdn;

          return null;
        }

        setMapLoadProgress(4, 'Загрузка карты…', 'loading');
        const loaded = await loadPdfJs();
        if (!loaded) {
          fallbackToIframe('cannot import pdfjs from local and CDN');
        } else {
          startPdfJs(loaded.pdfjsLib, loaded.workerSrc);
        }
      </script>
    @endif
  </div>
</body>
</html>
