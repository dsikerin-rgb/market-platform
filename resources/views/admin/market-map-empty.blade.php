<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>Карта рынка — {{ $marketName }}</title>
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
      </div>
    </div>

    <script>
      (function () {
        const btn = document.getElementById('closeBtn');
        const returnUrl = @json($returnUrl ?? '');
        const settingsUrl = @json($settingsUrl ?? '');
        const fallbackUrl = returnUrl || settingsUrl || '';

        function isStandaloneApp() {
          try {
            if (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) return true;
            if (window.matchMedia && window.matchMedia('(display-mode: fullscreen)').matches) return true;
          } catch (e) {
            // ignore
          }

          return Boolean(window.navigator && 'standalone' in window.navigator && window.navigator.standalone);
        }

        btn.addEventListener('click', function () {
          if (isStandaloneApp()) {
            if (window.history.length > 1) {
              window.history.back();
            } else if (fallbackUrl) {
              window.location.replace(fallbackUrl);
            }
            return;
          }

          try { window.close(); } catch (e) { /* ignore */ }

          setTimeout(function () {
            if (document.visibilityState !== 'hidden' && fallbackUrl) {
              window.location.replace(fallbackUrl);
            }
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
