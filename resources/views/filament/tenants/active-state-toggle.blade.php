@php
    $isActive = (bool) ($isActive ?? false);
@endphp

<button
    type="button"
    role="switch"
    aria-checked="{{ $isActive ? 'true' : 'false' }}"
    x-data="{ active: @js($isActive), busy: false }"
    x-on:click.prevent="
        if (busy) return;

        busy = true;
        active = !active;

        $wire.toggleTenantActiveState()
            .catch(() => {
                active = !active;
            })
            .finally(() => {
                busy = false;
            });
    "
    x-bind:aria-checked="active ? 'true' : 'false'"
    x-bind:class="active ? 'is-active' : 'is-inactive'"
    x-bind:disabled="busy"
    class="tenant-hero-state-card tenant-card-action tenant-card-action--state {{ $isActive ? 'is-active' : 'is-inactive' }}"
>
    <span class="tenant-hero-state-copy">
        <span class="tenant-hero-state-title" x-text="active ? 'Активен' : 'Неактивен'">
            {{ $isActive ? 'Активен' : 'Неактивен' }}
        </span>

        <span class="tenant-hero-state-subtitle" x-text="active ? 'Договор участвует в работе' : 'Договор отключен'">
            {{ $isActive ? 'Договор участвует в работе' : 'Договор отключен' }}
        </span>
    </span>

    <span class="tenant-hero-state-switch" aria-hidden="true">
        <span class="tenant-hero-state-switch__track">
            <span class="tenant-hero-state-switch__thumb"></span>
        </span>
    </span>
</button>
