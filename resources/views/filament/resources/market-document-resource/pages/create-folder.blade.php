<x-filament-panels::page>
    <form wire:submit.prevent="create" class="space-y-6">
        {{ $this->form }}

        <div class="flex flex-wrap items-center gap-3">
            <x-filament::button type="submit" icon="heroicon-o-folder-plus">
                Создать папку
            </x-filament::button>

            <x-filament::button type="button" color="gray" wire:click="cancel">
                Отмена
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
