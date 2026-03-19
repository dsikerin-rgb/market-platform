{{-- resources/views/filament/widgets/market-switcher-widget.blade.php --}}

<x-filament::section>
    <div class="flex w-full flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
        <div class="min-w-0 xl:max-w-sm">
            <div class="text-base font-semibold text-gray-950 dark:text-white">
                Рынок
            </div>
            <div class="mt-1 text-sm leading-snug text-gray-500 dark:text-gray-400">
                Выбор рынка влияет на метрики. После изменения страница перезагрузится.
            </div>
        </div>

        <div class="flex min-w-0 flex-1 xl:justify-end">
            <div class="mt-1 w-full xl:mt-0 xl:max-w-sm">
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
