{{-- resources/views/filament/widgets/market-switcher-widget.blade.php --}}

<x-filament::section class="market-switcher-widget">
    <style>
        .market-switcher-widget {
            width: 100%;
        }

        .market-switcher-widget-grid {
            display: grid;
            grid-template-columns: minmax(0, 1fr);
            gap: 1rem;
            align-items: start;
        }

        .market-switcher-widget-copy {
            min-width: 0;
        }

        .market-switcher-widget-title {
            margin: 0;
            font-size: 1rem;
            line-height: 1.5rem;
            font-weight: 600;
            color: rgb(17 24 39);
        }

        .dark .market-switcher-widget-title {
            color: rgb(255 255 255);
        }

        .market-switcher-widget-hint {
            margin-top: 0.5rem;
            max-width: 36rem;
            font-size: 0.875rem;
            line-height: 1.5rem;
            color: rgb(107 114 128);
        }

        .dark .market-switcher-widget-hint {
            color: rgb(156 163 175);
        }

        .market-switcher-widget-control {
            min-width: 0;
        }

        @media (min-width: 1024px) {
            .market-switcher-widget {
                width: min(100%, 66.6667%);
            }

            .market-switcher-widget-grid {
                grid-template-columns: minmax(0, 1fr) 24rem;
                gap: 2rem;
                align-items: center;
            }

            .market-switcher-widget-control {
                justify-self: end;
                width: 24rem;
            }
        }
    </style>

    <div class="market-switcher-widget-grid">
        <div class="market-switcher-widget-copy">
            <div class="market-switcher-widget-title">
                Рынок
            </div>
            <div class="market-switcher-widget-hint">
                Выбор влияет на метрики. После смены страница обновится.
            </div>
        </div>

        <div class="market-switcher-widget-control">
            <div class="w-full">
                <x-filament::input.wrapper class="w-full">
                    <x-filament::input.select wire:model.live="selectedMarketId">
                        <option value="">— Выберите рынок —</option>

                        @foreach ($markets as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </div>
        </div>
    </div>
</x-filament::section>
