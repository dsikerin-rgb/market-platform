{{-- resources/views/filament/widgets/market-switcher-widget.blade.php --}}

<x-filament::section>
    <x-slot name="heading">Рынок</x-slot>
    <x-slot name="description">
        Выбор рынка обязателен для корректных метрик. После изменения страница перезагрузится.
    </x-slot>

    <div class="w-full max-w-sm">
        <x-filament::input.wrapper class="w-full">
            <x-filament::input.select wire:model.live="selectedMarketId">
                <option value="">— Выберите рынок —</option>

                @foreach ($markets as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </x-filament::input.select>
        </x-filament::input.wrapper>

        <div class="mt-2 text-sm text-gray-500 leading-snug">
            {{ $appliesNote ?? 'Применяется к данным панели (виджеты и списки ресурсов).' }}
        </div>
    </div>
</x-filament::section>