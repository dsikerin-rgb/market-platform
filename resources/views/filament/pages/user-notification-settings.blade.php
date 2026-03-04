<x-filament-panels::page>
    <div class="mx-auto w-full max-w-3xl">
        <div class="mb-6 rounded-xl border border-gray-200 bg-white/80 p-4 dark:border-gray-700 dark:bg-gray-900/60">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h3 class="text-base font-semibold">Telegram</h3>
                    @if (filled($currentUser?->telegram_chat_id))
                        <p class="text-sm text-green-600 dark:text-green-400">
                            Подключено (chat_id: {{ $currentUser->telegram_chat_id }})
                        </p>
                    @else
                        <p class="text-sm text-gray-500">Не подключено</p>
                    @endif
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <x-filament::button type="button" wire:click="generateTelegramConnectLink" size="sm" icon="heroicon-o-link">
                        Подключить Telegram
                    </x-filament::button>
                    <x-filament::button type="button" wire:click="refreshTelegramStatus" size="sm" color="gray" icon="heroicon-o-arrow-path">
                        Обновить статус
                    </x-filament::button>
                </div>
            </div>

            @if ($telegramLinkData)
                <div class="space-y-2 rounded-lg border border-dashed border-gray-300 p-3 text-sm dark:border-gray-600">
                    @if (! empty($telegramLinkData['deep_link']))
                        <div>
                            1. Откройте ссылку:
                            <a href="{{ $telegramLinkData['deep_link'] }}" target="_blank" rel="noopener noreferrer" class="text-primary-600 underline">
                                {{ $telegramLinkData['deep_link'] }}
                            </a>
                        </div>
                    @elseif (! empty($telegramLinkData['bot_username']))
                        <div>
                            1. Откройте бота: <strong>@{{ $telegramLinkData['bot_username'] }}</strong>
                        </div>
                    @endif

                    <div>2. Отправьте команду боту:</div>
                    <pre class="overflow-x-auto rounded bg-gray-100 px-2 py-1 dark:bg-gray-800">{{ $telegramLinkData['command'] }}</pre>

                    <div class="text-gray-500 dark:text-gray-400">
                        Ссылка действует до {{ $telegramLinkData['expires_at'] }}.
                    </div>
                </div>
            @endif
        </div>

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
