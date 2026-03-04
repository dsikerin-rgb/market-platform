<x-filament-panels::page>
    <div class="mx-auto w-full max-w-3xl">
        <form wire:submit.prevent="save" class="space-y-6">
            {{ $this->form }}

            <div class="rounded-xl border border-gray-200 bg-white/80 p-4 dark:border-gray-700 dark:bg-gray-900/60">
                @if ($canSelfManage)
                    <div class="flex items-center gap-3">
                        <x-filament::button type="submit" icon="heroicon-o-check" color="primary">
                            Сохранить
                        </x-filament::button>
                        <p class="text-sm text-gray-500">Изменения применяются сразу после сохранения.</p>
                    </div>
                @else
                    <p class="text-sm text-gray-500">
                        Для вашей роли изменения выполняет super-admin или market-admin.
                    </p>
                @endif
            </div>
        </form>
    </div>
</x-filament-panels::page>
