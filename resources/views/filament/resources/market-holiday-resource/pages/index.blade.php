<div
    class="aw-content-switcher"
    x-data="{ loading: false }"
    x-on:livewire:navigating.window="loading = true"
    x-on:livewire:navigated.window="loading = false"
>
    <div class="aw-content-switcher__overlay" x-show="loading" style="display: none;">
        <div class="aw-content-switcher__spinner" aria-hidden="true"></div>
    </div>

    <div class="aw-content-switcher__body" :class="{ 'is-loading': loading }">
        @if ($this->viewMode === 'calendar')
            @include('filament.resources.market-holiday-resource.pages.partials.calendar-content', [
                'monthLabel' => $monthLabel,
                'weeks' => $weeks,
                'weekdays' => $weekdays,
                'prevMonthLabel' => $prevMonthLabel,
                'nextMonthLabel' => $nextMonthLabel,
                'prevMonthUrl' => $prevMonthUrl,
                'nextMonthUrl' => $nextMonthUrl,
            ])
        @else
            {{ $this->table }}
        @endif
    </div>
</div>
