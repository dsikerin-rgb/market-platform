{{-- resources/views/filament/pages/requests.blade.php --}}

<x-filament-panels::page>
    <div class="grid gap-6">
        <x-filament::section>
            <x-slot name="heading">Обращения</x-slot>

            @php
                $ticketId = request('ticket_id');
            @endphp

            @if ($ticketId)
                <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-100">
                    <div class="font-semibold">Открыто из уведомления</div>

                    <div class="mt-1">
                        Заявка #{{ $ticketId }}. Сейчас эта страница — заглушка; следующим шагом добавим список обращений и переход к конкретной записи.
                    </div>

                    <div class="mt-3">
                        <a
                            href="{{ url()->current() }}"
                            class="inline-flex items-center rounded-lg bg-white px-3 py-2 text-sm font-medium text-gray-900 shadow-sm ring-1 ring-gray-200 hover:bg-gray-50 dark:bg-gray-900 dark:text-gray-100 dark:ring-gray-800"
                        >
                            Сбросить
                        </a>
                    </div>
                </div>
            @endif

            <div class="text-sm text-gray-500">
                Здесь будут отображаться обращения сотрудников и арендаторов.
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
