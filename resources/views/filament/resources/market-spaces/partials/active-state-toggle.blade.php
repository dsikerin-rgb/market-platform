@php
    $isActive = (bool) ($isActive ?? false);
@endphp

<button
    type="button"
    role="switch"
    x-data="{ active: @js($isActive), busy: false }"
    x-on:click.prevent="
        if (busy) return;

        busy = true;
        active = !active;

        $wire.toggleMarketSpaceActiveState()
            .catch(() => {
                active = !active;
            })
            .finally(() => {
                busy = false;
            });
    "
    x-bind:aria-checked="active ? 'true' : 'false'"
    x-bind:class="{ 'is-active': active, 'is-inactive': !active }"
    x-bind:disabled="busy"
    class="market-space-hero-state-card"
    title="Не заменяет сценарий &quot;Упразднить место&quot;"
>
    <span class="market-space-hero-state-copy">
        <span class="market-space-hero-state-title" x-text="active ? 'Активно' : 'Неактивно'">
            {{ $isActive ? 'Активно' : 'Неактивно' }}
        </span>

        <span class="market-space-hero-state-subtitle" x-text="active ? 'Место участвует в текущей работе' : 'Место выключено из активного контура'">
            {{ $isActive ? 'Место участвует в текущей работе' : 'Место выключено из активного контура' }}
        </span>
    </span>

    <span class="market-space-hero-state-switch" aria-hidden="true">
        <span class="market-space-hero-state-switch__track">
            <span class="market-space-hero-state-switch__thumb"></span>
        </span>
    </span>
</button>
