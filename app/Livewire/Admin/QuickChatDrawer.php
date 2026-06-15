<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Models\StaffConversation;
use App\Models\StaffConversationMessage;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\User;
use App\Support\StaffConversationService;
use App\Support\MessageAttachmentStorage;
use App\Support\TicketAccessService;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

class QuickChatDrawer extends Component
{
    use WithFileUploads;

    public bool $isOpen = false;

    public ?string $selectedType = null;

    public ?int $selectedId = null;

    public string $messageBody = '';

    /**
     * @var array<int, TemporaryUploadedFile>
     */
    public array $messageAttachments = [];

    public string $search = '';

    public function mount(): void
    {
        $requestedType = (string) request('quick_chat', '');

        if ($requestedType === 'staff') {
            $this->selectedType = 'staff';
            $this->selectedId = $this->resolveStaffPeerIdFromConversationId(max(0, (int) request('conversation_id')));
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
            $this->selectedId = $this->resolveStaffPeerIdFromConversationId((int) request('conversation_id'));
            $this->isOpen = $this->selectedId > 0;

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
        $this->messageAttachments = [];
        $this->resetErrorBag();
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

            $peer = User::query()->whereKey($id)->first();

            if (! $peer || ! $this->canAccessStaffPeer($user, $peer)) {
                return;
            }

            $this->markStaffPeerRead($user, (int) $peer->id);
        } else {
            $ticket = Ticket::query()->whereKey($id)->first();

            if (! $ticket || ! $this->canAccessTicket($user, $ticket)) {
                return;
            }
        }

        $this->selectedType = $type;
        $this->selectedId = $id;
        $this->messageBody = '';
        $this->messageAttachments = [];
        $this->resetErrorBag();
        $this->dispatch('quick-chat-updated');
    }

    public function sendMessage(StaffConversationService $service): void
    {
        $this->validate([
            'messageBody' => ['nullable', 'string', 'max:5000'],
            'messageAttachments' => ['nullable', 'array', 'max:5'],
            'messageAttachments.*' => ['file', 'max:20480'],
        ], [
            'messageBody.max' => 'Сообщение слишком длинное.',
            'messageAttachments.max' => 'Можно прикрепить не больше 5 файлов.',
            'messageAttachments.*.max' => 'Файл должен быть не больше 20 МБ.',
        ]);

        $user = $this->currentUser();
        if (! $user || ! $this->selectedType || ! $this->selectedId) {
            return;
        }

        $body = trim($this->messageBody);
        $files = array_values(array_filter(
            $this->messageAttachments,
            static fn ($file): bool => $file instanceof TemporaryUploadedFile,
        ));

        if ($body === '' && $files === []) {
            $this->addError('messageBody', 'Введите сообщение или прикрепите файл.');

            return;
        }

        $attachments = MessageAttachmentStorage::store($files, 'chat-attachments');

        if ($this->selectedType === 'staff') {
            if (! $this->staffTablesAvailable()) {
                $this->addError('messageBody', 'Внутренние диалоги сотрудников пока недоступны.');

                return;
            }

            $peer = User::query()->whereKey((int) $this->selectedId)->first();

            if (! $peer || ! $this->canAccessStaffPeer($user, $peer)) {
                $this->addError('messageBody', 'Диалог недоступен.');

                return;
            }

            $conversation = $this->latestStaffConversationWithPeer($user, (int) $peer->id);

            if ($conversation) {
                $service->addMessage($conversation, $user, $body, $attachments);
            } else {
                $conversation = $service->startConversation($user, $peer, '', $body, $attachments);
            }

            $this->markStaffPeerRead($user, (int) $peer->id);
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
                'attachments' => $attachments !== [] ? $attachments : null,
            ]);

            if ($statusBefore === 'new') {
                $ticket->status = 'in_progress';
                $ticket->save();
            } else {
                $ticket->touch();
            }
        }

        $this->messageBody = '';
        $this->messageAttachments = [];
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
        app(TicketAccessService::class)->scopeVisibleTo($query, $user);
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
            ->limit(100);

        $this->scopeStaffConversations($query, $user);
        $this->scopeStaffSearch($query);

        return $query->get()
            ->groupBy(fn (StaffConversation $conversation): int => $this->staffPeerId($conversation, $user))
            ->filter(fn (Collection $conversations, mixed $peerId): bool => (int) $peerId > 0 && $conversations->isNotEmpty())
            ->map(function (Collection $conversations, mixed $peerId) use ($user): array {
                $peerId = (int) $peerId;

                /** @var StaffConversation $latestConversation */
                $latestConversation = $conversations->sortByDesc(
                    fn (StaffConversation $conversation): mixed => $conversation->last_message_at ?: $conversation->updated_at
                )->first();

                $counterparty = (int) $latestConversation->created_by_user_id === (int) $user->id
                    ? $latestConversation->recipient
                    : $latestConversation->starter;

                $counterpartyName = trim((string) ($counterparty?->name ?? ''));
                if ($counterpartyName === '') {
                    $counterpartyName = 'Сотрудник';
                }

                $latestMessage = $this->latestStaffMessageWithPeer($user, $peerId);
                $preview = $latestMessage
                    ? (trim((string) $latestMessage->body) !== ''
                        ? Str::limit(trim((string) $latestMessage->body), 110)
                        : (MessageAttachmentStorage::present($latestMessage->attachments) !== [] ? 'Вложение' : 'Переписка сотрудников'))
                    : 'Переписка сотрудников';

                $lastAt = $latestMessage?->created_at ?: ($latestConversation->last_message_at ?: $latestConversation->updated_at);

                return [
                    'type' => 'staff',
                    'id' => $peerId,
                    'title' => $counterpartyName,
                    'subtitle' => 'Сотрудник',
                    'preview' => $preview,
                    'meta' => $this->formatListDateTime($lastAt),
                    'count' => $conversations->sum(fn (StaffConversation $conversation): int => (int) $conversation->messages_count),
                    'unread_count' => $this->unreadStaffMessagesCountForPeer($user, $peerId),
                    'sort_at' => $lastAt,
                ];
            })
            ->sortByDesc('sort_at')
            ->values();
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

        $peer = User::query()->whereKey((int) $this->selectedId)->first();

        if (! $peer || ! $this->canAccessStaffPeer($user, $peer)) {
            return null;
        }

        $latestConversation = $this->latestStaffConversationWithPeer($user, (int) $peer->id);

        if (! $latestConversation) {
            return null;
        }

        $messagesCount = (int) StaffConversationMessage::query()
            ->join('staff_conversations', 'staff_conversations.id', '=', 'staff_conversation_messages.staff_conversation_id')
            ->where(function (Builder $pair) use ($user, $peer): void {
                $pair
                    ->where(function (Builder $direct) use ($user, $peer): void {
                        $direct
                            ->where('staff_conversations.created_by_user_id', (int) $user->id)
                            ->where('staff_conversations.recipient_user_id', (int) $peer->id);
                    })
                    ->orWhere(function (Builder $reverse) use ($user, $peer): void {
                        $reverse
                            ->where('staff_conversations.created_by_user_id', (int) $peer->id)
                            ->where('staff_conversations.recipient_user_id', (int) $user->id);
                    });
            })
            ->count('staff_conversation_messages.id');

        return [
            'type' => 'staff',
            'id' => (int) $peer->id,
            'title' => trim((string) $peer->name) !== '' ? trim((string) $peer->name) : 'Сотрудник',
            'subtitle' => trim((string) $peer->email),
            'description' => '',
            'meta' => 'Обновлено: ' . $this->formatDateTime($latestConversation->last_message_at ?: $latestConversation->updated_at),
            'count' => $messagesCount,
        ];
    }

    private function latestStaffConversationWithPeer(User $user, int $peerId): ?StaffConversation
    {
        return StaffConversation::query()
            ->with(['starter:id,name,email', 'recipient:id,name,email'])
            ->where(function (Builder $pair) use ($user, $peerId): void {
                $pair
                    ->where(function (Builder $direct) use ($user, $peerId): void {
                        $direct
                            ->where('created_by_user_id', (int) $user->id)
                            ->where('recipient_user_id', $peerId);
                    })
                    ->orWhere(function (Builder $reverse) use ($user, $peerId): void {
                        $reverse
                            ->where('created_by_user_id', $peerId)
                            ->where('recipient_user_id', (int) $user->id);
                    });
            })
            ->latest('last_message_at')
            ->latest('updated_at')
            ->first();
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
            ->with(['tenant:id,name,short_name', 'attachments:id,ticket_id,file_path,original_name'])
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
                'attachments' => MessageAttachmentStorage::present(
                    $ticket->attachments
                        ->map(fn ($attachment): array => [
                            'path' => (string) $attachment->file_path,
                            'name' => (string) $attachment->original_name,
                        ])
                        ->all(),
                ),
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
                'attachments' => MessageAttachmentStorage::present($comment->attachments),
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

        $peer = User::query()->whereKey((int) $this->selectedId)->first();

        if (! $peer || ! $this->canAccessStaffPeer($user, $peer)) {
            return collect();
        }

        return StaffConversationMessage::query()
            ->with(['user:id,name,email'])
            ->join('staff_conversations', 'staff_conversations.id', '=', 'staff_conversation_messages.staff_conversation_id')
            ->where(function (Builder $pair) use ($user, $peer): void {
                $pair
                    ->where(function (Builder $direct) use ($user, $peer): void {
                        $direct
                            ->where('staff_conversations.created_by_user_id', (int) $user->id)
                            ->where('staff_conversations.recipient_user_id', (int) $peer->id);
                    })
                    ->orWhere(function (Builder $reverse) use ($user, $peer): void {
                        $reverse
                            ->where('staff_conversations.created_by_user_id', (int) $peer->id)
                            ->where('staff_conversations.recipient_user_id', (int) $user->id);
                    });
            })
            ->orderBy('staff_conversation_messages.created_at')
            ->select('staff_conversation_messages.*')
            ->get()
            ->map(fn (StaffConversationMessage $message): array => [
                'id' => 'staff-message-' . (int) $message->id,
                'user_name' => trim((string) ($message->user?->name ?? '')) ?: 'Пользователь',
                'body' => trim((string) $message->body),
                'is_own' => (int) $message->user_id === (int) $user->id,
                'created_at' => $this->formatDateTime($message->created_at),
                'date_key' => optional($message->created_at)->toDateString(),
                'date_label' => $this->formatDateLabel($message->created_at),
                'attachments' => MessageAttachmentStorage::present($message->attachments),
            ]);
    }

    private function canAccessTicket(User $user, Ticket $ticket): bool
    {
        return app(TicketAccessService::class)->canView($user, $ticket);
    }

    private function canAccessStaffPeer(User $user, User $peer): bool
    {
        if ((int) $user->id === (int) $peer->id) {
            return false;
        }

        if ($this->isSuperAdmin($user)) {
            return true;
        }

        return (int) ($user->market_id ?? 0) > 0
            && (int) ($user->market_id ?? 0) === (int) ($peer->market_id ?? 0);
    }

    private function staffPeerId(StaffConversation $conversation, User $user): int
    {
        if ((int) $conversation->created_by_user_id === (int) $user->id) {
            return (int) $conversation->recipient_user_id;
        }

        if ((int) $conversation->recipient_user_id === (int) $user->id) {
            return (int) $conversation->created_by_user_id;
        }

        return 0;
    }

    private function resolveStaffPeerIdFromConversationId(int $conversationId): ?int
    {
        if ($conversationId <= 0 || ! $this->staffTablesAvailable()) {
            return null;
        }

        $user = $this->currentUser();
        if (! $user) {
            return null;
        }

        $conversation = StaffConversation::query()->whereKey($conversationId)->first();
        if (! $conversation || ! app(StaffConversationService::class)->canAccessConversation($user, $conversation)) {
            return null;
        }

        $peerId = $this->staffPeerId($conversation, $user);
        if ($peerId <= 0) {
            return null;
        }

        $this->markStaffPeerRead($user, $peerId);

        return $peerId;
    }

    private function latestStaffMessageWithPeer(User $user, int $peerId): ?StaffConversationMessage
    {
        return StaffConversationMessage::query()
            ->join('staff_conversations', 'staff_conversations.id', '=', 'staff_conversation_messages.staff_conversation_id')
            ->where(function (Builder $pair) use ($user, $peerId): void {
                $pair
                    ->where(function (Builder $direct) use ($user, $peerId): void {
                        $direct
                            ->where('staff_conversations.created_by_user_id', (int) $user->id)
                            ->where('staff_conversations.recipient_user_id', $peerId);
                    })
                    ->orWhere(function (Builder $reverse) use ($user, $peerId): void {
                        $reverse
                            ->where('staff_conversations.created_by_user_id', $peerId)
                            ->where('staff_conversations.recipient_user_id', (int) $user->id);
                    });
            })
            ->latest('staff_conversation_messages.created_at')
            ->select('staff_conversation_messages.*')
            ->first();
    }

    private function markStaffPeerRead(User $user, int $peerId): void
    {
        if (! Schema::hasColumn('staff_conversation_messages', 'read_at')) {
            return;
        }

        $messageIds = StaffConversationMessage::query()
            ->join('staff_conversations', 'staff_conversations.id', '=', 'staff_conversation_messages.staff_conversation_id')
            ->whereNull('staff_conversation_messages.read_at')
            ->where('staff_conversation_messages.user_id', $peerId)
            ->where(function (Builder $pair) use ($user, $peerId): void {
                $pair
                    ->where(function (Builder $direct) use ($user, $peerId): void {
                        $direct
                            ->where('staff_conversations.created_by_user_id', (int) $user->id)
                            ->where('staff_conversations.recipient_user_id', $peerId);
                    })
                    ->orWhere(function (Builder $reverse) use ($user, $peerId): void {
                        $reverse
                            ->where('staff_conversations.created_by_user_id', $peerId)
                            ->where('staff_conversations.recipient_user_id', (int) $user->id);
                    });
            })
            ->pluck('staff_conversation_messages.id');

        if ($messageIds->isEmpty()) {
            return;
        }

        StaffConversationMessage::query()
            ->whereKey($messageIds)
            ->update(['read_at' => now()]);
    }

    private function unreadStaffMessagesCountForPeer(User $user, int $peerId): int
    {
        if (! Schema::hasColumn('staff_conversation_messages', 'read_at')) {
            return 0;
        }

        return (int) StaffConversationMessage::query()
            ->join('staff_conversations', 'staff_conversations.id', '=', 'staff_conversation_messages.staff_conversation_id')
            ->whereNull('staff_conversation_messages.read_at')
            ->where('staff_conversation_messages.user_id', $peerId)
            ->where(function (Builder $pair) use ($user, $peerId): void {
                $pair
                    ->where(function (Builder $direct) use ($user, $peerId): void {
                        $direct
                            ->where('staff_conversations.created_by_user_id', (int) $user->id)
                            ->where('staff_conversations.recipient_user_id', $peerId);
                    })
                    ->orWhere(function (Builder $reverse) use ($user, $peerId): void {
                        $reverse
                            ->where('staff_conversations.created_by_user_id', $peerId)
                            ->where('staff_conversations.recipient_user_id', (int) $user->id);
                    });
            })
            ->count('staff_conversation_messages.id');
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

    private function formatListDateTime(mixed $value): string
    {
        if (! $value) {
            return '';
        }

        try {
            $date = $value->timezone(config('app.timezone'));

            if ($date->toDateString() === now()->toDateString()) {
                return $date->format('H:i');
            }

            return $date->format('d.m.Y');
        } catch (\Throwable) {
            return '';
        }
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
