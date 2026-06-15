<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Models\StaffConversation;
use App\Models\StaffConversationMessage;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\User;
use App\Support\StaffConversationService;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Component;

class QuickChatDrawer extends Component
{
    public bool $isOpen = false;

    public ?string $selectedType = null;

    public ?int $selectedId = null;

    public string $messageBody = '';

    public string $search = '';

    public function mount(): void
    {
        $requestedType = (string) request('quick_chat', '');

        if ($requestedType === 'staff') {
            $this->selectedType = 'staff';
            $this->selectedId = max(0, (int) request('conversation_id'));
            $this->isOpen = $this->selectedId > 0;

            return;
        }

        if ($requestedType === 'ticket') {
            $this->selectedType = 'ticket';
            $this->selectedId = max(0, (int) request('ticket_id'));
            $this->isOpen = $this->selectedId > 0;

            return;
        }

        if ((string) request('channel', '') === 'staff' && (int) request('conversation_id') > 0) {
            $this->selectedType = 'staff';
            $this->selectedId = (int) request('conversation_id');
            $this->isOpen = true;

            return;
        }

        if ((int) request('ticket_id') > 0) {
            $this->selectedType = 'ticket';
            $this->selectedId = (int) request('ticket_id');
            $this->isOpen = true;

            return;
        }

        $this->isOpen = trim((string) request()->path(), '/') === 'admin/requests';
    }

    public function render(): View
    {
        $recentChats = $this->isOpen ? $this->recentChats() : collect();
        $selectedChat = $this->isOpen ? $this->selectedChat() : null;

        return view('livewire.admin.quick-chat-drawer', [
            'recentChats' => $recentChats,
            'selectedChat' => $selectedChat,
            'messages' => $selectedChat ? $this->selectedMessages() : collect(),
            'unreadCount' => $this->unreadStaffMessagesCount(),
        ]);
    }

    public function openDrawer(?string $type = null, ?int $id = null): void
    {
        $this->isOpen = true;

        if ($type && $id) {
            $this->selectChat($type, $id);

            return;
        }

        if (! $this->selectedType || ! $this->selectedId) {
            $firstChat = $this->recentChats()->first();

            if (is_array($firstChat)) {
                $this->selectedType = (string) $firstChat['type'];
                $this->selectedId = (int) $firstChat['id'];
            }
        }
    }

    public function closeDrawer(): void
    {
        $this->isOpen = false;
        $this->messageBody = '';
        $this->resetErrorBag('messageBody');
    }

    public function selectChat(string $type, int $id): void
    {
        $type = $type === 'staff' ? 'staff' : 'ticket';
        $id = max(0, $id);

        if ($id <= 0) {
            return;
        }

        $user = $this->currentUser();
        if (! $user) {
            return;
        }

        if ($type === 'staff') {
            if (! $this->staffTablesAvailable()) {
                return;
            }

            $conversation = StaffConversation::query()->whereKey($id)->first();

            if (! $conversation || ! app(StaffConversationService::class)->canAccessConversation($user, $conversation)) {
                return;
            }

            app(StaffConversationService::class)->markConversationRead($conversation, $user);
        } else {
            $ticket = Ticket::query()->whereKey($id)->first();

            if (! $ticket || ! $this->canAccessTicket($user, $ticket)) {
                return;
            }
        }

        $this->selectedType = $type;
        $this->selectedId = $id;
        $this->messageBody = '';
        $this->resetErrorBag('messageBody');
        $this->dispatch('quick-chat-updated');
    }

    public function sendMessage(StaffConversationService $service): void
    {
        $this->validate([
            'messageBody' => ['required', 'string', 'min:1', 'max:5000'],
        ], [
            'messageBody.required' => 'Введите сообщение.',
            'messageBody.max' => 'Сообщение слишком длинное.',
        ]);

        $user = $this->currentUser();
        if (! $user || ! $this->selectedType || ! $this->selectedId) {
            return;
        }

        $body = trim($this->messageBody);
        if ($body === '') {
            $this->addError('messageBody', 'Введите сообщение.');

            return;
        }

        if ($this->selectedType === 'staff') {
            if (! $this->staffTablesAvailable()) {
                $this->addError('messageBody', 'Внутренние диалоги сотрудников пока недоступны.');

                return;
            }

            $conversation = StaffConversation::query()->whereKey((int) $this->selectedId)->first();

            if (! $conversation || ! $service->canAccessConversation($user, $conversation)) {
                $this->addError('messageBody', 'Диалог недоступен.');

                return;
            }

            $service->addMessage($conversation, $user, $body);
            $service->markConversationRead($conversation, $user);
        } else {
            $ticket = Ticket::query()->whereKey((int) $this->selectedId)->first();

            if (! $ticket || ! $this->canAccessTicket($user, $ticket)) {
                $this->addError('messageBody', 'Диалог недоступен.');

                return;
            }

            $statusBefore = (string) $ticket->status;

            TicketComment::query()->create([
                'ticket_id' => (int) $ticket->id,
                'user_id' => (int) $user->id,
                'body' => $body,
            ]);

            if ($statusBefore === 'new') {
                $ticket->status = 'in_progress';
                $ticket->save();
            } else {
                $ticket->touch();
            }
        }

        $this->messageBody = '';
        $this->dispatch('quick-chat-updated');

        Notification::make()
            ->title('Сообщение отправлено')
            ->success()
            ->send();
    }

    private function currentUser(): ?User
    {
        $user = Filament::auth()->user();

        return $user instanceof User ? $user : null;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function recentChats(): Collection
    {
        $user = $this->currentUser();
        if (! $user) {
            return collect();
        }

        return $this->recentTickets($user)
            ->merge($this->recentStaffConversations($user))
            ->sortByDesc('sort_at')
            ->values()
            ->take(30);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function recentTickets(User $user): Collection
    {
        $query = Ticket::query()
            ->with(['tenant:id,name,short_name'])
            ->withCount('comments')
            ->latest('updated_at')
            ->limit(20);

        $this->scopeMarket($query, $user);
        $this->scopeTicketSearch($query);

        return $query->get()->map(function (Ticket $ticket): array {
            $subject = trim((string) $ticket->subject) !== ''
                ? trim((string) $ticket->subject)
                : 'Диалог с арендатором';

            $tenantName = trim((string) ($ticket->tenant?->display_name ?? ''));

            return [
                'type' => 'ticket',
                'id' => (int) $ticket->id,
                'title' => $subject,
                'subtitle' => $tenantName !== '' ? $tenantName : 'Арендатор',
                'preview' => Str::limit(trim((string) $ticket->description), 110),
                'meta' => $this->formatDateTime($ticket->updated_at),
                'count' => $this->ticketMessagesCount($ticket),
                'sort_at' => $ticket->updated_at,
            ];
        });
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function recentStaffConversations(User $user): Collection
    {
        if (! $this->staffTablesAvailable()) {
            return collect();
        }

        $query = StaffConversation::query()
            ->with(['starter:id,name,email', 'recipient:id,name,email'])
            ->withCount('messages')
            ->latest('last_message_at')
            ->latest('updated_at')
            ->limit(20);

        $this->scopeStaffConversations($query, $user);
        $this->scopeStaffSearch($query);

        return $query->get()->map(function (StaffConversation $conversation) use ($user): array {
            $counterparty = (int) $conversation->created_by_user_id === (int) $user->id
                ? $conversation->recipient
                : $conversation->starter;

            $counterpartyName = trim((string) ($counterparty?->name ?? ''));
            if ($counterpartyName === '') {
                $counterpartyName = 'Сотрудник';
            }

            $subject = trim((string) $conversation->subject) !== ''
                ? trim((string) $conversation->subject)
                : 'Внутренний диалог';

            return [
                'type' => 'staff',
                'id' => (int) $conversation->id,
                'title' => $subject,
                'subtitle' => $counterpartyName,
                'preview' => 'Переписка сотрудников',
                'meta' => $this->formatDateTime($conversation->last_message_at ?: $conversation->updated_at),
                'count' => (int) $conversation->messages_count,
                'sort_at' => $conversation->last_message_at ?: $conversation->updated_at,
            ];
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    private function selectedChat(): ?array
    {
        $user = $this->currentUser();
        if (! $user || ! $this->selectedType || ! $this->selectedId) {
            return null;
        }

        if ($this->selectedType === 'staff') {
            return $this->selectedStaffChat($user);
        }

        return $this->selectedTicketChat($user);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function selectedTicketChat(User $user): ?array
    {
        $ticket = Ticket::query()
            ->with(['tenant:id,name,short_name'])
            ->withCount('comments')
            ->whereKey((int) $this->selectedId)
            ->first();

        if (! $ticket || ! $this->canAccessTicket($user, $ticket)) {
            return null;
        }

        return [
            'type' => 'ticket',
            'id' => (int) $ticket->id,
            'title' => trim((string) $ticket->subject) !== '' ? trim((string) $ticket->subject) : 'Диалог с арендатором',
            'subtitle' => trim((string) ($ticket->tenant?->display_name ?? '')) ?: 'Арендатор',
            'description' => trim((string) $ticket->description),
            'meta' => 'Обновлено: ' . $this->formatDateTime($ticket->updated_at),
            'count' => $this->ticketMessagesCount($ticket),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function selectedStaffChat(User $user): ?array
    {
        if (! $this->staffTablesAvailable()) {
            return null;
        }

        $conversation = StaffConversation::query()
            ->with(['starter:id,name,email', 'recipient:id,name,email'])
            ->withCount('messages')
            ->whereKey((int) $this->selectedId)
            ->first();

        if (! $conversation || ! app(StaffConversationService::class)->canAccessConversation($user, $conversation)) {
            return null;
        }

        $counterparty = (int) $conversation->created_by_user_id === (int) $user->id
            ? $conversation->recipient
            : $conversation->starter;

        return [
            'type' => 'staff',
            'id' => (int) $conversation->id,
            'title' => trim((string) $conversation->subject) !== '' ? trim((string) $conversation->subject) : 'Внутренний диалог',
            'subtitle' => trim((string) ($counterparty?->name ?? '')) ?: 'Сотрудник',
            'description' => '',
            'meta' => 'Обновлено: ' . $this->formatDateTime($conversation->last_message_at ?: $conversation->updated_at),
            'count' => (int) $conversation->messages_count,
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function selectedMessages(): Collection
    {
        if ($this->selectedType === 'staff') {
            return $this->selectedStaffMessages();
        }

        return $this->selectedTicketMessages();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function selectedTicketMessages(): Collection
    {
        $user = $this->currentUser();
        if (! $user || ! $this->selectedId) {
            return collect();
        }

        $ticket = Ticket::query()
            ->with(['tenant:id,name,short_name'])
            ->whereKey((int) $this->selectedId)
            ->first();

        if (! $ticket || ! $this->canAccessTicket($user, $ticket)) {
            return collect();
        }

        $initial = collect();
        $description = trim((string) $ticket->description);

        if ($description !== '') {
            $initial->push([
                'id' => 'ticket-' . (int) $ticket->id . '-initial',
                'user_name' => trim((string) ($ticket->tenant?->display_name ?? '')) ?: 'Арендатор',
                'body' => $description,
                'is_own' => false,
                'created_at' => $this->formatDateTime($ticket->created_at),
                'date_key' => optional($ticket->created_at)->toDateString(),
                'date_label' => $this->formatDateLabel($ticket->created_at),
            ]);
        }

        $comments = TicketComment::query()
            ->with(['user:id,name,email'])
            ->where('ticket_id', (int) $ticket->id)
            ->oldest('created_at')
            ->get()
            ->map(fn (TicketComment $comment): array => [
                'id' => 'ticket-comment-' . (int) $comment->id,
                'user_name' => trim((string) ($comment->user?->name ?? '')) ?: 'Пользователь',
                'body' => trim((string) $comment->body),
                'is_own' => (int) $comment->user_id === (int) $user->id,
                'created_at' => $this->formatDateTime($comment->created_at),
                'date_key' => optional($comment->created_at)->toDateString(),
                'date_label' => $this->formatDateLabel($comment->created_at),
            ]);

        return $initial->merge($comments)->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function selectedStaffMessages(): Collection
    {
        $user = $this->currentUser();
        if (! $user || ! $this->selectedId || ! $this->staffTablesAvailable()) {
            return collect();
        }

        $conversation = StaffConversation::query()->whereKey((int) $this->selectedId)->first();

        if (! $conversation || ! app(StaffConversationService::class)->canAccessConversation($user, $conversation)) {
            return collect();
        }

        return StaffConversationMessage::query()
            ->with(['user:id,name,email'])
            ->where('staff_conversation_id', (int) $conversation->id)
            ->oldest('created_at')
            ->get()
            ->map(fn (StaffConversationMessage $message): array => [
                'id' => 'staff-message-' . (int) $message->id,
                'user_name' => trim((string) ($message->user?->name ?? '')) ?: 'Пользователь',
                'body' => trim((string) $message->body),
                'is_own' => (int) $message->user_id === (int) $user->id,
                'created_at' => $this->formatDateTime($message->created_at),
                'date_key' => optional($message->created_at)->toDateString(),
                'date_label' => $this->formatDateLabel($message->created_at),
            ]);
    }

    private function canAccessTicket(User $user, Ticket $ticket): bool
    {
        if ($this->isSuperAdmin($user)) {
            return true;
        }

        return (int) ($user->market_id ?? 0) > 0
            && (int) ($user->market_id ?? 0) === (int) $ticket->market_id;
    }

    private function ticketMessagesCount(Ticket $ticket): int
    {
        $initialMessageCount = trim((string) $ticket->description) !== '' ? 1 : 0;

        return $initialMessageCount + (int) ($ticket->comments_count ?? 0);
    }

    private function scopeMarket(Builder $query, User $user): void
    {
        if ($this->isSuperAdmin($user)) {
            $marketId = $this->resolveMarketId($user);

            if ($marketId > 0) {
                $query->where('market_id', $marketId);
            }

            return;
        }

        $query->where('market_id', (int) ($user->market_id ?? 0));
    }

    private function scopeStaffConversations(Builder $query, User $user): void
    {
        if ($this->isSuperAdmin($user)) {
            $marketId = $this->resolveMarketId($user);

            if ($marketId > 0) {
                $query->where('market_id', $marketId);
            }

            return;
        }

        $query->where(function (Builder $pair) use ($user): void {
            $pair
                ->where('created_by_user_id', (int) $user->id)
                ->orWhere('recipient_user_id', (int) $user->id);
        });
    }

    private function scopeTicketSearch(Builder $query): void
    {
        $search = trim($this->search);
        if ($search === '') {
            return;
        }

        $query->where(function (Builder $searchQuery) use ($search): void {
            $searchQuery
                ->where('subject', 'like', '%' . $search . '%')
                ->orWhere('description', 'like', '%' . $search . '%')
                ->orWhereHas('tenant', function (Builder $tenantQuery) use ($search): void {
                    $tenantQuery
                        ->where('name', 'like', '%' . $search . '%')
                        ->orWhere('short_name', 'like', '%' . $search . '%');
                });
        });
    }

    private function scopeStaffSearch(Builder $query): void
    {
        $search = trim($this->search);
        if ($search === '') {
            return;
        }

        $query->where(function (Builder $searchQuery) use ($search): void {
            $searchQuery
                ->where('subject', 'like', '%' . $search . '%')
                ->orWhereHas('starter', fn (Builder $userQuery): Builder => $this->scopeUserSearch($userQuery, $search))
                ->orWhereHas('recipient', fn (Builder $userQuery): Builder => $this->scopeUserSearch($userQuery, $search));
        });
    }

    private function scopeUserSearch(Builder $query, string $search): Builder
    {
        return $query
            ->where('name', 'like', '%' . $search . '%')
            ->orWhere('email', 'like', '%' . $search . '%');
    }

    private function unreadStaffMessagesCount(): int
    {
        $user = $this->currentUser();
        if (! $user || ! $this->staffTablesAvailable() || ! Schema::hasColumn('staff_conversation_messages', 'read_at')) {
            return 0;
        }

        return (int) StaffConversationMessage::query()
            ->join('staff_conversations', 'staff_conversations.id', '=', 'staff_conversation_messages.staff_conversation_id')
            ->whereNull('staff_conversation_messages.read_at')
            ->where('staff_conversation_messages.user_id', '<>', (int) $user->id)
            ->where(function (Builder $query) use ($user): void {
                $query
                    ->where('staff_conversations.created_by_user_id', (int) $user->id)
                    ->orWhere('staff_conversations.recipient_user_id', (int) $user->id);
            })
            ->count();
    }

    private function staffTablesAvailable(): bool
    {
        return Schema::hasTable('staff_conversations')
            && Schema::hasTable('staff_conversation_messages');
    }

    private function resolveMarketId(User $user): int
    {
        if (! $this->isSuperAdmin($user)) {
            return (int) ($user->market_id ?? 0);
        }

        $panelId = Filament::getCurrentPanel()?->getId() ?? 'admin';

        return (int) (
            session('dashboard_market_id')
            ?: session("filament.{$panelId}.selected_market_id")
            ?: session("filament_{$panelId}_market_id")
            ?: session('filament.admin.selected_market_id')
            ?: 0
        );
    }

    private function isSuperAdmin(User $user): bool
    {
        return method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin();
    }

    private function formatDateTime(mixed $value): string
    {
        if (! $value) {
            return '';
        }

        try {
            return $value->timezone(config('app.timezone'))->format('d.m.Y H:i');
        } catch (\Throwable) {
            return '';
        }
    }

    private function formatDateLabel(mixed $value): string
    {
        if (! $value) {
            return '';
        }

        try {
            $date = $value->timezone(config('app.timezone'));
            $key = $date->toDateString();

            if ($key === now()->toDateString()) {
                return 'Сегодня';
            }

            if ($key === now()->subDay()->toDateString()) {
                return 'Вчера';
            }

            return $date->format('d.m.Y');
        } catch (\Throwable) {
            return '';
        }
    }
}
