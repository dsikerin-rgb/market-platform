@php
    use Filament\Widgets\View\Components\ChartWidgetComponent;
    use Illuminate\View\ComponentAttributeBag;

    $color = $this->getColor();
    $heading = $this->getHeading();
    $description = $this->getDescription();
    $type = $this->getType();
    $chartData = $this->getCachedData();
    $canvasData = [
        'labels' => $chartData['labels'] ?? [],
        'datasets' => $chartData['datasets'] ?? [],
    ];
    $deltaBars = $chartData['deltaBars'] ?? [];
    $deltaMaxAbs = max((float) ($chartData['deltaMaxAbs'] ?? 0), 1.0);
    $formatMoney = static fn (float $value): string => number_format($value, abs($value - round($value)) < 0.01 ? 0 : 2, ',', ' ') . ' ₽';
@endphp

<x-filament-widgets::widget class="fi-wi-chart">
    <x-filament::section
        :description="$description"
        :heading="$heading"
    >
        <div
            @if ($pollingInterval = $this->getPollingInterval())
                wire:poll.{{ $pollingInterval }}="updateChartData"
            @endif
        >
            <div
                x-load
                x-load-src="{{ \Filament\Support\Facades\FilamentAsset::getAlpineComponentSrc('chart', 'filament/widgets') }}"
                wire:ignore
                data-chart-type="{{ $type }}"
                x-data="chart({
                            cachedData: @js($canvasData),
                            options: @js($this->getOptions()),
                            type: @js($type),
                        })"
                {{
                    (new ComponentAttributeBag)
                        ->color(ChartWidgetComponent::class, $color)
                        ->class([
                            'fi-wi-chart-canvas-ctn',
                            'fi-wi-chart-canvas-ctn-no-aspect-ratio' => filled($maxHeight = $this->getMaxHeight()),
                        ])
                        ->style([
                            'max-height: ' . $maxHeight => filled($maxHeight),
                        ])
                }}
            >
                <canvas x-ref="canvas"></canvas>

                <span
                    x-ref="backgroundColorElement"
                    class="fi-wi-chart-bg-color"
                ></span>

                <span
                    x-ref="borderColorElement"
                    class="fi-wi-chart-border-color"
                ></span>

                <span
                    x-ref="gridColorElement"
                    class="fi-wi-chart-grid-color"
                ></span>

                <span
                    x-ref="textColorElement"
                    class="fi-wi-chart-text-color"
                ></span>
            </div>
        </div>

        @if (count($deltaBars) > 0)
            <div class="mt-4 border-t border-gray-200 pt-3 dark:border-gray-800">
                <div class="mb-2 flex items-center justify-between gap-3 text-xs text-gray-500 dark:text-gray-400">
                    <span class="font-medium text-gray-700 dark:text-gray-200">Разница</span>
                    <span class="inline-flex flex-wrap items-center justify-end gap-x-3 gap-y-1">
                        <span class="inline-flex items-center gap-1.5">
                            <span class="h-2 w-2 rounded-sm bg-danger-500"></span>
                            <span>Начислено больше</span>
                        </span>
                        <span class="inline-flex items-center gap-1.5">
                            <span class="h-2 w-2 rounded-sm bg-success-500"></span>
                            <span>Оплачено больше</span>
                        </span>
                    </span>
                </div>

                <div
                    class="grid gap-1"
                    style="grid-template-columns: repeat({{ count($deltaBars) }}, minmax(0, 1fr));"
                >
                    @foreach ($deltaBars as $bar)
                        @php
                            $value = (float) ($bar['value'] ?? 0);
                            $height = round((abs($value) / $deltaMaxAbs) * 48, 2);
                            $isPositive = $value >= 0;
                            $title = ($bar['label'] ?? '')
                                . ': начислено ' . $formatMoney((float) ($bar['accrued'] ?? 0))
                                . ', оплачено ' . $formatMoney((float) ($bar['paid'] ?? 0))
                                . ', разница ' . $formatMoney($value);
                        @endphp

                        <div
                            class="relative h-16"
                            title="{{ $title }}"
                        >
                            <div class="absolute inset-x-0 top-1/2 border-t border-gray-300 dark:border-gray-700"></div>
                            <div
                                class="absolute left-1/2 w-3 -translate-x-1/2 rounded-sm {{ $isPositive ? 'bottom-1/2 bg-danger-500' : 'top-1/2 bg-success-500' }}"
                                style="height: {{ $height }}%;"
                            ></div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
