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

    <style>
        .requests-workspace {
            --requests-border: rgba(15, 23, 42, 0.10);
            --requests-border-strong: rgba(37, 99, 235, 0.22);
            --requests-surface: #ffffff;
            --requests-surface-soft: #f8fafc;
            --requests-surface-strong: #eef2ff;
            --requests-card: #ffffff;
            --requests-card-hover: #f8fafc;
            --requests-card-selected: linear-gradient(180deg, #eff6ff, #dbeafe);
            --requests-card-selected-border: rgba(37, 99, 235, 0.34);
            --requests-panel: #ffffff;
            --requests-panel-soft: #f8fafc;
            --requests-thread: #f8fafc;
            --requests-composer: #ffffff;
            --requests-text: #0f172a;
            --requests-heading: #0f172a;
            --requests-muted: #64748b;
            --requests-muted-strong: #475569;
            --requests-hero-text: #f8fafc;
            --requests-hero-muted: #cbd5e1;
            --requests-shadow: rgba(15, 23, 42, 0.08);
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .dark .requests-workspace {
            --requests-border: rgba(148, 163, 184, 0.18);
            --requests-border-strong: rgba(96, 165, 250, 0.34);
            --requests-surface: rgba(15, 23, 42, 0.72);
            --requests-surface-soft: rgba(15, 23, 42, 0.58);
            --requests-surface-strong: rgba(15, 23, 42, 0.92);
            --requests-card: rgba(15, 23, 42, 0.68);
            --requests-card-hover: rgba(15, 23, 42, 0.78);
            --requests-card-selected: linear-gradient(180deg, rgba(30, 41, 59, 0.96), rgba(15, 23, 42, 0.92));
            --requests-card-selected-border: rgba(96, 165, 250, 0.50);
            --requests-panel: rgba(15, 23, 42, 0.72);
            --requests-panel-soft: rgba(255, 255, 255, 0.03);
            --requests-thread: rgba(15, 23, 42, 0.58);
            --requests-composer: rgba(15, 23, 42, 0.72);
            --requests-text: #f8fafc;
            --requests-heading: #f8fafc;
            --requests-muted: #94a3b8;
            --requests-muted-strong: #cbd5e1;
            --requests-hero-text: #f8fafc;
            --requests-hero-muted: #94a3b8;
            --requests-shadow: rgba(15, 23, 42, 0.18);
        }

        .requests-hero {
            border: 1px solid var(--requests-border);
            border-radius: 1.5rem;
            background:
                radial-gradient(circle at top left, rgba(59, 130, 246, 0.18), transparent 28%),
                radial-gradient(circle at top right, rgba(16, 185, 129, 0.14), transparent 24%),
                linear-gradient(180deg, #eff6ff, #dbeafe);
            padding: 1.5rem;
            box-shadow: 0 24px 60px var(--requests-shadow);
        }

        .dark .requests-hero {
            background:
                radial-gradient(circle at top left, rgba(56, 189, 248, 0.08), transparent 28%),
                radial-gradient(circle at top right, rgba(16, 185, 129, 0.09), transparent 24%),
                linear-gradient(180deg, rgba(15, 23, 42, 0.96), rgba(15, 23, 42, 0.92));
        }

        .requests-hero-row {
            display: flex;
            gap: 1.25rem;
            justify-content: space-between;
            align-items: flex-end;
            flex-wrap: wrap;
        }

        .requests-hero-main {
            display: flex;
            flex-direction: column;
            gap: 0.85rem;
            max-width: 44rem;
        }

        .requests-hero-title {
            display: flex;
            align-items: center;
            gap: 0.9rem;
        }

        .requests-hero-icon {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 3rem;
            height: 3rem;
            border-radius: 1rem;
            background: rgba(37, 99, 235, 0.12);
            color: #1d4ed8;
        }

        .dark .requests-hero-icon {
            background: rgba(59, 130, 246, 0.12);
            color: rgb(147, 197, 253);
        }

        .requests-hero-copy h2 {
            margin: 0;
            font-size: 2rem;
            line-height: 1.1;
            font-weight: 700;
            color: var(--requests-heading);
        }

        .requests-hero-copy p {
            margin: 0.35rem 0 0;
            font-size: 0.95rem;
            color: var(--requests-muted);
        }

        .requests-filter-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            align-self: flex-start;
            padding: 0.5rem 0.85rem;
            border-radius: 999px;
            border: 1px solid rgba(37, 99, 235, 0.22);
            background: rgba(37, 99, 235, 0.08);
            color: #1d4ed8;
            font-size: 0.78rem;
            font-weight: 600;
        }

        .dark .requests-filter-pill {
            border: 1px solid rgba(59, 130, 246, 0.28);
            background: rgba(37, 99, 235, 0.12);
            color: #dbeafe;
        }

        .requests-stat-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 0.85rem;
            min-width: min(100%, 28rem);
        }

        .requests-stat {
            border-radius: 1rem;
            padding: 0.95rem 1rem;
            border: 1px solid var(--requests-border);
            background: rgba(255, 255, 255, 0.55);
        }

        .requests-stat-label {
            font-size: 0.7rem;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--requests-muted);
        }

        .requests-stat-value {
            margin-top: 0.3rem;
            font-size: 1.75rem;
            line-height: 1;
            font-weight: 700;
            color: var(--requests-heading);
        }

        .dark .requests-stat {
            background: rgba(15, 23, 42, 0.55);
        }

        .requests-stat-value.is-warning { color: #fbbf24; }
        .requests-stat-value.is-primary { color: #60a5fa; }
        .requests-stat-value.is-success { color: #34d399; }

        .requests-layout {
            display: grid;
            gap: 1.5rem;
            grid-template-columns: minmax(0, 380px) minmax(0, 1fr);
        }

        .requests-section-heading {
            display: inline-flex;
            align-items: center;
            gap: 0.55rem;
        }

        .requests-empty {
            display: flex;
            min-height: 18rem;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 0.8rem;
            padding: 2rem 1.5rem;
            border-radius: 1rem;
            border: 1px dashed var(--requests-border);
            background: var(--requests-surface-soft);
            text-align: center;
        }

        .requests-empty-icon {
            display: flex;
            width: 3rem;
            height: 3rem;
            align-items: center;
            justify-content: center;
            border-radius: 1rem;
            background: rgba(148, 163, 184, 0.12);
            color: var(--requests-muted);
        }

        .requests-empty-title {
            font-size: 0.95rem;
            font-weight: 600;
            color: var(--requests-heading);
        }

        .requests-empty-copy {
            font-size: 0.9rem;
            color: var(--requests-muted);
        }

        .requests-ticket-list {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            max-height: 76vh;
            overflow-y: auto;
            padding-right: 0.25rem;
        }

        .requests-ticket-card {
            display: block;
            padding: 1rem;
            border-radius: 1rem;
            border: 1px solid var(--requests-border);
            background: var(--requests-card);
            text-decoration: none;
            transition: transform 150ms ease, border-color 150ms ease, background 150ms ease, box-shadow 150ms ease;
        }

        .requests-ticket-card:hover {
            transform: translateY(-1px);
            border-color: var(--requests-border-strong);
            background: var(--requests-card-hover);
            box-shadow: 0 18px 36px var(--requests-shadow);
        }

        .requests-ticket-card.is-selected {
            border-color: var(--requests-card-selected-border);
            background: var(--requests-card-selected);
            box-shadow: 0 18px 40px rgba(59, 130, 246, 0.10);
        }

        .requests-ticket-row {
            display: flex;
            align-items: flex-start;
            gap: 0.85rem;
        }

        .requests-ticket-avatar {
            display: flex;
            width: 2.5rem;
            height: 2.5rem;
            align-items: center;
            justify-content: center;
            border-radius: 0.9rem;
            background: rgba(148, 163, 184, 0.12);
            color: var(--requests-muted-strong);
            flex-shrink: 0;
        }

        .requests-ticket-card.is-selected .requests-ticket-avatar {
            background: #2563eb;
            color: #fff;
        }

        .requests-ticket-body {
            min-width: 0;
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            flex: 1;
        }

        .requests-ticket-meta {
            display: flex;
            align-items: center;
            gap: 0.45rem;
            font-size: 0.72rem;
            color: var(--requests-muted);
        }

        .requests-ticket-subject {
            margin-top: 0.25rem;
            font-size: 0.95rem;
            font-weight: 700;
            color: var(--requests-heading);
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .requests-ticket-tags {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.45rem;
            font-size: 0.78rem;
            color: var(--requests-muted-strong);
        }

        .requests-comment-count {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.2rem 0.5rem;
            border-radius: 999px;
            background: rgba(148, 163, 184, 0.10);
            color: var(--requests-muted-strong);
            font-weight: 600;
        }

        .requests-details {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .requests-details-card {
            border-radius: 1rem;
            border: 1px solid var(--requests-border);
            background: var(--requests-panel);
            padding: 1.25rem;
        }

        .requests-details-top {
            display: flex;
            gap: 1rem;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
        }

        .requests-details-title {
            margin: 0.35rem 0 0;
            font-size: 1.4rem;
            line-height: 1.2;
            font-weight: 700;
            color: var(--requests-heading);
        }

        .requests-details-description {
            margin: 0.35rem 0 0;
            max-width: 48rem;
            white-space: pre-wrap;
            font-size: 0.93rem;
            line-height: 1.65;
            color: var(--requests-muted-strong);
        }

        .requests-meta-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0.75rem;
            min-width: min(100%, 20rem);
        }

        .requests-meta-card {
            padding: 0.8rem 0.9rem;
            border-radius: 0.9rem;
            border: 1px solid var(--requests-border);
            background: var(--requests-panel-soft);
        }

        .requests-meta-label {
            font-size: 0.68rem;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--requests-muted);
        }

        .requests-meta-value {
            margin-top: 0.35rem;
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--requests-heading);
        }

        .requests-thread {
            border-radius: 1rem;
            border: 1px solid var(--requests-border);
            background: var(--requests-thread);
            padding: 1rem;
        }

        .requests-thread-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .requests-thread-title {
            font-size: 0.95rem;
            font-weight: 700;
            color: var(--requests-heading);
        }

        .requests-thread-subtitle {
            margin-top: 0.2rem;
            font-size: 0.8rem;
            color: var(--requests-muted);
        }

        .requests-thread-count {
            border-radius: 999px;
            padding: 0.35rem 0.75rem;
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--requests-muted-strong);
            background: rgba(148, 163, 184, 0.10);
            border: 1px solid var(--requests-border);
        }

        .requests-thread-list {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            max-height: 48vh;
            overflow-y: auto;
            padding-right: 0.25rem;
        }

        .requests-thread-row {
            display: flex;
        }

        .requests-thread-row.is-own {
            justify-content: flex-end;
        }

        .requests-message {
            max-width: 48rem;
            border-radius: 1rem;
            padding: 0.9rem 1rem;
            border: 1px solid var(--requests-border);
            background: var(--requests-surface);
            color: var(--requests-text);
            box-shadow: 0 12px 24px var(--requests-shadow);
        }

        .requests-message.is-own {
            background: linear-gradient(180deg, #2563eb, #1d4ed8);
            border-color: rgba(96, 165, 250, 0.32);
        }

        .requests-message-meta {
            display: flex;
            align-items: center;
            gap: 0.45rem;
            margin-bottom: 0.45rem;
            font-size: 0.74rem;
            color: var(--requests-muted);
        }

        .requests-message.is-own .requests-message-meta {
            color: rgba(255, 255, 255, 0.78);
        }

        .requests-message-body {
            white-space: pre-wrap;
            font-size: 0.93rem;
            line-height: 1.65;
        }

        .requests-composer {
            border-radius: 1rem;
            border: 1px solid var(--requests-border);
            background: var(--requests-composer);
            padding: 1rem;
            box-shadow: 0 18px 36px var(--requests-shadow);
        }

        .requests-composer-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            margin-bottom: 0.85rem;
        }

        .requests-composer-label {
            font-size: 0.95rem;
            font-weight: 700;
            color: var(--requests-heading);
        }

        .requests-composer-note {
            font-size: 0.78rem;
            color: var(--requests-muted);
        }

        .requests-composer textarea {
            width: 100%;
            min-height: 7rem;
            resize: vertical;
            border-radius: 1rem;
            border: 1px solid var(--requests-border);
            background: var(--requests-surface-soft);
            color: var(--requests-text);
            padding: 0.9rem 1rem;
            font-size: 0.92rem;
            line-height: 1.6;
            outline: none;
        }

        .requests-composer textarea::placeholder {
            color: var(--requests-muted);
        }

        .requests-composer textarea:focus {
            border-color: rgba(96, 165, 250, 0.52);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.16);
        }

        .requests-composer-actions {
            display: flex;
            justify-content: flex-end;
            margin-top: 0.85rem;
        }

        @media (max-width: 1279px) {
            .requests-layout {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 767px) {
            .requests-hero {
                padding: 1.15rem;
            }

            .requests-stat-grid,
            .requests-meta-grid {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media (max-width: 520px) {
            .requests-stat-grid,
            .requests-meta-grid {
                grid-template-columns: 1fr;
            }

            .requests-hero-copy h2 {
                font-size: 1.65rem;
            }

            .requests-composer-head,
            .requests-thread-head {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>

    <div class="requests-workspace">
        <section class="requests-hero">
            <div class="requests-hero-row">
                <div class="requests-hero-main">
                    <div class="requests-hero-title">
                        <div class="requests-hero-icon">
                            <x-filament::icon icon="heroicon-m-chat-bubble-left-right" class="h-6 w-6" />
                        </div>
                        <div class="requests-hero-copy">
                            <h2>Обращения</h2>
                            <p>Рабочий центр по обращениям арендаторов и внутренней переписке.</p>
                        </div>
                    </div>

                    @if ($tenantFilterId > 0)
                        @php
                            $tenantTitle = trim((string) ($tenantFilter?->short_name ?? $tenantFilter?->name ?? ''));
                        @endphp
                        <div class="requests-filter-pill">
                            <x-filament::icon icon="heroicon-m-funnel" class="h-4 w-4" />
                            <span>Фильтр по арендатору: {{ $tenantTitle !== '' ? $tenantTitle : ('арендатор #' . $tenantFilterId) }}</span>
                        </div>
                    @endif
                </div>

                <div class="requests-stat-grid">
                    <div class="requests-stat">
                        <div class="requests-stat-label">Всего</div>
                        <div class="requests-stat-value">{{ $tickets->count() }}</div>
                    </div>
                    <div class="requests-stat">
                        <div class="requests-stat-label">Новые</div>
                        <div class="requests-stat-value is-warning">{{ $newCount }}</div>
                    </div>
                    <div class="requests-stat">
                        <div class="requests-stat-label">В работе</div>
                        <div class="requests-stat-value is-primary">{{ $inProgressCount }}</div>
                    </div>
                    <div class="requests-stat">
                        <div class="requests-stat-label">С ответами</div>
                        <div class="requests-stat-value is-success">{{ $commentedCount }}</div>
                    </div>
                </div>
            </div>
        </section>

        <div class="requests-layout">
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
                    <div class="requests-empty">
                        <div class="requests-empty-icon">
                            <x-filament::icon icon="heroicon-m-chat-bubble-left-ellipsis" class="h-6 w-6" />
                        </div>
                        <div>
                            <div class="requests-empty-title">Нет диалогов</div>
                            <div class="requests-empty-copy">По текущему фильтру ещё нет обращений или переписки.</div>
                        </div>
                    </div>
                @else
                    <div class="requests-ticket-list">
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
                                class="requests-ticket-card {{ $isSelected ? 'is-selected' : '' }}"
                            >
                                <div class="requests-ticket-row">
                                    <div class="requests-ticket-avatar">
                                        <x-filament::icon :icon="$categoryBadge['icon']" class="h-5 w-5" />
                                    </div>

                                    <div class="requests-ticket-body">
                                        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:.75rem;">
                                            <div style="min-width:0;">
                                                <div class="requests-ticket-meta">
                                                    <span class="font-medium">#{{ $ticket->id }}</span>
                                                    <span>•</span>
                                                    <span>{{ $ticket->updated_at?->format('d.m.Y H:i') }}</span>
                                                </div>
                                                <div class="requests-ticket-subject">
                                                    {{ $ticket->subject ?: 'Без темы' }}
                                                </div>
                                            </div>

                                            <div style="flex-shrink:0;">
                                                <x-filament::badge :color="$statusBadge['color']">
                                                    {{ $statusLabels[$status] ?? $status }}
                                                </x-filament::badge>
                                            </div>
                                        </div>

                                        <div class="requests-ticket-tags">
                                            <x-filament::badge :color="$categoryBadge['color']">
                                                {{ $categoryLabels[$category] ?? 'Другое' }}
                                            </x-filament::badge>

                                            <span class="truncate">{{ $tenantName }}</span>

                                            @if (($ticket->comments_count ?? 0) > 0)
                                                <span class="requests-comment-count">
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
                    <div class="requests-section-heading">
                        <x-filament::icon icon="heroicon-m-chat-bubble-left-right" class="h-5 w-5 text-primary-500" />
                        <span>Переписка</span>
                    </div>
                </x-slot>

                <x-slot name="description">
                    {{ $selectedTicket ? 'Полная карточка выбранного обращения и история диалога.' : 'Выберите диалог слева, чтобы открыть переписку.' }}
                </x-slot>

                @if (! $selectedTicket)
                    <div class="requests-empty" style="min-height:28rem;">
                        <div class="requests-empty-icon">
                            <x-filament::icon icon="heroicon-m-chat-bubble-left-right" class="h-6 w-6" />
                        </div>
                        <div>
                            <div class="requests-empty-title">Диалог не выбран</div>
                            <div class="requests-empty-copy">Откройте нужное обращение слева, чтобы посмотреть переписку и ответить.</div>
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

                    <div class="requests-details">
                        <div class="requests-details-card">
                            <div class="requests-details-top">
                                <div style="display:flex;flex-direction:column;gap:.75rem;">
                                    <div style="display:flex;flex-wrap:wrap;align-items:center;gap:.5rem;">
                                        <div class="requests-ticket-meta" style="font-weight:600;">
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

                                    <div>
                                        <h3 class="requests-details-title">
                                            {{ $selectedTicket->subject ?: 'Без темы' }}
                                        </h3>

                                        @if (filled($selectedTicket->description))
                                            <p class="requests-details-description">
                                                {{ $selectedTicket->description }}
                                            </p>
                                        @endif
                                    </div>
                                </div>

                                <div class="requests-meta-grid">
                                    <div class="requests-meta-card">
                                        <div class="requests-meta-label">Арендатор</div>
                                        <div class="requests-meta-value">{{ $tenantName }}</div>
                                    </div>
                                    <div class="requests-meta-card">
                                        <div class="requests-meta-label">Назначен</div>
                                        <div class="requests-meta-value">{{ $assignedTo !== '' ? $assignedTo : 'Не назначен' }}</div>
                                    </div>
                                    <div class="requests-meta-card">
                                        <div class="requests-meta-label">Создано</div>
                                        <div class="requests-meta-value">{{ $selectedTicket->created_at?->format('d.m.Y H:i') }}</div>
                                    </div>
                                    <div class="requests-meta-card">
                                        <div class="requests-meta-label">Последнее обновление</div>
                                        <div class="requests-meta-value">{{ $selectedTicket->updated_at?->format('d.m.Y H:i') }}</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="requests-thread">
                            <div class="requests-thread-head">
                                <div>
                                    <h4 class="requests-thread-title">Лента сообщений</h4>
                                    <p class="requests-thread-subtitle">Сообщения идут в хронологическом порядке.</p>
                                </div>
                                <div class="requests-thread-count">
                                    {{ $comments->count() }} в ленте
                                </div>
                            </div>

                            <div class="requests-thread-list">
                                @forelse ($comments as $comment)
                                    @php
                                        $isOwn = $user && (int) $comment->user_id === (int) $user->id;
                                    @endphp

                                    <div class="requests-thread-row {{ $isOwn ? 'is-own' : '' }}">
                                        <div class="requests-message {{ $isOwn ? 'is-own' : '' }}">
                                            <div class="requests-message-meta">
                                                <span class="font-medium">{{ $comment->user?->name ?? 'Пользователь' }}</span>
                                                <span>•</span>
                                                <span>{{ $comment->created_at?->format('d.m.Y H:i') }}</span>
                                            </div>
                                            <div class="requests-message-body">{{ $comment->body }}</div>
                                        </div>
                                    </div>
                                @empty
                                    <div class="requests-empty" style="min-height:14rem;">
                                        <div class="requests-empty-icon" style="width:2.5rem;height:2.5rem;">
                                            <x-filament::icon icon="heroicon-m-chat-bubble-bottom-center-text" class="h-5 w-5" />
                                        </div>
                                        <div>
                                            <div class="requests-empty-title">Пока нет сообщений</div>
                                            <div class="requests-empty-copy">Напишите первый ответ, чтобы начать переписку.</div>
                                        </div>
                                    </div>
                                @endforelse
                            </div>
                        </div>

                        <form
                            method="POST"
                            action="{{ route('filament.admin.requests.comment', ['ticket' => (int) $selectedTicket->id]) }}"
                            class="requests-composer"
                        >
                            @csrf

                            <div>
                                <div class="requests-composer-head">
                                    <label class="requests-composer-label">
                                        Ответ в диалог
                                    </label>
                                    <div class="requests-composer-note">
                                        Отправка сразу добавит сообщение в выбранное обращение
                                    </div>
                                </div>

                                <textarea
                                    name="body"
                                    rows="4"
                                    required
                                    placeholder="Опишите решение, уточнение или следующий шаг."
                                >{{ old('body') }}</textarea>

                                @error('body')
                                    <div class="text-sm text-danger-600">{{ $message }}</div>
                                @enderror

                                <div class="requests-composer-actions">
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
