<x-filament::section>
    <x-slot name="heading">
        {{ $heading }}
    </x-slot>

    @if (filled($description))
        <x-slot name="description">
            {{ $description }}
        </x-slot>
    @endif

    @php
        $formatMoney = static fn (float $value): string => number_format($value, abs($value - round($value)) < 0.01 ? 0 : 2, ',', ' ') . ' ₽';
        $formatCount = static fn (int $value): string => number_format($value, 0, ',', ' ');
    @endphp

    <style>
        .accrual-packages-widget {
            display: flex;
            flex-direction: column;
            gap: 0.85rem;
        }

        .accrual-packages-widget__summary {
            display: flex;
            flex-wrap: wrap;
            align-items: baseline;
            justify-content: space-between;
            gap: 0.45rem 0.75rem;
        }

        .accrual-packages-widget__total {
            color: rgb(15, 23, 42);
            font-size: 1.08rem;
            font-weight: 750;
            line-height: 1.1;
            font-variant-numeric: tabular-nums;
        }

        .dark .accrual-packages-widget__total {
            color: rgb(248, 250, 252);
        }

        .accrual-packages-widget__meta {
            color: rgb(100, 116, 139);
            font-size: 0.74rem;
            line-height: 1.35;
        }

        .dark .accrual-packages-widget__meta {
            color: rgb(148, 163, 184);
        }

        .accrual-packages-widget__rows {
            display: flex;
            flex-direction: column;
            gap: 0.7rem;
        }

        .accrual-packages-widget__row {
            display: flex;
            flex-direction: column;
            gap: 0.34rem;
            min-width: 0;
        }

        .accrual-packages-widget__line {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 0.65rem;
            align-items: start;
        }

        .accrual-packages-widget__label {
            min-width: 0;
            color: rgb(51, 65, 85);
            font-size: 0.79rem;
            font-weight: 650;
            line-height: 1.25;
            overflow-wrap: anywhere;
        }

        .dark .accrual-packages-widget__label {
            color: rgb(226, 232, 240);
        }

        .accrual-packages-widget__amount {
            color: rgb(15, 23, 42);
            font-size: 0.78rem;
            font-weight: 750;
            line-height: 1.25;
            text-align: right;
            white-space: nowrap;
            font-variant-numeric: tabular-nums;
        }

        .dark .accrual-packages-widget__amount {
            color: rgb(248, 250, 252);
        }

        .accrual-packages-widget__subline {
            display: flex;
            flex-wrap: wrap;
            gap: 0.35rem 0.55rem;
            color: rgb(100, 116, 139);
            font-size: 0.7rem;
            line-height: 1.25;
        }

        .dark .accrual-packages-widget__subline {
            color: rgb(148, 163, 184);
        }

        .accrual-packages-widget__track {
            height: 0.42rem;
            overflow: hidden;
            border-radius: 999px;
            background: rgba(148, 163, 184, 0.18);
        }

        .dark .accrual-packages-widget__track {
            background: rgba(51, 65, 85, 0.75);
        }

        .accrual-packages-widget__bar {
            display: block;
            height: 100%;
            border-radius: inherit;
        }

        .accrual-packages-widget__empty {
            border-radius: 0.75rem;
            border: 1px dashed rgba(148, 163, 184, 0.45);
            padding: 1rem;
            color: rgb(71, 85, 105);
            font-size: 0.85rem;
            line-height: 1.45;
        }

        .dark .accrual-packages-widget__empty {
            border-color: rgba(71, 85, 105, 0.85);
            color: rgb(203, 213, 225);
        }
    </style>

    @if (filled($emptyReason))
        <div class="accrual-packages-widget__empty">
            {{ $emptyReason }}
        </div>
    @else
        <div class="accrual-packages-widget">
            <div class="accrual-packages-widget__summary">
                <div class="accrual-packages-widget__total">{{ $formatMoney((float) $totalAmount) }}</div>
                <div class="accrual-packages-widget__meta">
                    {{ $formatCount((int) $rowsCount) }} строк · {{ $formatCount((int) $packagesCount) }} групп
                </div>
            </div>

            <div class="accrual-packages-widget__rows">
                @foreach ($packages as $package)
                    <div class="accrual-packages-widget__row">
                        <div class="accrual-packages-widget__line">
                            <div class="accrual-packages-widget__label" title="{{ $package['full_label'] }}">
                                {{ $package['label'] }}
                            </div>
                            <div class="accrual-packages-widget__amount">{{ $formatMoney((float) $package['amount']) }}</div>
                        </div>

                        <div class="accrual-packages-widget__subline">
                            <span>{{ $package['percent_label'] }}%</span>
                            <span>{{ $formatCount((int) $package['rows']) }} строк</span>
                            @if (filled($package['packages_count'] ?? null))
                                <span>{{ $formatCount((int) $package['packages_count']) }} групп</span>
                            @endif
                        </div>

                        <div class="accrual-packages-widget__track" aria-hidden="true">
                            <span
                                class="accrual-packages-widget__bar"
                                style="width: {{ $package['width'] }}; background: {{ $package['color'] }};"
                            ></span>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</x-filament::section>
