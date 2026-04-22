<button
    type="button"
    class="market-space-hero-state-card"
    x-on:click="{{ $action->getAlpineClickHandler() }}"
    aria-label="Упразднить место"
>
    <span class="market-space-hero-state-copy">
        <span class="market-space-hero-state-title">Упразднить место</span>
        <span class="market-space-hero-state-subtitle">Проверка связей перед деактивацией</span>
    </span>

    <span class="market-space-hero-state-switch" aria-hidden="true">
        <span class="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-slate-500 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-300">
            Проверка
        </span>
    </span>
</button>
