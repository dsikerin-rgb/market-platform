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
>
    <span class="market-space-hero-state-copy">
        <span class="market-space-hero-state-title" x-text="active ? 'Активно' : 'Неактивно'">
            {{ $isActive ? 'Активно' : 'Неактивно' }}
        </span>

        <span class="market-space-hero-state-subtitle" x-text="active ? 'Место участвует в работе' : 'Место скрыто из сценариев'">
            {{ $isActive ? 'Место участвует в работе' : 'Место скрыто из сценариев' }}
        </span>
    </span>

    <span class="market-space-hero-state-switch" aria-hidden="true">
        <span class="market-space-hero-state-switch__track">
            <span class="market-space-hero-state-switch__thumb"></span>
        </span>
    </span>
</button>
