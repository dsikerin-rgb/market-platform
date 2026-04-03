<header class="market-space-edit-hero">
    @if ($breadcrumbs)
        <div class="market-space-edit-hero__breadcrumbs">
            <x-filament::breadcrumbs :breadcrumbs="$breadcrumbs" />
        </div>
    @endif

    <div class="market-space-edit-hero__top">
        <div class="market-space-edit-hero__main">
            <div class="market-space-edit-hero__heading-row">
                @if (filled($heading))
                    <h1 class="market-space-edit-hero__heading">{{ $heading }}</h1>
                @endif

                @if (filled($statusLabel))
                    <span class="market-space-edit-hero__status market-space-edit-hero__status--{{ $statusColor ?? 'gray' }}">
                        {{ $statusLabel }}
                    </span>
                @endif
            </div>
        </div>

        @if ($actions)
            <div class="market-space-edit-hero__actions">
                <x-filament::actions
                    :actions="$actions"
                    :alignment="$actionsAlignment"
                />
            </div>
        @endif
    </div>
</header>
