<x-filament-panels::page>
    @include('filament.resources.market-holiday-resource.pages.partials.calendar-content', [
        'monthLabel' => $monthLabel,
        'weeks' => $weeks,
        'weekdays' => $weekdays,
        'prevMonthUrl' => $prevMonthUrl,
        'nextMonthUrl' => $nextMonthUrl,
    ])
</x-filament-panels::page>
