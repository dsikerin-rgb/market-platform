{{-- resources/views/filament/pages/requests.blade.php --}}

<x-filament-panels::page>
    @php
        $user = \Filament\Facades\Filament::auth()->user();

        $tenantFilterId = max(0, (int) request('tenant_id'));

        $tenantFilter = null;
        if ($tenantFilterId > 0) {
            $tenantFilter = \App\Models\Tenant::query()
                ->select(['id', 'market_id', 'name', 'short_name'])
                ->find($tenantFilterId);
        }

        $ticketsQuery = \App\Models\Ticket::query()
            ->with(['tenant:id,name,short_name', 'user:id,name'])
            ->orderByDesc('updated_at')
            ->orderByDesc('id');

        $isSuperAdmin = $user && method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin();

        if (! $isSuperAdmin) {
            $marketId = (int) ($user->market_id ?? 0);

            if ($marketId > 0) {
                $ticketsQuery->where('market_id', $marketId);
            } else {
                $ticketsQuery->whereRaw('1 = 0');
            }
        }

        if ($tenantFilterId > 0) {
            $ticketsQuery->where('tenant_id', $tenantFilterId);
        }

        $tickets = $ticketsQuery->limit(100)->get();

        $requestedTicketId = (int) request('ticket_id');

        $selectedTicket = $requestedTicketId > 0
            ? $tickets->firstWhere('id', $requestedTicketId)
            : $tickets->first();

        if (! $selectedTicket && $requestedTicketId > 0) {
            $singleTicketQuery = \App\Models\Ticket::query()->whereKey($requestedTicketId);

            if (! $isSuperAdmin) {
                $marketId = (int) ($user->market_id ?? 0);

                if ($marketId > 0) {
                    $singleTicketQuery->where('market_id', $marketId);
                } else {
                    $singleTicketQuery->whereRaw('1 = 0');
                }
            }

            if ($tenantFilterId > 0) {
                $singleTicketQuery->where('tenant_id', $tenantFilterId);
            }

            $selectedTicket = $singleTicketQuery->first();
        }

        $comments = collect();

        if ($selectedTicket) {
            $comments = \App\Models\TicketComment::query()
                ->where('ticket_id', (int) $selectedTicket->id)
                ->with('user:id,name')
                ->orderBy('created_at')
                ->get();
        }

        $categoryLabels = [
            'repair' => 'Ремонт',
            'cleaning' => 'Уборка',
            'payment' => 'Оплата',
            'help' => 'Помощь',
            'other' => 'Другое',
        ];

        $statusLabels = [
            'new' => 'Новая',
            'in_progress' => 'В работе',
            'on_hold' => 'Пауза',
            'resolved' => 'Решена',
            'closed' => 'Закрыта',
            'cancelled' => 'Отменена',
        ];

        $baseParams = [];
        if ($tenantFilterId > 0) {
            $baseParams['tenant_id'] = $tenantFilterId;
        }
    @endphp

    <div class="grid gap-6 lg:grid-cols-[360px_minmax(0,1fr)]">
        <x-filament::section>
            <x-slot name="heading">Диалоги</x-slot>

            @if ($tenantFilterId > 0)
                <div class="mb-3 text-xs text-gray-500">
                    @php
                        $tenantTitle = trim((string) ($tenantFilter?->short_name ?? $tenantFilter?->name ?? ''));
                    @endphp
                    Фильтр: {{ $tenantTitle !== '' ? $tenantTitle : ('арендатор #' . $tenantFilterId) }}
                </div>
            @endif

            @if ($tickets->isEmpty())
                <div class="text-sm text-gray-500">Нет диалогов по выбранному фильтру.</div>
            @else
                <div class="space-y-2 max-h-[70vh] overflow-y-auto pr-1">
                    @foreach ($tickets as $ticket)
                        @php
                            $isSelected = $selectedTicket && (int) $selectedTicket->id === (int) $ticket->id;
                            $tenantName = trim((string) ($ticket->tenant?->short_name ?? $ticket->tenant?->name ?? '—'));

                            $ticketUrlParams = array_merge($baseParams, [
                                'ticket_id' => (int) $ticket->id,
                            ]);
                        @endphp

                        <a
                            href="{{ \App\Filament\Pages\Requests::getUrl(parameters: $ticketUrlParams) }}"
                            class="block rounded-xl border p-3 transition {{ $isSelected ? 'border-primary-500 bg-primary-50 dark:border-primary-400 dark:bg-primary-900/20' : 'border-gray-200 hover:border-gray-300 dark:border-gray-800 dark:hover:border-gray-700' }}"
                        >
                            <div class="text-xs text-gray-500">#{{ $ticket->id }} • {{ $statusLabels[$ticket->status] ?? $ticket->status }}</div>
                            <div class="mt-1 text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $ticket->subject }}</div>
                            <div class="mt-1 text-xs text-gray-500">{{ $categoryLabels[$ticket->category] ?? 'Другое' }} • {{ $tenantName }}</div>
                            <div class="mt-1 text-xs text-gray-400">{{ $ticket->updated_at?->format('d.m.Y H:i') }}</div>
                        </a>
                    @endforeach
                </div>
            @endif
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Переписка</x-slot>

            @if (! $selectedTicket)
                <div class="text-sm text-gray-500">Выберите диалог слева.</div>
            @else
                @php
                    $tenantName = trim((string) ($selectedTicket->tenant?->short_name ?? $selectedTicket->tenant?->name ?? '—'));
                @endphp

                <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-800">
                    <div class="text-xs text-gray-500">#{{ $selectedTicket->id }} • {{ $statusLabels[$selectedTicket->status] ?? $selectedTicket->status }}</div>
                    <div class="mt-1 text-lg font-semibold text-gray-900 dark:text-gray-100">{{ $selectedTicket->subject }}</div>
                    <div class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ $selectedTicket->description }}</div>
                    <div class="mt-3 text-xs text-gray-500">Арендатор: {{ $tenantName }} • Категория: {{ $categoryLabels[$selectedTicket->category] ?? 'Другое' }}</div>
                </div>

                <div class="mt-4 space-y-3 max-h-[50vh] overflow-y-auto pr-1">
                    @forelse ($comments as $comment)
                        <div class="rounded-xl border border-gray-200 p-3 dark:border-gray-800">
                            <div class="text-xs text-gray-500">{{ $comment->user?->name ?? 'Пользователь' }} • {{ $comment->created_at?->format('d.m.Y H:i') }}</div>
                            <div class="mt-1 text-sm text-gray-800 dark:text-gray-200 whitespace-pre-wrap">{{ $comment->body }}</div>
                        </div>
                    @empty
                        <div class="text-sm text-gray-500">В этом диалоге пока нет сообщений.</div>
                    @endforelse
                </div>

                <form
                    method="POST"
                    action="{{ route('filament.admin.requests.comment', ['ticket' => (int) $selectedTicket->id]) }}"
                    class="mt-4 space-y-3"
                >
                    @csrf

                    <label class="fi-fo-field-wrp-label block text-sm font-medium text-gray-700 dark:text-gray-300">
                        Ответ
                    </label>
                    <textarea
                        name="body"
                        rows="4"
                        required
                        class="fi-input block w-full rounded-lg border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-700 dark:bg-gray-900"
                    >{{ old('body') }}</textarea>

                    @error('body')
                        <div class="text-sm text-danger-600">{{ $message }}</div>
                    @enderror

                    <x-filament::button type="submit" icon="heroicon-o-paper-airplane">
                        Отправить сообщение
                    </x-filament::button>
                </form>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
