<div class="flex items-center mr-3 gap-2" data-learning-target="topbar-actions">
    <button
        type="button"
        class="fi-btn fi-btn-size-sm fi-btn-color-gray market-learning-mode-toggle"
        data-learning-mode-toggle
        data-learning-target="learning-toggle"
        aria-pressed="false"
        style="white-space:nowrap;background:#f8fafc;border:1px solid #cbd5e1;color:#0f172a;box-shadow:0 10px 24px rgba(15,23,42,.08);"
    >
        Обучение
    </button>

    <a
        href="{{ $mapUrl }}"
        target="_blank"
        rel="noopener"
        class="fi-btn fi-btn-size-sm fi-btn-color-primary fi-color-primary"
        data-learning-target="map-link"
        style="white-space:nowrap;background:#16a34a;border-color:#15803d;color:#fff;box-shadow:0 10px 30px rgba(22,163,74,.2);"
    >
        Карта
    </a>

    <a
        href="{{ $marketplaceUrl }}"
        target="_blank"
        rel="noopener"
        class="fi-btn fi-btn-size-sm fi-btn-color-gray"
        data-learning-target="marketplace-link"
        style="white-space:nowrap;background:#2563eb;border-color:#1d4ed8;color:#fff;box-shadow:0 10px 30px rgba(37,99,235,.2);"
    >
        Маркетплейс
    </a>
</div>
