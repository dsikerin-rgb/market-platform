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
            ->withCount('comments')
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
            $singleTicketQuery = \App\Models\Ticket::query()
                ->with(['tenant:id,name,short_name', 'user:id,name'])
                ->withCount('comments')
                ->whereKey($requestedTicketId);

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

        $categoryMeta = [
            'repair' => ['icon' => 'heroicon-m-wrench-screwdriver', 'color' => 'warning'],
            'cleaning' => ['icon' => 'heroicon-m-sparkles', 'color' => 'info'],
            'payment' => ['icon' => 'heroicon-m-banknotes', 'color' => 'success'],
            'help' => ['icon' => 'heroicon-m-lifebuoy', 'color' => 'primary'],
            'other' => ['icon' => 'heroicon-m-chat-bubble-left-right', 'color' => 'gray'],
        ];

        $statusLabels = [
            'new' => 'Новая',
            'in_progress' => 'В работе',
            'on_hold' => 'Пауза',
            'resolved' => 'Решена',
            'closed' => 'Закрыта',
            'cancelled' => 'Отменена',
        ];

        $statusMeta = [
            'new' => ['icon' => 'heroicon-m-bolt', 'color' => 'warning'],
            'in_progress' => ['icon' => 'heroicon-m-arrow-path', 'color' => 'primary'],
            'on_hold' => ['icon' => 'heroicon-m-pause-circle', 'color' => 'gray'],
            'resolved' => ['icon' => 'heroicon-m-check-badge', 'color' => 'success'],
            'closed' => ['icon' => 'heroicon-m-lock-closed', 'color' => 'gray'],
            'cancelled' => ['icon' => 'heroicon-m-x-circle', 'color' => 'danger'],
        ];

        $priorityLabels = [
            'low' => 'Низкий',
            'normal' => 'Обычный',
            'medium' => 'Средний',
            'high' => 'Высокий',
            'urgent' => 'Срочный',
        ];

        $priorityColors = [
            'low' => 'gray',
            'normal' => 'primary',
            'medium' => 'warning',
            'high' => 'danger',
            'urgent' => 'danger',
        ];

        $baseParams = [];
        if ($tenantFilterId > 0) {
            $baseParams['tenant_id'] = $tenantFilterId;
        }

        $newCount = $tickets->where('status', 'new')->count();
        $inProgressCount = $tickets->where('status', 'in_progress')->count();
        $openCount = $tickets->whereIn('status', ['new', 'in_progress', 'on_hold'])->count();
        $commentedCount = $tickets->where('comments_count', '>', 0)->count();
    @endphp

    <div class="space-y-6">
        <section
            class="rounded-3xl border border-gray-200/80 bg-gradient-to-br from-white via-white to-gray-50/80 px-5 py-5 shadow-sm dark:border-white/10 dark:from-gray-900 dark:via-gray-900 dark:to-gray-950"
        >
            <div class="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
                <div class="space-y-3">
                    <div class="flex items-center gap-3">
                        <div class="flex h-11 w-11 items-center justify-center rounded-2xl bg-primary-500/10 text-primary-600 dark:bg-primary-400/15 dark:text-primary-300">
                            <x-filament::icon icon="heroicon-m-chat-bubble-left-right" class="h-6 w-6" />
                        </div>
                        <div>
                            <h2 class="text-2xl font-semibold tracking-tight text-gray-950 dark:text-white">Обращения</h2>
                            <p class="text-sm text-gray-500 dark:text-gray-400">Рабочий центр по обращениям арендаторов и внутренней переписке.</p>
                        </div>
                    </div>

                    @if ($tenantFilterId > 0)
                        @php
                            $tenantTitle = trim((string) ($tenantFilter?->short_name ?? $tenantFilter?->name ?? ''));
                        @endphp
                        <div class="inline-flex items-center gap-2 rounded-full border border-primary-200/80 bg-primary-50 px-3 py-1 text-xs font-medium text-primary-700 dark:border-primary-400/20 dark:bg-primary-400/10 dark:text-primary-200">
                            <x-filament::icon icon="heroicon-m-funnel" class="h-4 w-4" />
                            <span>Фильтр по арендатору: {{ $tenantTitle !== '' ? $tenantTitle : ('арендатор #' . $tenantFilterId) }}</span>
                        </div>
                    @endif
                </div>

                <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:min-w-[28rem]">
                    <div class="rounded-2xl border border-gray-200/70 bg-white/80 px-4 py-3 dark:border-white/10 dark:bg-white/5">
                        <div class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Всего</div>
                        <div class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ $tickets->count() }}</div>
                    </div>
                    <div class="rounded-2xl border border-gray-200/70 bg-white/80 px-4 py-3 dark:border-white/10 dark:bg-white/5">
                        <div class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Новые</div>
                        <div class="mt-1 text-2xl font-semibold text-warning-600 dark:text-warning-400">{{ $newCount }}</div>
                    </div>
                    <div class="rounded-2xl border border-gray-200/70 bg-white/80 px-4 py-3 dark:border-white/10 dark:bg-white/5">
                        <div class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">В работе</div>
                        <div class="mt-1 text-2xl font-semibold text-primary-600 dark:text-primary-300">{{ $inProgressCount }}</div>
                    </div>
                    <div class="rounded-2xl border border-gray-200/70 bg-white/80 px-4 py-3 dark:border-white/10 dark:bg-white/5">
                        <div class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">С ответами</div>
                        <div class="mt-1 text-2xl font-semibold text-success-600 dark:text-success-400">{{ $commentedCount }}</div>
                    </div>
                </div>
            </div>
        </section>

        <div class="grid gap-6 xl:grid-cols-[380px_minmax(0,1fr)]">
            <x-filament::section>
                <x-slot name="heading">
                    <div class="flex items-center gap-2">
                        <x-filament::icon icon="heroicon-m-inbox-stack" class="h-5 w-5 text-primary-500" />
                        <span>Диалоги</span>
                    </div>
                </x-slot>

                <x-slot name="description">
                    {{ $openCount > 0 ? "Открытых обращений: {$openCount}" : 'Нет открытых обращений.' }}
                </x-slot>

                @if ($tickets->isEmpty())
                    <div class="flex min-h-[18rem] flex-col items-center justify-center gap-3 rounded-2xl border border-dashed border-gray-300/80 bg-gray-50/70 px-6 py-10 text-center dark:border-white/10 dark:bg-white/[0.03]">
                        <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-gray-200/70 text-gray-500 dark:bg-white/10 dark:text-gray-400">
                            <x-filament::icon icon="heroicon-m-chat-bubble-left-ellipsis" class="h-6 w-6" />
                        </div>
                        <div class="space-y-1">
                            <div class="text-sm font-medium text-gray-900 dark:text-white">Нет диалогов</div>
                            <div class="text-sm text-gray-500 dark:text-gray-400">По текущему фильтру ещё нет обращений или переписки.</div>
                        </div>
                    </div>
                @else
                    <div class="space-y-3 max-h-[76vh] overflow-y-auto pr-1">
                        @foreach ($tickets as $ticket)
                            @php
                                $isSelected = $selectedTicket && (int) $selectedTicket->id === (int) $ticket->id;
                                $tenantName = trim((string) ($ticket->tenant?->short_name ?? $ticket->tenant?->name ?? '—'));
                                $ticketUrlParams = array_merge($baseParams, ['ticket_id' => (int) $ticket->id]);
                                $status = (string) $ticket->status;
                                $category = (string) $ticket->category;
                                $statusBadge = $statusMeta[$status] ?? ['icon' => 'heroicon-m-question-mark-circle', 'color' => 'gray'];
                                $categoryBadge = $categoryMeta[$category] ?? $categoryMeta['other'];
                            @endphp

                            <a
                                href="{{ \App\Filament\Pages\Requests::getUrl(parameters: $ticketUrlParams) }}"
                                class="group block rounded-2xl border p-4 transition duration-200 {{ $isSelected ? 'border-primary-300 bg-primary-50/90 shadow-sm dark:border-primary-500/40 dark:bg-primary-500/10' : 'border-gray-200/80 bg-white/80 hover:border-primary-200 hover:bg-gray-50 dark:border-white/10 dark:bg-white/[0.03] dark:hover:border-primary-500/25 dark:hover:bg-white/[0.05]' }}"
                            >
                                <div class="flex items-start gap-3">
                                    <div class="mt-0.5 flex h-10 w-10 items-center justify-center rounded-2xl {{ $isSelected ? 'bg-primary-500 text-white' : 'bg-gray-100 text-gray-500 dark:bg-white/10 dark:text-gray-300' }}">
                                        <x-filament::icon :icon="$categoryBadge['icon']" class="h-5 w-5" />
                                    </div>

                                    <div class="min-w-0 flex-1 space-y-3">
                                        <div class="flex items-start justify-between gap-3">
                                            <div class="min-w-0">
                                                <div class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                                                    <span class="font-medium">#{{ $ticket->id }}</span>
                                                    <span>•</span>
                                                    <span>{{ $ticket->updated_at?->format('d.m.Y H:i') }}</span>
                                                </div>
                                                <div class="mt-1 truncate text-sm font-semibold text-gray-950 dark:text-white">
                                                    {{ $ticket->subject ?: 'Без темы' }}
                                                </div>
                                            </div>

                                            <div class="shrink-0">
                                                <x-filament::badge :color="$statusBadge['color']">
                                                    {{ $statusLabels[$status] ?? $status }}
                                                </x-filament::badge>
                                            </div>
                                        </div>

                                        <div class="flex flex-wrap items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                                            <x-filament::badge :color="$categoryBadge['color']">
                                                {{ $categoryLabels[$category] ?? 'Другое' }}
                                            </x-filament::badge>

                                            <span class="truncate">{{ $tenantName }}</span>

                                            @if (($ticket->comments_count ?? 0) > 0)
                                                <span class="inline-flex items-center gap-1 rounded-full bg-gray-100 px-2 py-0.5 font-medium text-gray-600 dark:bg-white/10 dark:text-gray-300">
                                                    <x-filament::icon icon="heroicon-m-chat-bubble-left-ellipsis" class="h-3.5 w-3.5" />
                                                    {{ $ticket->comments_count }}
                                                </span>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            </a>
                        @endforeach
                    </div>
                @endif
            </x-filament::section>

            <x-filament::section>
                <x-slot name="heading">
                    <div class="flex items-center gap-2">
                        <x-filament::icon icon="heroicon-m-chat-bubble-left-right" class="h-5 w-5 text-primary-500" />
                        <span>Переписка</span>
                    </div>
                </x-slot>

                <x-slot name="description">
                    {{ $selectedTicket ? 'Полная карточка выбранного обращения и история диалога.' : 'Выберите диалог слева, чтобы открыть переписку.' }}
                </x-slot>

                @if (! $selectedTicket)
                    <div class="flex min-h-[28rem] flex-col items-center justify-center gap-3 rounded-2xl border border-dashed border-gray-300/80 bg-gray-50/70 px-6 py-10 text-center dark:border-white/10 dark:bg-white/[0.03]">
                        <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-gray-200/70 text-gray-500 dark:bg-white/10 dark:text-gray-400">
                            <x-filament::icon icon="heroicon-m-chat-bubble-left-right" class="h-6 w-6" />
                        </div>
                        <div class="space-y-1">
                            <div class="text-sm font-medium text-gray-900 dark:text-white">Диалог не выбран</div>
                            <div class="text-sm text-gray-500 dark:text-gray-400">Откройте нужное обращение слева, чтобы посмотреть переписку и ответить.</div>
                        </div>
                    </div>
                @else
                    @php
                        $tenantName = trim((string) ($selectedTicket->tenant?->short_name ?? $selectedTicket->tenant?->name ?? '—'));
                        $status = (string) $selectedTicket->status;
                        $category = (string) $selectedTicket->category;
                        $statusBadge = $statusMeta[$status] ?? ['icon' => 'heroicon-m-question-mark-circle', 'color' => 'gray'];
                        $categoryBadge = $categoryMeta[$category] ?? $categoryMeta['other'];
                        $assignedTo = trim((string) ($selectedTicket->user?->name ?? ''));
                        $priority = (string) ($selectedTicket->priority ?? 'normal');
                    @endphp

                    <div class="space-y-4">
                        <div class="rounded-2xl border border-gray-200/80 bg-white/70 p-5 dark:border-white/10 dark:bg-white/[0.03]">
                            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                                <div class="space-y-3">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                            Обращение #{{ $selectedTicket->id }}
                                        </div>

                                        <x-filament::badge :color="$statusBadge['color']">
                                            {{ $statusLabels[$status] ?? $status }}
                                        </x-filament::badge>

                                        <x-filament::badge :color="$categoryBadge['color']">
                                            {{ $categoryLabels[$category] ?? 'Другое' }}
                                        </x-filament::badge>

                                        <x-filament::badge :color="$priorityColors[$priority] ?? 'gray'">
                                            {{ $priorityLabels[$priority] ?? 'Обычный' }}
                                        </x-filament::badge>
                                    </div>

                                    <div class="space-y-1">
                                        <h3 class="text-xl font-semibold tracking-tight text-gray-950 dark:text-white">
                                            {{ $selectedTicket->subject ?: 'Без темы' }}
                                        </h3>

                                        @if (filled($selectedTicket->description))
                                            <p class="max-w-3xl whitespace-pre-wrap text-sm leading-6 text-gray-600 dark:text-gray-300">
                                                {{ $selectedTicket->description }}
                                            </p>
                                        @endif
                                    </div>
                                </div>

                                <div class="grid grid-cols-2 gap-3 sm:min-w-[20rem]">
                                    <div class="rounded-2xl border border-gray-200/80 bg-gray-50/80 p-3 dark:border-white/10 dark:bg-white/[0.04]">
                                        <div class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Арендатор</div>
                                        <div class="mt-1 text-sm font-medium text-gray-900 dark:text-white">{{ $tenantName }}</div>
                                    </div>
                                    <div class="rounded-2xl border border-gray-200/80 bg-gray-50/80 p-3 dark:border-white/10 dark:bg-white/[0.04]">
                                        <div class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Назначен</div>
                                        <div class="mt-1 text-sm font-medium text-gray-900 dark:text-white">{{ $assignedTo !== '' ? $assignedTo : 'Не назначен' }}</div>
                                    </div>
                                    <div class="rounded-2xl border border-gray-200/80 bg-gray-50/80 p-3 dark:border-white/10 dark:bg-white/[0.04]">
                                        <div class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Создано</div>
                                        <div class="mt-1 text-sm font-medium text-gray-900 dark:text-white">{{ $selectedTicket->created_at?->format('d.m.Y H:i') }}</div>
                                    </div>
                                    <div class="rounded-2xl border border-gray-200/80 bg-gray-50/80 p-3 dark:border-white/10 dark:bg-white/[0.04]">
                                        <div class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Последнее обновление</div>
                                        <div class="mt-1 text-sm font-medium text-gray-900 dark:text-white">{{ $selectedTicket->updated_at?->format('d.m.Y H:i') }}</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="rounded-2xl border border-gray-200/80 bg-gray-50/60 p-4 dark:border-white/10 dark:bg-white/[0.02]">
                            <div class="mb-4 flex items-center justify-between gap-3">
                                <div>
                                    <h4 class="text-sm font-semibold text-gray-900 dark:text-white">Лента сообщений</h4>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">Сообщения идут в хронологическом порядке.</p>
                                </div>
                                <div class="rounded-full bg-white px-3 py-1 text-xs font-medium text-gray-500 shadow-sm ring-1 ring-gray-200/80 dark:bg-white/5 dark:text-gray-300 dark:ring-white/10">
                                    {{ $comments->count() }} в ленте
                                </div>
                            </div>

                            <div class="space-y-3 max-h-[48vh] overflow-y-auto pr-1">
                                @forelse ($comments as $comment)
                                    @php
                                        $isOwn = $user && (int) $comment->user_id === (int) $user->id;
                                    @endphp

                                    <div class="flex {{ $isOwn ? 'justify-end' : 'justify-start' }}">
                                        <div class="max-w-3xl rounded-2xl px-4 py-3 shadow-sm ring-1 {{ $isOwn ? 'bg-primary-500 text-white ring-primary-400/30' : 'bg-white text-gray-900 ring-gray-200/80 dark:bg-white/[0.06] dark:text-gray-100 dark:ring-white/10' }}">
                                            <div class="mb-2 flex items-center gap-2 text-xs {{ $isOwn ? 'text-white/80' : 'text-gray-500 dark:text-gray-400' }}">
                                                <span class="font-medium">{{ $comment->user?->name ?? 'Пользователь' }}</span>
                                                <span>•</span>
                                                <span>{{ $comment->created_at?->format('d.m.Y H:i') }}</span>
                                            </div>
                                            <div class="whitespace-pre-wrap text-sm leading-6">{{ $comment->body }}</div>
                                        </div>
                                    </div>
                                @empty
                                    <div class="flex min-h-[14rem] flex-col items-center justify-center gap-3 rounded-2xl border border-dashed border-gray-300/80 bg-white/60 px-6 py-10 text-center dark:border-white/10 dark:bg-white/[0.03]">
                                        <div class="flex h-10 w-10 items-center justify-center rounded-2xl bg-gray-200/70 text-gray-500 dark:bg-white/10 dark:text-gray-400">
                                            <x-filament::icon icon="heroicon-m-chat-bubble-bottom-center-text" class="h-5 w-5" />
                                        </div>
                                        <div class="space-y-1">
                                            <div class="text-sm font-medium text-gray-900 dark:text-white">Пока нет сообщений</div>
                                            <div class="text-sm text-gray-500 dark:text-gray-400">Напишите первый ответ, чтобы начать переписку.</div>
                                        </div>
                                    </div>
                                @endforelse
                            </div>
                        </div>

                        <form
                            method="POST"
                            action="{{ route('filament.admin.requests.comment', ['ticket' => (int) $selectedTicket->id]) }}"
                            class="rounded-2xl border border-gray-200/80 bg-white/80 p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]"
                        >
                            @csrf

                            <div class="space-y-3">
                                <div class="flex items-center justify-between gap-3">
                                    <label class="text-sm font-semibold text-gray-900 dark:text-white">
                                        Ответ в диалог
                                    </label>
                                    <div class="text-xs text-gray-500 dark:text-gray-400">
                                        Отправка сразу добавит сообщение в выбранное обращение
                                    </div>
                                </div>

                                <textarea
                                    name="body"
                                    rows="4"
                                    required
                                    placeholder="Опишите решение, уточнение или следующий шаг."
                                    class="fi-input block w-full rounded-2xl border-gray-300 bg-white px-4 py-3 text-sm shadow-sm transition focus:border-primary-500 focus:ring-primary-500 dark:border-gray-700 dark:bg-gray-900"
                                >{{ old('body') }}</textarea>

                                @error('body')
                                    <div class="text-sm text-danger-600">{{ $message }}</div>
                                @enderror

                                <div class="flex justify-end">
                                    <x-filament::button type="submit" icon="heroicon-o-paper-airplane">
                                        Отправить сообщение
                                    </x-filament::button>
                                </div>
                            </div>
                        </form>
                    </div>
                @endif
            </x-filament::section>
        </div>
    </div>
</x-filament-panels::page>
