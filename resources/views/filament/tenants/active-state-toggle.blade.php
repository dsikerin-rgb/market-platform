@php
    $isActive = (bool) ($isActive ?? false);
@endphp

<button
    type="button"
    role="switch"
    aria-checked="{{ $isActive ? 'true' : 'false' }}"
    wire:click="toggleTenantActiveState"
    wire:loading.attr="disabled"
    class="tenant-hero-state-card tenant-card-action tenant-card-action--state {{ $isActive ? 'is-active' : 'is-inactive' }}"
>
    <span class="tenant-hero-state-copy">
        <span class="tenant-hero-state-title">
            {{ $isActive ? 'Активен' : 'Неактивен' }}
        </span>

        <span class="tenant-hero-state-subtitle">
            {{ $isActive ? 'Договор участвует в работе' : 'Договор отключен' }}
        </span>
    </span>

    <span class="tenant-hero-state-switch" aria-hidden="true">
        <span class="tenant-hero-state-switch__track">
            <span class="tenant-hero-state-switch__thumb"></span>
        </span>
    </span>
</button>
