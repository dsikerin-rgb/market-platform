<x-filament::section>
    <x-slot name="heading">Фильтр по рынку</x-slot>

    <div class="flex flex-wrap items-center gap-3">
        <div class="w-full max-w-sm">
            <x-filament::input.wrapper>
                <x-filament::input.select wire:model.live="selectedMarketId">
                    <option value="">Все рынки</option>

                    @foreach ($markets as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </div>

        <div class="text-sm text-gray-500">
            Применяется к виджетам на дашборде.
        </div>
    </div>
</x-filament::section>
