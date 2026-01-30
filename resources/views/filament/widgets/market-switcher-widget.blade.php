{{-- resources/views/filament/widgets/market-switcher-widget.blade.php --}}

<x-filament::section>
    <x-slot name="heading">Рынок</x-slot>
    <x-slot name="description">
        Выбор рынка обязателен для корректных метрик. После изменения страница перезагрузится.
    </x-slot>

    <div class="flex flex-wrap items-center gap-3">
        <div class="w-full max-w-sm">
            <x-filament::input.wrapper>
                <x-filament::input.select
                    wire:model.live="selectedMarketId"
                >
                    <option value="">— Выберите рынок —</option>

                    @foreach ($markets as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </div>

        <div class="text-sm text-gray-500">
            Применяется к данным панели (виджеты и списки ресурсов).
        </div>
    </div>
</x-filament::section>
