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

        $statusTabs = [
            'all' => 'Все',
            'new' => 'Новые',
            'in_progress' => 'В работе',
            'on_hold' => 'Пауза',
            'closed' => 'Закрытые',
        ];

        $statusFilter = (string) request('status', 'all');
        if (! array_key_exists($statusFilter, $statusTabs)) {
            $statusFilter = 'all';
        }

        $searchQuery = trim((string) request('q', ''));

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

        if ($statusFilter !== 'all') {
            if ($statusFilter === 'closed') {
                $ticketsQuery->whereIn('status', ['resolved', 'closed', 'cancelled']);
            } else {
                $ticketsQuery->where('status', $statusFilter);
            }
        }

        if ($searchQuery !== '') {
            $escapedSearch = addcslashes($searchQuery, '\\%_');
            $likeSearch = '%' . $escapedSearch . '%';

            $ticketsQuery->where(function ($query) use ($likeSearch): void {
                $query
                    ->where('subject', 'like', $likeSearch)
                    ->orWhere('description', 'like', $likeSearch)
                    ->orWhereHas('tenant', function ($tenantQuery) use ($likeSearch): void {
                        $tenantQuery
                            ->where('name', 'like', $likeSearch)
                            ->orWhere('short_name', 'like', $likeSearch);
                    });
            });
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

            if ($statusFilter !== 'all') {
                if ($statusFilter === 'closed') {
                    $singleTicketQuery->whereIn('status', ['resolved', 'closed', 'cancelled']);
                } else {
                    $singleTicketQuery->where('status', $statusFilter);
                }
            }

            if ($searchQuery !== '') {
                $escapedSearch = addcslashes($searchQuery, '\\%_');
                $likeSearch = '%' . $escapedSearch . '%';

                $singleTicketQuery->where(function ($query) use ($likeSearch): void {
                    $query
                        ->where('subject', 'like', $likeSearch)
                        ->orWhere('description', 'like', $likeSearch)
                        ->orWhereHas('tenant', function ($tenantQuery) use ($likeSearch): void {
                            $tenantQuery
                                ->where('name', 'like', $likeSearch)
                                ->orWhere('short_name', 'like', $likeSearch);
                        });
                });
            }

            $selectedTicket = $singleTicketQuery->first();
        }

        if (! $selectedTicket) {
            $selectedTicket = $tickets->first();
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
        if ($statusFilter !== 'all') {
            $baseParams['status'] = $statusFilter;
        }
        if ($searchQuery !== '') {
            $baseParams['q'] = $searchQuery;
        }

        $channelTabs = [
            'tenants' => 'Арендаторы',
            'staff' => 'Сотрудники',
        ];

        $channel = (string) request('channel', 'tenants');
        if (! array_key_exists($channel, $channelTabs)) {
            $channel = 'tenants';
        }

        $staffStatusTabs = [
            'all' => 'Все',
            'mine' => 'Мои',
        ];

        $staffConversationsAvailable = \Illuminate\Support\Facades\Schema::hasTable('staff_conversations')
            && \Illuminate\Support\Facades\Schema::hasTable('staff_conversation_messages');
        $staffConversationsUnavailableReason = $staffConversationsAvailable
            ? null
            : 'Staff channel is unavailable: run migrations for staff_conversations tables.';

        $staffStatusFilter = (string) request('status', 'all');
        if (! array_key_exists($staffStatusFilter, $staffStatusTabs)) {
            $staffStatusFilter = 'all';
        }

        $conversations = collect();
        $selectedConversation = null;
        $conversationMessages = collect();

        if ($channel === 'staff' && $staffConversationsAvailable) {
            $staffQuery = \App\Models\StaffConversation::query()
                ->with(['starter:id,name', 'recipient:id,name'])
                ->withCount('messages')
                ->orderByDesc('last_message_at')
                ->orderByDesc('updated_at')
                ->orderByDesc('id');

            if (! $isSuperAdmin) {
                $marketId = (int) ($user->market_id ?? 0);

                if ($marketId > 0) {
                    $staffQuery
                        ->where('market_id', $marketId)
                        ->where(function ($query) use ($user): void {
                            $query
                                ->where('created_by_user_id', (int) $user->id)
                                ->orWhere('recipient_user_id', (int) $user->id);
                        });
                } else {
                    $staffQuery->whereRaw('1 = 0');
                }
            } elseif ($staffStatusFilter === 'mine' && $user) {
                $staffQuery->where(function ($query) use ($user): void {
                    $query
                        ->where('created_by_user_id', (int) $user->id)
                        ->orWhere('recipient_user_id', (int) $user->id);
                });
            }

            if ($searchQuery !== '') {
                $escapedSearch = addcslashes($searchQuery, '\\%_');
                $likeSearch = '%' . $escapedSearch . '%';

                $staffQuery->where(function ($query) use ($likeSearch): void {
                    $query
                        ->where('subject', 'like', $likeSearch)
                        ->orWhereHas('starter', fn ($userQuery) => $userQuery->where('name', 'like', $likeSearch))
                        ->orWhereHas('recipient', fn ($userQuery) => $userQuery->where('name', 'like', $likeSearch));
                });
            }

            $conversations = $staffQuery->limit(100)->get();

            $requestedConversationId = (int) request('conversation_id');
            $selectedConversation = $requestedConversationId > 0
                ? $conversations->firstWhere('id', $requestedConversationId)
                : $conversations->first();

            if (! $selectedConversation && $requestedConversationId > 0) {
                $singleStaffQuery = \App\Models\StaffConversation::query()
                    ->with(['starter:id,name', 'recipient:id,name'])
                    ->withCount('messages')
                    ->whereKey($requestedConversationId);

                if (! $isSuperAdmin) {
                    $marketId = (int) ($user->market_id ?? 0);

                    if ($marketId > 0) {
                        $singleStaffQuery
                            ->where('market_id', $marketId)
                            ->where(function ($query) use ($user): void {
                                $query
                                    ->where('created_by_user_id', (int) $user->id)
                                    ->orWhere('recipient_user_id', (int) $user->id);
                            });
                    } else {
                        $singleStaffQuery->whereRaw('1 = 0');
                    }
                } elseif ($staffStatusFilter === 'mine' && $user) {
                    $singleStaffQuery->where(function ($query) use ($user): void {
                        $query
                            ->where('created_by_user_id', (int) $user->id)
                            ->orWhere('recipient_user_id', (int) $user->id);
                    });
                }

                if ($searchQuery !== '') {
                    $escapedSearch = addcslashes($searchQuery, '\\%_');
                    $likeSearch = '%' . $escapedSearch . '%';

                    $singleStaffQuery->where(function ($query) use ($likeSearch): void {
                        $query
                            ->where('subject', 'like', $likeSearch)
                            ->orWhereHas('starter', fn ($userQuery) => $userQuery->where('name', 'like', $likeSearch))
                            ->orWhereHas('recipient', fn ($userQuery) => $userQuery->where('name', 'like', $likeSearch));
                    });
                }

                $selectedConversation = $singleStaffQuery->first();
            }

            if (! $selectedConversation) {
                $selectedConversation = $conversations->first();
            }

            if ($selectedConversation) {
                $conversationMessages = \App\Models\StaffConversationMessage::query()
                    ->where('staff_conversation_id', (int) $selectedConversation->id)
                    ->with('user:id,name')
                    ->orderBy('created_at')
                    ->get();
            }
        }

        $tenantComposeOptions = \App\Models\Tenant::query()
            ->select(['id', 'name', 'short_name', 'market_id'])
            ->when(! $isSuperAdmin, function ($query) use ($user): void {
                $marketId = (int) ($user->market_id ?? 0);
                if ($marketId > 0) {
                    $query->where('market_id', $marketId);
                } else {
                    $query->whereRaw('1 = 0');
                }
            })
            ->orderBy('name')
            ->limit(300)
            ->get();

        $staffComposeOptions = \App\Models\User::query()
            ->select(['id', 'name', 'market_id'])
            ->whereNull('tenant_id')
            ->whereKeyNot((int) ($user->id ?? 0))
            ->when(! $isSuperAdmin, function ($query) use ($user): void {
                $marketId = (int) ($user->market_id ?? 0);
                if ($marketId > 0) {
                    $query->where('market_id', $marketId);
                } else {
                    $query->whereRaw('1 = 0');
                }
            })
            ->orderBy('name')
            ->limit(300)
            ->get();

    @endphp

    <style>
        .requests-workspace {
            --requests-accent: #1d4ed8;
            --requests-accent-soft: rgba(37, 99, 235, 0.12);
            --requests-radius-xl: 1rem;
            --requests-radius-lg: 0.875rem;
            --requests-radius-md: 0.75rem;
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
            --requests-shadow: rgba(15, 23, 42, 0.08);
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .dark .requests-workspace {
            --requests-accent: #93c5fd;
            --requests-accent-soft: rgba(59, 130, 246, 0.20);
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
            --requests-shadow: rgba(15, 23, 42, 0.18);
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

        .requests-hero {
            border: 1px solid var(--requests-border-strong);
            border-radius: var(--requests-radius-xl);
            background:
                radial-gradient(circle at top right, rgba(191, 219, 254, 0.42), transparent 36%),
                linear-gradient(180deg, #eff6ff, #dbeafe);
            padding: 1rem 1.1rem;
            box-shadow:
                0 18px 40px rgba(37, 99, 235, 0.10),
                inset 0 1px 0 rgba(255, 255, 255, 0.58);
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .dark .requests-hero {
            background:
                radial-gradient(circle at top right, rgba(59, 130, 246, 0.18), transparent 36%),
                linear-gradient(180deg, rgba(30, 41, 59, 0.96), rgba(15, 23, 42, 0.92));
            box-shadow:
                0 18px 40px rgba(15, 23, 42, 0.26),
                inset 0 1px 0 rgba(148, 163, 184, 0.08);
        }

        .requests-hero-controls {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.85rem;
            flex-wrap: wrap;
        }

        .requests-channel-tabs {
            display: inline-flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.35rem;
            padding: 0.35rem;
            border: 1px solid var(--requests-border);
            border-radius: var(--requests-radius-lg);
            background: var(--requests-surface);
        }

        .requests-header-row {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .requests-title {
            display: flex;
            align-items: center;
            gap: 0.85rem;
        }

        .requests-title-icon {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 2.75rem;
            height: 2.75rem;
            border-radius: 1rem;
            background: rgba(37, 99, 235, 0.12);
            color: #1d4ed8;
            flex-shrink: 0;
        }

        .dark .requests-title-icon {
            background: rgba(59, 130, 246, 0.12);
            color: rgb(147, 197, 253);
        }

        .requests-title-text h2 {
            margin: 0;
            font-size: 1.8rem;
            line-height: 1.1;
            font-weight: 700;
            color: var(--requests-heading);
        }

        .requests-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.85rem;
            flex-wrap: wrap;
        }

        .requests-toolbar-actions {
            display: flex;
            align-items: center;
            gap: 0.9rem;
            flex-wrap: wrap;
            margin-left: auto;
        }

        .requests-modal-backdrop {
            position: fixed;
            inset: 0;
            z-index: 80;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            background: rgba(15, 23, 42, 0.58);
            backdrop-filter: blur(4px) saturate(1.05);
        }

        .requests-modal-panel {
            width: min(860px, 100%);
            max-height: calc(100vh - 3rem);
            overflow: auto;
            border-radius: var(--requests-radius-xl);
            border: 1px solid var(--requests-border-strong);
            background:
                radial-gradient(circle at top right, #dbeafe 0%, #eff6ff 26%, transparent 52%),
                linear-gradient(180deg, #f8fbff, #ffffff);
            box-shadow:
                0 30px 64px rgba(15, 23, 42, 0.24),
                0 8px 22px rgba(37, 99, 235, 0.10);
            padding: 1.15rem 1.2rem;
        }

        .dark .requests-modal-panel {
            background:
                radial-gradient(circle at top right, rgba(59, 130, 246, 0.22), transparent 40%),
                linear-gradient(180deg, #1e293b, #0f172a);
            box-shadow:
                0 30px 64px rgba(2, 6, 23, 0.56),
                0 10px 24px rgba(15, 23, 42, 0.30);
        }

        .requests-modal-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 0.85rem;
            margin-bottom: 0.9rem;
        }

        .requests-modal-close {
            flex-shrink: 0;
            border-radius: var(--requests-radius-lg) !important;
            border: 1px solid var(--requests-border) !important;
            background: rgba(255, 255, 255, 0.72) !important;
            color: var(--requests-muted-strong) !important;
            box-shadow: none !important;
        }

        .requests-modal-close:hover {
            background: var(--requests-surface-soft) !important;
            color: var(--requests-heading) !important;
        }

        .dark .requests-modal-close {
            background: rgba(15, 23, 42, 0.52) !important;
            color: var(--requests-text) !important;
        }

        .requests-modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 0.6rem;
            margin-top: 0.85rem;
        }

        [x-cloak] {
            display: none !important;
        }

        .requests-compose-card {
            min-width: 0;
        }

        .requests-compose-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 0.85rem;
            margin-bottom: 0.95rem;
            padding-bottom: 0.7rem;
            border-bottom: 1px solid rgba(148, 163, 184, 0.24);
        }

        .requests-compose-title {
            font-size: 1.02rem;
            font-weight: 700;
            color: var(--requests-heading);
        }

        .requests-compose-copy {
            margin-top: 0.2rem;
            max-width: 44rem;
            font-size: 0.84rem;
            line-height: 1.45;
            color: var(--requests-muted-strong);
        }

        .requests-compose-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0.95rem 1rem;
        }

        .requests-compose-grid--single {
            grid-template-columns: 1fr;
            max-width: 50rem;
        }

        .requests-compose-field {
            display: flex;
            flex-direction: column;
            gap: 0.38rem;
        }

        .requests-compose-field--narrow {
            justify-self: start;
            width: min(100%, 30rem);
            max-width: 30rem;
        }

        .requests-compose-grid--single .requests-compose-field:not(.requests-compose-field--narrow) {
            width: 100%;
            max-width: none;
            justify-self: stretch;
        }

        .requests-compose-field label {
            font-size: 0.78rem;
            font-weight: 600;
            letter-spacing: 0.02em;
            color: var(--requests-muted-strong);
        }

        .requests-compose-field input,
        .requests-compose-field select,
        .requests-compose-field textarea {
            width: 100%;
            border-radius: var(--requests-radius-lg);
            border: 1px solid var(--requests-border);
            background: rgba(248, 250, 252, 0.9);
            color: var(--requests-text);
            padding: 0.72rem 0.9rem;
            font-size: 0.95rem;
            line-height: 1.45;
            outline: none;
            transition: border-color 160ms ease, box-shadow 160ms ease, background-color 160ms ease;
        }

        .requests-compose-field textarea {
            min-height: 6.6rem;
            border-radius: 1rem;
            resize: vertical;
        }

        .dark .requests-compose-field input,
        .dark .requests-compose-field select,
        .dark .requests-compose-field textarea {
            background: rgba(15, 23, 42, 0.58);
        }

        .requests-compose-field input:focus,
        .requests-compose-field select:focus,
        .requests-compose-field textarea:focus {
            border-color: rgba(96, 165, 250, 0.52);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.14);
        }

        .requests-compose-actions {
            display: flex;
            justify-content: flex-end;
            gap: 0.6rem;
            margin-top: 1rem;
            padding-top: 0.72rem;
            border-top: 1px solid rgba(148, 163, 184, 0.22);
            max-width: 50rem;
        }

        .requests-compose-cancel {
            border-radius: var(--requests-radius-lg) !important;
            border: 1px solid var(--requests-border) !important;
            background: transparent !important;
            color: var(--requests-muted-strong) !important;
            box-shadow: none !important;
        }

        .requests-compose-cancel:hover {
            background: var(--requests-surface-soft) !important;
            color: var(--requests-heading) !important;
        }

        .requests-compose-submit {
            border-radius: var(--requests-radius-lg) !important;
            border-color: rgba(29, 78, 216, 0.46) !important;
            background: linear-gradient(180deg, #2563eb, #1d4ed8) !important;
            color: #ffffff !important;
        }

        .requests-compose-submit svg {
            color: inherit !important;
        }

        .dark .requests-compose-submit {
            border-color: rgba(96, 165, 250, 0.42) !important;
            background: linear-gradient(180deg, #1d4ed8, #1e40af) !important;
            color: #ffffff !important;
        }

        .requests-tabs {
            display: inline-flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.35rem;
            padding: 0.35rem;
            border: 1px solid var(--requests-border);
            border-radius: var(--requests-radius-lg);
            background: var(--requests-surface);
        }

        .requests-tab {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 2.25rem;
            padding: 0.5rem 0.8rem;
            border-radius: var(--requests-radius-md);
            color: var(--requests-muted-strong);
            text-decoration: none;
            font-size: 0.92rem;
            font-weight: 500;
            transition: background-color 150ms ease, color 150ms ease;
        }

        .requests-tab:hover {
            background: var(--requests-surface-soft);
            color: var(--requests-heading);
        }

        .requests-tab.is-active {
            background: var(--requests-accent-soft);
            color: var(--requests-accent);
            font-weight: 600;
        }

        .dark .requests-tab.is-active {
            color: var(--requests-accent);
        }

        .requests-search {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            flex-wrap: wrap;
        }

        .requests-toolbar-actions > .requests-search {
            margin-left: 0.35rem;
        }

        .requests-search-field {
            position: relative;
            display: flex;
            align-items: center;
            min-width: 18rem;
            max-width: 24rem;
            flex: 1 1 18rem;
        }

        .requests-search-icon {
            position: absolute;
            left: 0.9rem;
            width: 1.05rem;
            height: 1.05rem;
            color: #9ca3af;
            pointer-events: none;
        }

        .requests-search input[type="search"] {
            height: 2.5rem;
            width: 100%;
            padding: 0.65rem 0.9rem 0.65rem 2.5rem;
            border-radius: var(--requests-radius-lg);
            border: 1px solid var(--requests-border);
            background: var(--requests-surface-soft);
            color: var(--requests-text);
        }

        .requests-search input[type="search"]::placeholder {
            color: var(--requests-muted);
        }

        .requests-hero-cta {
            border-radius: var(--requests-radius-lg) !important;
            border-color: rgba(29, 78, 216, 0.45) !important;
            background: linear-gradient(180deg, #2563eb, #1d4ed8) !important;
            color: #ffffff !important;
        }

        .dark .requests-hero-cta {
            border-color: rgba(96, 165, 250, 0.42) !important;
            background: linear-gradient(180deg, #1d4ed8, #1e40af) !important;
            color: #ffffff !important;
        }

        .requests-hero-cta svg {
            color: inherit !important;
            opacity: 0.95;
        }

        .requests-search-submit,
        .requests-search-reset {
            border-radius: var(--requests-radius-lg) !important;
            border: 1px solid var(--requests-border) !important;
            background: transparent !important;
            color: var(--requests-muted-strong) !important;
            box-shadow: none !important;
        }

        .requests-search-submit:hover,
        .requests-search-reset:hover {
            background: var(--requests-surface-soft) !important;
            color: var(--requests-heading) !important;
        }

        .requests-layout {
            display: grid;
            gap: 1.5rem;
            grid-template-columns: minmax(0, 380px) minmax(0, 1fr);
        }

        .requests-section-heading {
            display: inline-flex;
            align-items: center;
            gap: 0.55rem;
            flex-wrap: nowrap;
            white-space: nowrap;
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
            align-items: stretch;
            gap: 0.55rem;
            max-height: 76vh;
            overflow-y: auto;
            overflow-x: hidden;
            padding-right: 0.25rem;
        }

        .requests-ticket-card {
            display: block;
            width: 100%;
            min-width: 0;
            flex: 0 0 auto;
            padding: 0.75rem 0.8rem;
            border-radius: 0.9rem;
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
            gap: 0.65rem;
        }

        .requests-ticket-avatar {
            display: flex;
            width: 2.2rem;
            height: 2.2rem;
            align-items: center;
            justify-content: center;
            border-radius: 0.75rem;
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
            gap: 0.45rem;
            flex: 1;
        }

        .requests-ticket-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 0.6rem;
        }

        .requests-ticket-head-main {
            min-width: 0;
        }

        .requests-ticket-head-side {
            min-width: 0;
        }

        .requests-ticket-head-badges {
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
        }

        .requests-ticket-meta {
            display: flex;
            align-items: center;
            gap: 0.45rem;
            font-size: 0.7rem;
            color: var(--requests-muted);
        }

        .requests-ticket-subject {
            margin-top: 0.1rem;
            font-size: 0.92rem;
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
            gap: 0.35rem;
            font-size: 0.76rem;
            color: var(--requests-muted-strong);
            min-width: 0;
            width: 100%;
        }

        .requests-ticket-counterparty {
            min-width: 0;
            flex: 1 1 auto;
            overflow-wrap: anywhere;
            white-space: normal;
        }

        .requests-comment-count {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.16rem 0.46rem;
            border-radius: 999px;
            background: rgba(148, 163, 184, 0.10);
            color: var(--requests-muted-strong);
            font-weight: 600;
            font-size: 0.72rem;
            margin-left: auto;
        }

        .requests-details {
            display: flex;
            flex-direction: column;
            gap: 0.85rem;
        }

        .requests-details-card {
            border-radius: 1rem;
            border: 1px solid var(--requests-border-strong);
            background:
                radial-gradient(circle at top right, rgba(191, 219, 254, 0.26), transparent 34%),
                linear-gradient(180deg, rgba(239, 246, 255, 0.88), rgba(255, 255, 255, 0.96));
            padding: 0.95rem 1.05rem;
            box-shadow:
                0 14px 26px rgba(37, 99, 235, 0.06),
                inset 0 1px 0 rgba(255, 255, 255, 0.55);
        }

        .dark .requests-details-card {
            background:
                radial-gradient(circle at top right, rgba(59, 130, 246, 0.18), transparent 36%),
                linear-gradient(180deg, rgba(30, 41, 59, 0.94), rgba(15, 23, 42, 0.92));
            box-shadow:
                0 18px 36px rgba(15, 23, 42, 0.24),
                inset 0 1px 0 rgba(148, 163, 184, 0.08);
        }

        .requests-details-top {
            display: flex;
            flex-direction: column;
            gap: 0.72rem;
        }

        .requests-details-intro {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 0.45rem;
        }

        .requests-details-badges {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.35rem;
        }

        .requests-details-kicker {
            font-weight: 600;
            font-size: 0.74rem;
            color: var(--requests-muted-strong);
            border: 1px solid var(--requests-border);
            border-radius: 999px;
            padding: 0.16rem 0.52rem;
            background: rgba(148, 163, 184, 0.08);
        }

        .requests-details-badges > :not(.requests-details-kicker) {
            border-radius: 999px;
            min-height: 1.42rem;
            font-size: 0.7rem;
            letter-spacing: 0.01em;
            padding-inline: 0.5rem;
        }

        .requests-details-badges > :nth-child(n+3) {
            opacity: 0.8;
            filter: saturate(0.84);
        }

        .requests-details-title {
            margin: 0;
            font-size: 1.25rem;
            line-height: 1.2;
            font-weight: 700;
            color: var(--requests-heading);
        }

        .requests-details-description {
            margin: 0;
            align-self: flex-start;
            width: 100%;
            max-width: none;
            text-align: left;
            white-space: pre-wrap;
            font-size: 0.9rem;
            line-height: 1.55;
            color: var(--requests-muted-strong);
        }

        .requests-meta-line {
            display: flex;
            width: 100%;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.28rem 0.52rem;
            padding-top: 0.05rem;
            font-size: 0.8rem;
            line-height: 1.5;
            color: var(--requests-muted);
        }

        .requests-meta-item {
            display: inline-flex;
            align-items: baseline;
            gap: 0.3rem;
            min-width: 0;
        }

        .requests-meta-item strong {
            font-weight: 550;
            color: var(--requests-muted-strong);
        }

        .requests-meta-separator {
            color: var(--requests-muted);
            opacity: 0.65;
        }

        .requests-management-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr));
            gap: 0.55rem 0.85rem;
            padding-top: 0.5rem;
            border-top: 1px solid rgba(148, 163, 184, 0.24);
        }

        .requests-management-group {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 0.3rem;
            min-width: 0;
        }

        .requests-assignee-label {
            font-size: 0.75rem;
            font-weight: 600;
            white-space: nowrap;
            letter-spacing: 0.02em;
            color: var(--requests-muted-strong);
        }

        .requests-assignee-select {
            min-width: 0;
            width: 100%;
            max-width: none;
            height: 2.3rem;
            padding: 0.5rem 0.75rem;
            border-radius: 0.75rem;
            border: 1px solid var(--requests-border);
            background: rgba(255, 255, 255, 0.9);
            color: var(--requests-text);
        }

        .dark .requests-assignee-select {
            background: rgba(15, 23, 42, 0.58);
        }

        .requests-assignee-note {
            font-size: 0.72rem;
            opacity: 0.75;
            white-space: nowrap;
            color: var(--requests-muted);
        }

        .requests-thread {
            border-radius: 1rem;
            border: 1px solid var(--requests-border);
            background: var(--requests-thread);
            padding: 0.9rem;
        }

        .requests-thread-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            margin-bottom: 0.75rem;
        }

        .requests-thread-title {
            font-size: 0.95rem;
            font-weight: 700;
            color: var(--requests-heading);
        }

        .requests-thread-count {
            border-radius: 999px;
            padding: 0.3rem 0.65rem;
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--requests-muted-strong);
            background: rgba(148, 163, 184, 0.10);
            border: 1px solid var(--requests-border);
        }

        .requests-thread-list {
            display: flex;
            flex-direction: column;
            gap: 0.65rem;
            max-height: 44vh;
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
            padding: 0.9rem;
            box-shadow: 0 18px 36px var(--requests-shadow);
        }

        .requests-composer-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            margin-bottom: 0.65rem;
        }

        .requests-composer-label {
            font-size: 0.95rem;
            font-weight: 700;
            color: var(--requests-heading);
        }

        .requests-composer textarea {
            width: 100%;
            min-height: 6rem;
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
            margin-top: 0.7rem;
        }

        @media (max-width: 1279px) {
            .requests-layout {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 767px) {
            .requests-compose-grid {
                grid-template-columns: 1fr;
            }

            .requests-compose-grid--single,
            .requests-compose-actions {
                max-width: none;
            }

            .requests-search {
                width: 100%;
            }

            .requests-toolbar-actions {
                width: 100%;
            }

            .requests-search-field {
                min-width: 0;
                max-width: none;
                width: 100%;
            }

            .requests-search input[type="search"] {
                width: 100%;
            }

            .requests-assignee-select {
                min-width: 0;
                width: 100%;
                max-width: none;
            }

            .requests-management-group {
                width: 100%;
            }

            .requests-management-form {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 520px) {
            .requests-composer-head,
            .requests-thread-head {
                flex-direction: column;
                align-items: flex-start;
            }

            .requests-modal-head {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>

    <div
        class="requests-workspace"
        x-data="{
            composeModalOpen: false,
            focusComposeField() {
                window.setTimeout(() => {
                    const field = this.$refs.composeFirstField;
                    if (field && typeof field.focus === 'function') {
                        field.focus();
                    }
                }, 50);
            },
            openComposeModal() {
                this.composeModalOpen = true;
                this.focusComposeField();
            },
            closeComposeModal() {
                this.composeModalOpen = false;
            },
            handleComposeHotkey(event) {
                const key = (event.key || '').toLowerCase();
                if (key !== 'n' || event.ctrlKey || event.metaKey || event.altKey) {
                    return;
                }

                const target = event.target;
                const tagName = target && target.tagName ? target.tagName.toUpperCase() : '';
                if (target && (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(tagName))) {
                    return;
                }

                event.preventDefault();
                this.openComposeModal();
            },
        }"
        x-on:keydown.window="handleComposeHotkey($event)"
    >
        <section class="requests-hero">
            <div class="requests-header-row">
                <div class="requests-title">
                    <div class="requests-title-icon">
                        <x-filament::icon icon="heroicon-m-chat-bubble-left-right" class="h-6 w-6" />
                    </div>
                    <div class="requests-title-text">
                        <h2>Диалоги</h2>
                    </div>
                </div>

                @if ($tenantFilterId > 0)
                    @php
                        $tenantTitle = trim((string) ($tenantFilter?->short_name ?? $tenantFilter?->name ?? ''));
                    @endphp
                    <div class="requests-filter-pill">
                        <x-filament::icon icon="heroicon-m-funnel" class="h-4 w-4" />
                        <span>Арендатор: {{ $tenantTitle !== '' ? $tenantTitle : ('арендатор #' . $tenantFilterId) }}</span>
                    </div>
                @endif
            </div>

            <div class="requests-hero-controls">
                <div class="requests-channel-tabs">
                    @foreach ($channelTabs as $channelKey => $channelLabel)
                        @php
                            $channelParams = [];

                            if ($channelKey === 'staff') {
                                $channelParams['channel'] = 'staff';
                            }

                            if ($channelKey === 'tenants' && $tenantFilterId > 0) {
                                $channelParams['tenant_id'] = $tenantFilterId;
                            }

                            if ($searchQuery !== '') {
                                $channelParams['q'] = $searchQuery;
                            }

                            if ($channelKey === 'staff') {
                                if ($staffStatusFilter !== 'all') {
                                    $channelParams['status'] = $staffStatusFilter;
                                }
                            } elseif ($statusFilter !== 'all') {
                                $channelParams['status'] = $statusFilter;
                            }
                        @endphp

                        <a
                            href="{{ \App\Filament\Pages\Requests::getUrl(parameters: $channelParams) }}"
                            class="requests-tab {{ $channel === $channelKey ? 'is-active' : '' }}"
                        >
                            {{ $channelLabel }}
                        </a>
                    @endforeach
                </div>

                <div class="requests-toolbar-actions">
                    <x-filament::button
                        type="button"
                        color="primary"
                        icon="heroicon-o-plus"
                        class="requests-hero-cta"
                        x-on:click="openComposeModal()"
                    >
                        {{ $channel === 'staff' ? 'Новое сообщение сотруднику' : 'Новое обращение арендатору' }}
                    </x-filament::button>

                    <form method="GET" class="requests-search">
                    @if ($channel === 'staff')
                        <input type="hidden" name="channel" value="staff">
                    @endif

                    @if ($tenantFilterId > 0 && $channel === 'tenants')
                        <input type="hidden" name="tenant_id" value="{{ $tenantFilterId }}">
                    @endif

                    @php
                        $activeStatusFilter = $channel === 'staff' ? $staffStatusFilter : $statusFilter;
                    @endphp

                    @if ($activeStatusFilter !== 'all')
                        <input type="hidden" name="status" value="{{ $activeStatusFilter }}">
                    @endif

                    <label class="requests-search-field">
                        <x-filament::icon icon="heroicon-m-magnifying-glass" class="requests-search-icon" />
                        <input
                            type="search"
                            name="q"
                            value="{{ $searchQuery }}"
                            placeholder="Поиск"
                        >
                    </label>

                    <x-filament::button type="submit" color="gray" size="sm" class="requests-search-submit">
                        Найти
                    </x-filament::button>

                    @if ($searchQuery !== '' || $activeStatusFilter !== 'all')
                        <x-filament::button
                            tag="a"
                            color="gray"
                            size="sm"
                            class="requests-search-reset"
                            :href="\App\Filament\Pages\Requests::getUrl(parameters: array_filter([
                                'channel' => $channel === 'staff' ? 'staff' : null,
                                'tenant_id' => $channel === 'tenants' && $tenantFilterId > 0 ? $tenantFilterId : null,
                            ]))"
                        >
                            Сбросить
                        </x-filament::button>
                    @endif
                    </form>
                </div>
            </div>
        </section>

        <div class="requests-toolbar">
            <div class="requests-tabs">
                @foreach (($channel === 'staff' ? $staffStatusTabs : $statusTabs) as $statusKey => $statusLabel)
                    @php
                        $tabParams = [];

                        if ($channel === 'staff') {
                            $tabParams['channel'] = 'staff';
                        }

                        if ($tenantFilterId > 0 && $channel === 'tenants') {
                            $tabParams['tenant_id'] = $tenantFilterId;
                        }

                        if ($searchQuery !== '') {
                            $tabParams['q'] = $searchQuery;
                        }

                        if ($statusKey !== 'all') {
                            $tabParams['status'] = $statusKey;
                        }
                    @endphp

                    <a
                        href="{{ \App\Filament\Pages\Requests::getUrl(parameters: $tabParams) }}"
                        class="requests-tab {{ ($channel === 'staff' ? $staffStatusFilter : $statusFilter) === $statusKey ? 'is-active' : '' }}"
                    >
                        {{ $statusLabel }}
                    </a>
                @endforeach
            </div>
        </div>

        <div
            class="requests-modal-backdrop"
            x-cloak
            x-show="composeModalOpen"
            x-transition.opacity
            x-on:click.self="closeComposeModal()"
            x-on:keydown.escape.window="closeComposeModal()"
        >
        <section class="requests-compose-card requests-modal-panel" x-on:click.stop>
            <div class="requests-compose-head requests-modal-head">
                <div>
                    <div class="requests-compose-title">
                        {{ $channel === 'staff' ? 'Новый диалог с сотрудником' : 'Новый диалог с арендатором' }}
                    </div>
                    <div class="requests-compose-copy">
                        {{ $channel === 'staff' ? 'Запустите внутренний диалог сразу из общего inbox.' : 'Создайте диалог и сразу откройте переписку в общем списке.' }}
                    </div>
                </div>
                <x-filament::button
                    type="button"
                    color="gray"
                    size="sm"
                    class="requests-modal-close"
                    x-on:click="closeComposeModal()"
                >
                    Закрыть
                </x-filament::button>
            </div>

            @if ($channel === 'staff')
                @if (! $staffConversationsAvailable)
                    <div class="requests-empty">
                        <div class="requests-empty-icon">
                            <x-filament::icon icon="heroicon-m-exclamation-triangle" class="h-6 w-6" />
                        </div>
                        <div>
                            <div class="requests-empty-title">Staff channel unavailable</div>
                            <div class="requests-empty-copy">{{ $staffConversationsUnavailableReason }}</div>
                        </div>
                    </div>
                @else
                <form method="POST" action="{{ route('filament.admin.requests.staff.start') }}">
                    @csrf

                    <div class="requests-compose-grid requests-compose-grid--single">
                        <div class="requests-compose-field requests-compose-field--narrow">
                            <label for="requests_staff_recipient_user_id">Сотрудник</label>
                            <select id="requests_staff_recipient_user_id" name="recipient_user_id" x-ref="composeFirstField" required>
                                <option value="">Выберите сотрудника</option>
                                @foreach ($staffComposeOptions as $staffOption)
                                    <option value="{{ (int) $staffOption->id }}">{{ $staffOption->name }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="requests-compose-field">
                            <label for="requests_staff_body">Первое сообщение</label>
                            <textarea id="requests_staff_body" name="body" required placeholder="Напишите сообщение сотруднику..."></textarea>
                        </div>
                    </div>

                    <div class="requests-compose-actions">
                        <x-filament::button type="button" color="gray" class="requests-compose-cancel" x-on:click="closeComposeModal()">
                            Отмена
                        </x-filament::button>
                        <x-filament::button type="submit" icon="heroicon-o-paper-airplane" class="requests-compose-submit">
                            Начать диалог
                        </x-filament::button>
                    </div>
                </form>
                @endif
            @else
                <form method="POST" action="{{ route('filament.admin.requests.start') }}">
                    @csrf

                    <div class="requests-compose-grid requests-compose-grid--single">
                        <div class="requests-compose-field requests-compose-field--narrow">
                            <label for="requests_tenant_id">Арендатор</label>
                            <select id="requests_tenant_id" name="tenant_id" x-ref="composeFirstField" required>
                                <option value="">Выберите арендатора</option>
                                @foreach ($tenantComposeOptions as $tenantOption)
                                    <option value="{{ (int) $tenantOption->id }}" @selected($tenantFilterId > 0 && $tenantFilterId === (int) $tenantOption->id)>
                                        {{ $tenantOption->short_name ?: $tenantOption->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="requests-compose-field">
                            <label for="requests_ticket_description">Первое сообщение</label>
                            <textarea id="requests_ticket_description" name="description" required placeholder="Опишите задачу, проблему или следующий шаг..."></textarea>
                        </div>
                    </div>

                    <div class="requests-compose-actions">
                        <x-filament::button type="button" color="gray" class="requests-compose-cancel" x-on:click="closeComposeModal()">
                            Отмена
                        </x-filament::button>
                        <x-filament::button type="submit" icon="heroicon-o-paper-airplane" class="requests-compose-submit">
                            Создать диалог
                        </x-filament::button>
                    </div>
                </form>
            @endif
        </section>
        </div>

        <div class="requests-layout">
            <x-filament::section>
                <x-slot name="heading">
                    <div class="requests-section-heading">
                        <x-filament::icon icon="heroicon-m-inbox-stack" class="h-5 w-5 text-primary-500" />
                        <span>Диалоги</span>
                    </div>
                </x-slot>

                @if ($channel === 'staff')
                    @if ($conversations->isEmpty())
                        <div class="requests-empty">
                            <div class="requests-empty-icon">
                                <x-filament::icon icon="heroicon-m-user-group" class="h-6 w-6" />
                            </div>
                            <div>
                                <div class="requests-empty-title">Нет внутренних диалогов</div>
                                <div class="requests-empty-copy">Создайте первый диалог со сотрудником через форму выше.</div>
                            </div>
                        </div>
                    @else
                        <div class="requests-ticket-list">
                            @foreach ($conversations as $conversation)
                                @php
                                    $isSelected = $selectedConversation && (int) $selectedConversation->id === (int) $conversation->id;
                                    $starterName = trim((string) ($conversation->starter?->name ?? 'Неизвестный отправитель'));
                                    $recipientName = trim((string) ($conversation->recipient?->name ?? 'Неизвестный получатель'));
                                    $counterpartyName = $user && (int) ($conversation->created_by_user_id ?? 0) === (int) $user->id
                                        ? $recipientName
                                        : $starterName;
                                    $conversationUrlParams = [
                                        'channel' => 'staff',
                                        'conversation_id' => (int) $conversation->id,
                                    ];

                                    if ($staffStatusFilter !== 'all') {
                                        $conversationUrlParams['status'] = $staffStatusFilter;
                                    }

                                    if ($searchQuery !== '') {
                                        $conversationUrlParams['q'] = $searchQuery;
                                    }
                                @endphp

                                <a
                                    href="{{ \App\Filament\Pages\Requests::getUrl(parameters: $conversationUrlParams) }}"
                                    class="requests-ticket-card {{ $isSelected ? 'is-selected' : '' }}"
                                >
                                    <div class="requests-ticket-row">
                                        <div class="requests-ticket-avatar">
                                            <x-filament::icon icon="heroicon-m-user-circle" class="h-5 w-5" />
                                        </div>

                                        <div class="requests-ticket-body">
                                            <div class="requests-ticket-head">
                                                <div class="requests-ticket-head-main">
                                                    <div class="requests-ticket-meta">
                                                        <span class="font-medium">#{{ $conversation->id }}</span>
                                                        <span>•</span>
                                                        <span>{{ optional($conversation->last_message_at ?? $conversation->updated_at)->format('d.m.Y H:i') }}</span>
                                                    </div>
                                                    <div class="requests-ticket-subject">
                                                        {{ $conversation->subject ?: 'Без темы' }}
                                                    </div>
                                                </div>

                                                <div class="requests-ticket-head-side">
                                                    <x-filament::badge color="primary">
                                                        {{ $conversation->messages_count }} сообщ.
                                                    </x-filament::badge>
                                                </div>
                                            </div>

                                            <div class="requests-ticket-tags">
                                                <span class="requests-ticket-counterparty">{{ $counterpartyName }}</span>
                                            </div>
                                        </div>
                                    </div>
                                </a>
                            @endforeach
                        </div>
                    @endif
                @elseif ($tickets->isEmpty())
                    <div class="requests-empty">
                        <div class="requests-empty-icon">
                            <x-filament::icon icon="heroicon-m-chat-bubble-left-ellipsis" class="h-6 w-6" />
                        </div>
                        <div>
                            <div class="requests-empty-title">Нет диалогов</div>
                            <div class="requests-empty-copy">По текущему фильтру ещё нет диалогов или переписки.</div>
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
                                        <div class="requests-ticket-head">
                                            <div class="requests-ticket-head-main">
                                                <div class="requests-ticket-meta">
                                                    <span class="font-medium">#{{ $ticket->id }}</span>
                                                    <span>•</span>
                                                    <span>{{ $ticket->updated_at?->format('d.m.Y H:i') }}</span>
                                                </div>
                                                <div class="requests-ticket-subject">
                                                    {{ $ticket->subject ?: 'Без темы' }}
                                                </div>
                                            </div>

                                            <div class="requests-ticket-head-side">
                                                <div class="requests-ticket-head-badges">
                                                    <x-filament::badge :color="$categoryBadge['color']">
                                                        {{ $categoryLabels[$category] ?? 'Другое' }}
                                                    </x-filament::badge>
                                                    <x-filament::badge :color="$statusBadge['color']">
                                                        {{ $statusLabels[$status] ?? $status }}
                                                    </x-filament::badge>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="requests-ticket-tags">

                                            <span class="requests-ticket-counterparty">{{ $tenantName }}</span>

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

                @if ($channel === 'staff')
                    @if (! $selectedConversation)
                        <div class="requests-empty" style="min-height:28rem;">
                            <div class="requests-empty-icon">
                                <x-filament::icon icon="heroicon-m-chat-bubble-left-right" class="h-6 w-6" />
                            </div>
                            <div>
                                <div class="requests-empty-title">Диалог не выбран</div>
                                <div class="requests-empty-copy">Откройте нужную переписку слева или создайте новую выше.</div>
                            </div>
                        </div>
                    @else
                        @php
                            $starterName = trim((string) ($selectedConversation->starter?->name ?? 'Неизвестный отправитель'));
                            $recipientName = trim((string) ($selectedConversation->recipient?->name ?? 'Неизвестный получатель'));
                        @endphp

                        <div class="requests-details">
                            <div class="requests-details-card">
                                <div class="requests-details-top">
                                    <div class="requests-details-intro">
                                        <div class="requests-details-badges">
                                            <div class="requests-ticket-meta requests-details-kicker">
                                                Диалог #{{ $selectedConversation->id }}
                                            </div>

                                            <x-filament::badge color="primary">
                                                Сотрудники
                                            </x-filament::badge>
                                        </div>

                                        <h3 class="requests-details-title">
                                            {{ $selectedConversation->subject ?: 'Без темы' }}
                                        </h3>
                                    </div>

                                    <div class="requests-meta-line">
                                        <span class="requests-meta-item">
                                            Отправитель:
                                            <strong>{{ $starterName }}</strong>
                                        </span>
                                        <span class="requests-meta-separator">·</span>
                                        <span class="requests-meta-item">
                                            Получатель:
                                            <strong>{{ $recipientName }}</strong>
                                        </span>
                                        <span class="requests-meta-separator">·</span>
                                        <span class="requests-meta-item">
                                            Обновлено:
                                            <strong>{{ optional($selectedConversation->last_message_at ?? $selectedConversation->updated_at)->format('d.m.Y H:i') }}</strong>
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <div class="requests-thread">
                                <div class="requests-thread-head">
                                    <div>
                                        <h4 class="requests-thread-title">Лента сообщений</h4>
                                    </div>
                                    <div class="requests-thread-count">
                                        {{ $conversationMessages->count() }} в ленте
                                    </div>
                                </div>

                                <div class="requests-thread-list">
                                    @forelse ($conversationMessages as $message)
                                        @php
                                            $isOwn = $user && (int) $message->user_id === (int) $user->id;
                                        @endphp

                                        <div class="requests-thread-row {{ $isOwn ? 'is-own' : '' }}">
                                            <div class="requests-message {{ $isOwn ? 'is-own' : '' }}">
                                                <div class="requests-message-meta">
                                                    <span class="font-medium">{{ $message->user?->name ?? 'Пользователь' }}</span>
                                                    <span>•</span>
                                                    <span>{{ $message->created_at?->format('d.m.Y H:i') }}</span>
                                                </div>
                                                <div class="requests-message-body">{{ $message->body }}</div>
                                            </div>
                                        </div>
                                    @empty
                                        <div class="requests-empty" style="min-height:11rem;">
                                            <div class="requests-empty-icon" style="width:2.5rem;height:2.5rem;">
                                                <x-filament::icon icon="heroicon-m-chat-bubble-bottom-center-text" class="h-5 w-5" />
                                            </div>
                                            <div>
                                                <div class="requests-empty-title">Пока нет сообщений</div>
                                                <div class="requests-empty-copy">Напишите первое сообщение, чтобы открыть внутренний диалог.</div>
                                            </div>
                                        </div>
                                    @endforelse
                                </div>
                            </div>

                            <form
                                method="POST"
                                action="{{ route('filament.admin.requests.staff.comment', ['conversation' => (int) $selectedConversation->id]) }}"
                                class="requests-composer"
                            >
                                @csrf
                                <input type="hidden" name="q" value="{{ $searchQuery }}">

                                <div>
                                    <div class="requests-composer-head">
                                        <label class="requests-composer-label">
                                            Ответ в диалог
                                        </label>
                                    </div>

                                    <textarea
                                        name="body"
                                        rows="4"
                                        required
                                        placeholder="Напишите внутреннее сообщение..."
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
                @elseif (! $selectedTicket)
                    <div class="requests-empty" style="min-height:28rem;">
                        <div class="requests-empty-icon">
                            <x-filament::icon icon="heroicon-m-chat-bubble-left-right" class="h-6 w-6" />
                        </div>
                        <div>
                            <div class="requests-empty-title">Диалог не выбран</div>
                            <div class="requests-empty-copy">Откройте нужный диалог слева, чтобы посмотреть переписку и ответить.</div>
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
                        $subjectText = trim((string) ($selectedTicket->subject ?? ''));
                        $descriptionText = trim((string) ($selectedTicket->description ?? ''));
                        $showDescription = $descriptionText !== '' && mb_strtolower($descriptionText) !== mb_strtolower($subjectText);
                        $canManageAssignee = (bool) $user && (
                            ((method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()))
                            || (
                                method_exists($user, 'hasRole')
                                && $user->hasRole('market-admin')
                                && (int) ($user->market_id ?? 0) === (int) $selectedTicket->market_id
                            )
                        );
                        $assignableUsers = $canManageAssignee
                            ? \App\Models\User::query()
                                ->select(['id', 'name'])
                                ->where('market_id', (int) $selectedTicket->market_id)
                                ->whereNull('tenant_id')
                                ->orderBy('name')
                                ->get()
                            : collect();
                        $canManageStatus = $canManageAssignee;
                        $statusOptions = [
                            'new' => 'Новая',
                            'in_progress' => 'В работе',
                            'on_hold' => 'Пауза',
                            'resolved' => 'Решена',
                            'closed' => 'Закрыта',
                            'cancelled' => 'Отменена',
                        ];
                    @endphp

                    <div class="requests-details">
                        <div class="requests-details-card">
                            <div class="requests-details-top">
                                <div class="requests-details-intro">
                                    <div class="requests-details-badges">
                                        <div class="requests-ticket-meta requests-details-kicker">
                                            Диалог #{{ $selectedTicket->id }}
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

                                    <h3 class="requests-details-title">
                                        {{ $selectedTicket->subject ?: 'Без темы' }}
                                    </h3>

                                    @if ($showDescription)
                                        <p class="requests-details-description">{{ $descriptionText }}</p>
                                    @endif
                                </div>

                                <div class="requests-meta-line">
                                    <span class="requests-meta-item">
                                        Арендатор:
                                        <strong>{{ $tenantName }}</strong>
                                    </span>
                                    <span class="requests-meta-separator">·</span>
                                    <span class="requests-meta-item">
                                        Создано:
                                        <strong>{{ $selectedTicket->created_at?->format('d.m.Y H:i') }}</strong>
                                    </span>
                                    <span class="requests-meta-separator">·</span>
                                    <span class="requests-meta-item">
                                        Обновлено:
                                        <strong>{{ $selectedTicket->updated_at?->format('d.m.Y H:i') }}</strong>
                                    </span>
                                </div>

                                @if ($canManageAssignee || $canManageStatus)
                                    <form
                                        method="POST"
                                        action="{{ route('filament.admin.requests.assign', ['ticket' => (int) $selectedTicket->id]) }}"
                                        class="requests-management-form"
                                    >
                                        @csrf
                                        <input type="hidden" name="ticket_id" value="{{ (int) $selectedTicket->id }}">
                                        @if ($tenantFilterId > 0)
                                            <input type="hidden" name="tenant_id" value="{{ $tenantFilterId }}">
                                        @endif
                                        @if ($statusFilter !== 'all')
                                            <input type="hidden" name="status_redirect" value="{{ $statusFilter }}">
                                        @endif
                                        @if ($searchQuery !== '')
                                            <input type="hidden" name="q" value="{{ $searchQuery }}">
                                        @endif

                                        @if ($canManageAssignee)
                                            <div class="requests-management-group">
                                                <span class="requests-assignee-label">Ответственный:</span>

                                                <select
                                                    name="assigned_to"
                                                    class="requests-assignee-select"
                                                    onchange="this.form.requestSubmit()"
                                                >
                                                    <option value="">Не назначен</option>
                                                    @foreach ($assignableUsers as $assigneeOption)
                                                        <option
                                                            value="{{ (int) $assigneeOption->id }}"
                                                            @selected((int) ($selectedTicket->assigned_to ?? 0) === (int) $assigneeOption->id)
                                                        >
                                                            {{ $assigneeOption->name }}
                                                        </option>
                                                    @endforeach
                                                </select>
                                            </div>
                                        @endif

                                        @if ($canManageStatus)
                                            <div class="requests-management-group">
                                                <span class="requests-assignee-label">Статус:</span>

                                                <select
                                                    name="status_value"
                                                    class="requests-assignee-select"
                                                    onchange="this.form.requestSubmit()"
                                                >
                                                    @foreach ($statusOptions as $statusKey => $statusLabel)
                                                        <option
                                                            value="{{ $statusKey }}"
                                                            @selected($status === $statusKey)
                                                        >
                                                            {{ $statusLabel }}
                                                        </option>
                                                    @endforeach
                                                </select>
                                            </div>
                                        @endif
                                    </form>
                                @else
                                    <div class="requests-meta-line">
                                        <span class="requests-meta-item">
                                            Ответственный:
                                            <strong>{{ $assignedTo !== '' ? $assignedTo : 'Не назначен' }}</strong>
                                        </span>
                                    </div>
                                @endif
                            </div>
                        </div>

                        <div class="requests-thread">
                            <div class="requests-thread-head">
                                <div>
                                    <h4 class="requests-thread-title">Лента сообщений</h4>
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
                                    <div class="requests-empty" style="min-height:11rem;">
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

                            @if ($tenantFilterId > 0)
                                <input type="hidden" name="tenant_id" value="{{ $tenantFilterId }}">
                            @endif
                            @if ($statusFilter !== 'all')
                                <input type="hidden" name="status_redirect" value="{{ $statusFilter }}">
                            @endif
                            @if ($searchQuery !== '')
                                <input type="hidden" name="q" value="{{ $searchQuery }}">
                            @endif

                            <div>
                                <div class="requests-composer-head">
                                    <label class="requests-composer-label">
                                        Ответ в диалог
                                    </label>
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
