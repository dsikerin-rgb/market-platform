<?php

// app/Livewire/Admin/QuickChatDrawer.php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\StaffConversation;
use App\Models\StaffConversationMessage;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\User;
use App\Notifications\TicketChatNotification;
use App\Services\Ai\AiAgentActionTool;
use App\Services\Ai\AiAgentSettings;
use App\Services\Ai\AiConsultantService;
use App\Services\Ai\AiPageNudgeContextService;
use App\Services\Ai\AiUserProfileService;
use App\Support\MessageAttachmentStorage;
use App\Support\Search\LooseSearch;
use App\Support\StaffConversationService;
use App\Support\TicketAccessService;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

class QuickChatDrawer extends Component
{
    use WithFileUploads;

    private const AI_REPLY_DELAY_MS = 950;

    private const AI_GREETING_MESSAGE = 'Здравствуйте. Чем помочь по этой странице или данным рынка? Могу проверить информацию, подготовить ссылку, создать задачу, напоминание или отправить сообщение.';

    public bool $isOpen = false;

    public ?string $selectedType = null;

    public ?int $selectedId = null;

    public string $messageBody = '';

    public bool $isAiReplyPending = false;

    public ?string $pendingAiQuestion = null;

    /**
     * @var list<array{role:string,content:string}>
     */
    public array $pendingAiHistory = [];

    /**
     * @var array<string,mixed>
     */
    public array $pendingAiPageContext = [];

    /**
     * @var list<array{id:string,user_name:string,body:string,is_own:bool,created_at:string,date_key:string,date_label:string,attachments:list<array<string,mixed>>,chips:list<array{label:string,url:string}>,suggestions:list<string>,pending_action:?array<string,mixed>}>
     */
    public array $aiMessages = [];

    /**
     * @var array{url?:string,path?:string,title?:string,heading?:string}
     */
    public array $pageContext = [];

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

            $user = $this->currentUser();
            $ticket = $this->selectedId > 0
                ? Ticket::query()->whereKey((int) $this->selectedId)->first()
                : null;

            if ($user && $ticket && $this->canAccessTicket($user, $ticket)) {
                $this->markTicketMessageNotificationsRead($user, (int) $ticket->id);
            }

            return;
        }

        if ($requestedType === 'ai') {
            $this->selectedType = 'ai';
            $this->selectedId = 1;
            $this->isOpen = true;
            $this->loadAiMessages();

            return;
        }

        $this->isOpen = false;
    }

    public function render(): View
    {
        $recentChats = $this->isOpen ? $this->recentChats() : collect();
        $selectedChat = $this->isOpen ? $this->selectedChat() : null;

        return view('livewire.admin.quick-chat-drawer', [
            'recentChats' => $recentChats,
            'selectedChat' => $selectedChat,
            'messages' => $selectedChat ? $this->selectedMessages() : collect(),
            'unreadCount' => $this->unreadIncomingMessagesCount(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function openDrawer(?string $type = null, ?int $id = null, ?string $source = null, array $context = []): void
    {
        if ($context !== []) {
            $this->updatePageContext($context);
        }

        $this->isOpen = true;

        if ($type === 'ai') {
            $fromPageNudge = $source === 'page_nudge';
            $this->selectChat('ai', 1, ensureGreeting: ! $fromPageNudge);

            if ($fromPageNudge) {
                $this->ensureAiPageNudgeMessage();
            }

            return;
        }

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
        $this->clearPendingAiReply();
        $this->resetErrorBag();
    }

    public function useAiSuggestion(string $text): void
    {
        if ($this->selectedType !== 'ai') {
            return;
        }

        $text = Str::limit(trim($text), 500, '');
        if ($text === '') {
            return;
        }

        $this->messageBody = $text;
        $this->sendAiMessage($text);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function updatePageContext(array $context): void
    {
        $this->pageContext = [
            'url' => Str::limit(trim((string) ($context['url'] ?? '')), 500, ''),
            'path' => Str::limit(trim((string) ($context['path'] ?? '')), 300, ''),
            'title' => Str::limit(trim((string) ($context['title'] ?? '')), 160, ''),
            'heading' => Str::limit(trim((string) ($context['heading'] ?? '')), 160, ''),
        ];

        $user = $this->currentUser();
        if ($user && $this->selectedType === 'ai') {
            $this->touchAiConversationPageContext($user);
        }
    }

    public function selectChat(string $type, int $id, bool $ensureGreeting = true): void
    {
        $type = in_array($type, ['ai', 'staff', 'ticket'], true) ? $type : 'ticket';
        if ($type === 'ai') {
            $id = 1;
        }

        $id = max(0, $id);

        if ($id <= 0) {
            return;
        }

        $user = $this->currentUser();
        if (! $user) {
            return;
        }

        if ($type === 'ai') {
            $this->loadAiMessages($ensureGreeting);
        } elseif ($type === 'staff') {
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

            $this->markTicketMessageNotificationsRead($user, (int) $ticket->id);
        }

        $this->selectedType = $type;
        $this->selectedId = $id;
        $this->messageBody = '';
        $this->messageAttachments = [];
        $this->clearPendingAiReply();
        $this->resetErrorBag();
        $this->dispatch('quick-chat-updated');
    }

    public function sendMessage(StaffConversationService $service, AiConsultantService $aiConsultant): void
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

        if ($this->selectedType === 'ai') {
            if ($body === '') {
                $this->addError('messageBody', 'Введите вопрос для ИИ-консультанта.');

                return;
            }

            $this->sendAiMessage($body);

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

    private function sendAiMessage(string $body): void
    {
        $user = $this->currentUser();
        if (! $user || $this->selectedType !== 'ai') {
            return;
        }

        $body = trim($body);
        if ($body === '') {
            return;
        }

        if ($this->isAiReplyPending) {
            return;
        }

        $history = $this->aiConversationHistory();
        $this->appendAiMessage($user->name ?: 'Вы', $body, true);

        $conversation = $this->aiConversation($user, create: false);
        $marketId = $this->resolveMarketId($user);
        $profileService = app(AiUserProfileService::class);
        $profileService->syncFromConversation($user, $conversation, $marketId);

        if ($profileService->isLightOnboardingDeferral($body)) {
            $this->appendAiMessage(
                'ИИ-консультант',
                'Хорошо, не буду отвлекать. Когда будет удобно, напишите «давай познакомимся».',
                false,
                [],
                ['kind' => 'light_onboarding_snoozed'],
            );
            $this->messageBody = '';
            $this->messageAttachments = [];
            $this->dispatch('quick-chat-updated');

            return;
        }

        $this->pendingAiQuestion = $body;
        $this->pendingAiHistory = $history;
        $this->pendingAiPageContext = $this->pageContext;
        $this->isAiReplyPending = true;
        $this->messageBody = '';
        $this->messageAttachments = [];
        $this->dispatch('quick-chat-updated');
        $this->dispatch('quick-chat-ai-reply-queued', delay: self::AI_REPLY_DELAY_MS);
    }

    public function completeAiReply(AiConsultantService $aiConsultant): void
    {
        if (! $this->isAiReplyPending || $this->selectedType !== 'ai') {
            return;
        }

        $user = $this->currentUser();
        $question = trim((string) $this->pendingAiQuestion);

        if (! $user || $question === '') {
            $this->clearPendingAiReply();

            return;
        }

        $answer = $aiConsultant->answer(
            $user,
            $this->resolveMarketId($user),
            $question,
            $this->pendingAiHistory,
            $this->pendingAiPageContext,
        );
        $metadata = [];
        if (! empty($answer['pending_action']) && is_array($answer['pending_action'])) {
            $metadata['pending_action'] = $answer['pending_action'];
        }

        $this->appendAiMessage('ИИ-консультант', $answer['answer'], false, $answer['chips'] ?? [], $metadata);

        $this->clearPendingAiReply();
        $this->dispatch('quick-chat-updated');
    }

    private function clearPendingAiReply(): void
    {
        $this->isAiReplyPending = false;
        $this->pendingAiQuestion = null;
        $this->pendingAiHistory = [];
        $this->pendingAiPageContext = [];
    }

    public function confirmAiAction(string $messageId): void
    {
        $user = $this->currentUser();
        if (! $user || $this->selectedType !== 'ai') {
            return;
        }

        $conversation = $this->aiConversation($user, create: false);
        $claimedAction = DB::transaction(function () use ($conversation, $messageId, $user): ?array {
            $message = $this->aiMessageForAction($conversation, $messageId, lockForUpdate: true);
            if (! $message instanceof AiMessage) {
                return null;
            }

            $metadata = (array) ($message->metadata ?? []);
            $pendingAction = (array) ($metadata['pending_action'] ?? []);
            if (($pendingAction['status'] ?? null) !== 'pending') {
                return null;
            }

            $payload = (array) ($pendingAction['payload'] ?? []);
            $tool = strtolower(trim((string) ($payload['tool'] ?? $pendingAction['tool'] ?? '')));
            $settingsService = app(AiAgentSettings::class);
            $settings = $settingsService->get();
            if (! (bool) ($settings['action_tools_enabled'] ?? false) || ! $settingsService->canPrepareAction($user, $tool, $settings)) {
                $pendingAction['status'] = 'failed';
                $pendingAction['confirmed_by_user_id'] = (int) $user->id;
                $pendingAction['confirmed_at'] = now()->toIso8601String();
                $pendingAction['result_message'] = 'Не выполнено: для вашей роли это действие недоступно.';
                $metadata['pending_action'] = $pendingAction;
                $message->forceFill(['metadata' => $metadata])->save();

                return [
                    'message_id' => (int) $message->id,
                    'denied' => true,
                    'message' => $pendingAction['result_message'],
                ];
            }

            $pendingAction['status'] = 'running';
            $pendingAction['started_by_user_id'] = (int) $user->id;
            $pendingAction['started_at'] = now()->toIso8601String();
            $metadata['pending_action'] = $pendingAction;
            $message->forceFill(['metadata' => $metadata])->save();

            return [
                'message_id' => (int) $message->id,
                'payload' => $payload,
            ];
        });

        if (! is_array($claimedAction)) {
            return;
        }

        if ((bool) ($claimedAction['denied'] ?? false)) {
            $this->appendAiMessage(
                'ИИ-консультант',
                trim((string) ($claimedAction['message'] ?? 'Не выполнено: для вашей роли это действие недоступно.')),
                false,
                [],
                [
                    'kind' => 'action_result',
                    'action_message_id' => (int) ($claimedAction['message_id'] ?? 0),
                    'action_status' => 'failed',
                ],
            );

            $this->loadAiMessages();
            $this->dispatch('quick-chat-updated');

            return;
        }

        $result = app(AiAgentActionTool::class)->run($user, $this->resolveMarketId($user), (array) ($claimedAction['payload'] ?? []));
        $ok = (bool) ($result['ok'] ?? false);

        $message = AiMessage::query()
            ->whereKey((int) $claimedAction['message_id'])
            ->first();
        if (! $message instanceof AiMessage) {
            return;
        }

        $metadata = (array) ($message->metadata ?? []);
        $pendingAction = (array) ($metadata['pending_action'] ?? []);

        $pendingAction['status'] = $ok ? 'confirmed' : 'failed';
        $pendingAction['confirmed_by_user_id'] = (int) $user->id;
        $pendingAction['confirmed_at'] = now()->toIso8601String();
        $pendingAction['result_message'] = trim((string) ($result['message'] ?? ''));
        $pendingAction['result_data'] = (array) ($result['data'] ?? []);

        $metadata['pending_action'] = $pendingAction;
        $message->forceFill(['metadata' => $metadata])->save();

        $this->appendAiMessage(
            'ИИ-консультант',
            $pendingAction['result_message'] !== '' ? $pendingAction['result_message'] : ($ok ? 'Готово.' : 'Не удалось выполнить действие.'),
            false,
            (array) ($result['chips'] ?? []),
            [
                'kind' => 'action_result',
                'action_message_id' => (int) $message->id,
                'action_status' => $pendingAction['status'],
            ],
        );

        $this->loadAiMessages();
        $this->dispatch('quick-chat-updated');
    }

    public function cancelAiAction(string $messageId): void
    {
        $user = $this->currentUser();
        if (! $user || $this->selectedType !== 'ai') {
            return;
        }

        $conversation = $this->aiConversation($user, create: false);
        $cancelled = DB::transaction(function () use ($conversation, $messageId, $user): bool {
            $message = $this->aiMessageForAction($conversation, $messageId, lockForUpdate: true);
            if (! $message instanceof AiMessage) {
                return false;
            }

            $metadata = (array) ($message->metadata ?? []);
            $pendingAction = (array) ($metadata['pending_action'] ?? []);
            if (($pendingAction['status'] ?? null) !== 'pending') {
                return false;
            }

            $pendingAction['status'] = 'cancelled';
            $pendingAction['cancelled_by_user_id'] = (int) $user->id;
            $pendingAction['cancelled_at'] = now()->toIso8601String();
            $pendingAction['result_message'] = 'Отменено. Ничего не сделал.';

            $metadata['pending_action'] = $pendingAction;
            $message->forceFill(['metadata' => $metadata])->save();

            return true;
        });

        if (! $cancelled) {
            return;
        }

        $this->appendAiMessage(
            'ИИ-консультант',
            'Отменил. Ничего не сделал.',
            false,
            [],
            [
                'kind' => 'action_result',
                'action_message_id' => (int) $message->id,
                'action_status' => 'cancelled',
            ],
        );

        $this->loadAiMessages();
        $this->dispatch('quick-chat-updated');
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

        $staffConversations = $this->recentStaffConversations($user);

        return $this->recentTickets($user)
            ->merge($staffConversations)
            ->prepend($this->aiConsultantChat())
            ->merge($this->staffSearchCandidates(
                $user,
                $staffConversations->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
            ))
            ->sortByDesc('sort_at')
            ->values()
            ->take(30);
    }

    /**
     * @return array<string, mixed>
     */
    private function aiConsultantChat(): array
    {
        return [
            'type' => 'ai',
            'id' => 1,
            'title' => 'ИИ-консультант',
            'subtitle' => 'База данных рынка',
            'preview' => 'Спросите про арендаторов, места, договоры, задолженность, задачи или сообщения.',
            'meta' => 'помощник',
            'count' => $this->aiMessageCount(),
            'unread_count' => 0,
            'sort_at' => now()->subYears(10),
        ];
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

        return $query->get()->toBase()->map(function (Ticket $ticket) use ($user): array {
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
                'unread_count' => $this->unreadTicketMessageNotificationsCount($user, (int) $ticket->id),
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
            ->toBase()
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
     * @param  list<int>  $existingPeerIds
     * @return Collection<int, array<string, mixed>>
     */
    private function staffSearchCandidates(User $user, array $existingPeerIds): Collection
    {
        $search = trim($this->search);
        if ($search === '' || ! $this->staffTablesAvailable()) {
            return collect();
        }

        $query = User::query()
            ->select(['id', 'name', 'email', 'market_id', 'tenant_id'])
            ->where('id', '<>', (int) $user->id)
            ->whereNull('tenant_id')
            ->whereNotIn('id', $existingPeerIds)
            ->where(function (Builder $searchQuery) use ($search): void {
                $this->scopeUserSearch($searchQuery, $search);
            })
            ->orderBy('name')
            ->orderBy('email')
            ->limit(10);

        if ($this->isSuperAdmin($user)) {
            $marketId = $this->resolveMarketId($user);
            if ($marketId > 0) {
                $query->where('market_id', $marketId);
            }
        } else {
            $marketId = (int) ($user->market_id ?? 0);
            if ($marketId > 0) {
                $query->where(function (Builder $query) use ($marketId): void {
                    $query
                        ->where('market_id', $marketId)
                        ->orWhereHas('roles', function (Builder $roleQuery): void {
                            $roleQuery->where('name', 'super-admin');
                        });
                });
            } else {
                $query->whereHas('roles', function (Builder $roleQuery): void {
                    $roleQuery->where('name', 'super-admin');
                });
            }
        }

        return $query->get()
            ->toBase()
            ->filter(fn (User $peer): bool => $this->canAccessStaffPeer($user, $peer))
            ->map(function (User $peer): array {
                $name = trim((string) $peer->name);
                $email = trim((string) $peer->email);

                return [
                    'type' => 'staff',
                    'id' => (int) $peer->id,
                    'title' => $name !== '' ? $name : ($email !== '' ? $email : 'Сотрудник'),
                    'subtitle' => 'Новый диалог',
                    'preview' => 'Нажмите, чтобы начать переписку',
                    'meta' => '',
                    'count' => 0,
                    'unread_count' => 0,
                    'sort_at' => now(),
                    'is_candidate' => true,
                ];
            })
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

        if ($this->selectedType === 'ai') {
            return $this->selectedAiChat();
        }

        return $this->selectedTicketChat($user);
    }

    /**
     * @return array<string, mixed>
     */
    private function selectedAiChat(): array
    {
        return [
            'type' => 'ai',
            'id' => 1,
            'title' => 'ИИ-консультант',
            'subtitle' => 'Помощник по данным и рабочим действиям текущего рынка',
            'description' => 'Можно спросить про места, арендаторов, договоры, задолженность, обращения, задачи, события и 1С-сверку.',
            'meta' => config('gigachat.auth_key') ? 'GigaChat подключён' : 'ИИ отключён',
            'count' => $this->aiMessageCount(),
        ];
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
            'meta' => 'Обновлено: '.$this->formatDateTime($ticket->updated_at),
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
            return [
                'type' => 'staff',
                'id' => (int) $peer->id,
                'title' => trim((string) $peer->name) !== '' ? trim((string) $peer->name) : 'Сотрудник',
                'subtitle' => trim((string) $peer->email),
                'description' => 'Напишите первое сообщение, чтобы начать переписку.',
                'meta' => 'Новый диалог',
                'count' => 0,
            ];
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
            'meta' => 'Обновлено: '.$this->formatDateTime($latestConversation->last_message_at ?: $latestConversation->updated_at),
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
        if ($this->selectedType === 'ai') {
            return collect($this->aiMessages);
        }

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
                'id' => 'ticket-'.(int) $ticket->id.'-initial',
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
                'id' => 'ticket-comment-'.(int) $comment->id,
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
     * @param  list<array{label:string,url:string}>  $chips
     */
    private function appendAiMessage(string $userName, string $body, bool $isOwn, array $chips = [], array $metadata = []): void
    {
        $user = $this->currentUser();
        $conversation = $user ? $this->aiConversation($user, create: true) : null;

        if ($conversation instanceof AiConversation) {
            $this->touchAiConversationPageContext($user, $conversation);

            $message = AiMessage::query()->create([
                'ai_conversation_id' => (int) $conversation->id,
                'role' => $isOwn ? AiMessage::ROLE_USER : AiMessage::ROLE_ASSISTANT,
                'body' => $body,
                'metadata' => [
                    ...$metadata,
                    'user_name' => $userName,
                    'chips' => $this->normalizeAiChips($chips),
                ],
            ]);

            $conversation->touch();
            $this->aiMessages[] = $this->formatAiMessage($message);

            return;
        }

        $now = now()->timezone(config('app.timezone'));
        $this->aiMessages[] = [
            'id' => 'ai-message-'.count($this->aiMessages).'-'.$now->format('Hisv'),
            'user_name' => $userName,
            'body' => $body,
            'is_own' => $isOwn,
            'created_at' => $now->format('d.m.Y H:i'),
            'date_key' => $now->toDateString(),
            'date_label' => $this->formatDateLabel($now),
            'attachments' => [],
            'chips' => $this->normalizeAiChips($chips),
            'suggestions' => $this->normalizeAiSuggestions((array) ($metadata['suggestions'] ?? [])),
            'pending_action' => $this->normalizeAiPendingAction($metadata['pending_action'] ?? null),
        ];
    }

    private function aiMessageForAction(?AiConversation $conversation, string $messageId, bool $lockForUpdate = false): ?AiMessage
    {
        if (! $conversation instanceof AiConversation) {
            return null;
        }

        if (preg_match('/^ai-message-(\d+)$/', $messageId, $match) !== 1) {
            return null;
        }

        $query = AiMessage::query()
            ->where('ai_conversation_id', (int) $conversation->id)
            ->where('role', AiMessage::ROLE_ASSISTANT)
            ->whereKey((int) $match[1]);

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    /**
     * @return list<array{role:string,content:string}>
     */
    private function aiConversationHistory(): array
    {
        $user = $this->currentUser();
        $conversation = $user ? $this->aiConversation($user, create: true) : null;

        if ($conversation instanceof AiConversation) {
            return $conversation->messages()
                ->whereIn('role', [AiMessage::ROLE_USER, AiMessage::ROLE_ASSISTANT])
                ->latest('created_at')
                ->limit(40)
                ->get()
                ->reverse()
                ->map(static fn (AiMessage $message): array => [
                    'role' => (string) $message->role,
                    'content' => trim((string) $message->body),
                ])
                ->filter(static fn (array $message): bool => $message['content'] !== '')
                ->values()
                ->all();
        }

        return collect($this->aiMessages)
            ->map(static fn (array $message): array => [
                'role' => (bool) ($message['is_own'] ?? false) ? 'user' : 'assistant',
                'content' => trim((string) ($message['body'] ?? '')),
            ])
            ->filter(static fn (array $message): bool => $message['content'] !== '')
            ->values()
            ->all();
    }

    private function loadAiMessages(bool $ensureGreeting = true): void
    {
        $user = $this->currentUser();
        if (! $user) {
            $this->aiMessages = [];

            return;
        }

        $conversation = $this->aiConversation($user, create: true);
        if (! $conversation instanceof AiConversation) {
            return;
        }

        $this->touchAiConversationPageContext($user, $conversation);
        if ($ensureGreeting) {
            $this->ensureAiGreetingMessage($conversation);
        }

        $this->aiMessages = $conversation->messages()
            ->latest('created_at')
            ->limit(120)
            ->get()
            ->reverse()
            ->map(fn (AiMessage $message): array => $this->formatAiMessage($message))
            ->values()
            ->all();
    }

    private function aiMessageCount(): int
    {
        $user = $this->currentUser();
        if (! $user) {
            return count($this->aiMessages);
        }

        $conversation = $this->aiConversation($user, create: false);

        return $conversation instanceof AiConversation
            ? (int) $conversation->messages()->count()
            : count($this->aiMessages);
    }

    private function aiConversation(User $user, bool $create): ?AiConversation
    {
        if (! $this->aiTablesAvailable()) {
            return null;
        }

        $marketId = $this->resolveMarketId($user);
        $marketValue = $marketId > 0 ? $marketId : null;

        $query = AiConversation::query()
            ->where('user_id', (int) $user->id)
            ->where('market_id', $marketValue);

        $conversation = $query
            ->latest('updated_at')
            ->latest('id')
            ->first();

        if (! $conversation && $create) {
            $conversation = AiConversation::query()->create([
                'market_id' => $marketValue,
                'user_id' => (int) $user->id,
                'title' => 'ИИ-консультант',
            ]);
        }

        return $conversation;
    }

    private function ensureAiGreetingMessage(AiConversation $conversation): void
    {
        if ($conversation->messages()->exists()) {
            return;
        }

        $body = self::AI_GREETING_MESSAGE;
        $metadata = [
            'user_name' => 'ИИ-консультант',
            'chips' => [],
            'kind' => 'greeting',
        ];

        $user = $this->currentUser();
        if ($user instanceof User) {
            $marketId = $this->resolveMarketId($user);
            $profileService = app(AiUserProfileService::class);
            $offer = $profileService->lightOnboardingOffer($user, $marketId);

            if (is_array($offer)) {
                $body .= "\n\n".trim((string) ($offer['text'] ?? ''));
                $metadata['suggestions'] = (array) ($offer['suggestions'] ?? []);
                $metadata['light_onboarding_offer'] = [
                    'missing' => (array) ($offer['missing'] ?? []),
                    'offered_at' => now()->toDateTimeString(),
                ];
                $profileService->markLightOnboardingOffered($user, $marketId);
            }
        }

        AiMessage::query()->create([
            'ai_conversation_id' => (int) $conversation->id,
            'role' => AiMessage::ROLE_ASSISTANT,
            'body' => $body,
            'metadata' => $metadata,
        ]);

        $conversation->touch();
    }

    private function ensureAiPageNudgeMessage(): void
    {
        $user = $this->currentUser();
        if (! $user) {
            return;
        }

        $conversation = $this->aiConversation($user, create: true);
        if (! $conversation instanceof AiConversation) {
            return;
        }

        $this->touchAiConversationPageContext($user, $conversation);
        $marketId = $this->resolveMarketId($user);
        $profile = app(AiUserProfileService::class)->syncFromConversation($user, $conversation, $marketId);
        $priorityContext = app(AiPageNudgeContextService::class)->build($user, $marketId, $this->pageContext, $profile);

        $fingerprint = $this->aiPageContextFingerprint();
        $recentDuplicate = $conversation->messages()
            ->where('role', AiMessage::ROLE_ASSISTANT)
            ->where('metadata->kind', 'page_nudge_greeting')
            ->where('metadata->page_fingerprint', $fingerprint)
            ->where('created_at', '>=', now()->subHours(6))
            ->exists();

        if ($recentDuplicate) {
            $this->loadAiMessages(false);

            return;
        }

        AiMessage::query()->create([
            'ai_conversation_id' => (int) $conversation->id,
            'role' => AiMessage::ROLE_ASSISTANT,
            'body' => $this->aiPageNudgeMessageBody($user, $priorityContext),
            'metadata' => [
                'user_name' => 'ИИ-консультант',
                'chips' => [],
                'suggestions' => $this->aiPageNudgeSuggestions($priorityContext),
                'priority_context' => $priorityContext,
                'kind' => 'page_nudge_greeting',
                'page_fingerprint' => $fingerprint,
            ],
        ]);

        $conversation->touch();
        $this->loadAiMessages(false);
    }

    /**
     * @param array<string, mixed> $priorityContext
     */
    private function aiPageNudgeMessageBody(User $user, array $priorityContext = []): string
    {
        $name = $this->userFriendlyName($user);
        $page = $this->aiPageContextPhrase();
        $priorityMessage = trim((string) ($priorityContext['message'] ?? ''));

        if ($priorityMessage !== '') {
            return "{$name}, вижу, вы сейчас {$page}. {$priorityMessage} Что сделать в первую очередь?";
        }

        return "{$name}, вижу, вы сейчас {$page}. Могу проверить данные, найти важное, подготовить ссылку, сообщение, задачу или напоминание. Что нужно сделать?";
    }

    private function aiPageContextPhrase(): string
    {
        $path = trim((string) ($this->pageContext['path'] ?? ''));

        return match (true) {
            str_contains($path, '/admin/tenants/') => 'в карточке арендатора',
            str_contains($path, '/admin/tenants') => 'в разделе арендаторов',
            str_contains($path, '/admin/market-spaces/') => 'на странице места',
            str_contains($path, '/admin/market-spaces') => 'в разделе мест',
            str_contains($path, '/admin/tenant-contracts/') => 'на странице договора',
            str_contains($path, '/admin/tenant-contracts') => 'в разделе договоров',
            str_contains($path, '/admin/requests') => 'в диалогах и обращениях',
            str_contains($path, '/admin/tasks') => 'в задачах',
            str_contains($path, '/admin/calendar') => 'в календаре',
            str_contains($path, '/admin/map') => 'на карте рынка',
            default => 'на этой странице',
        };
    }

    /**
     * @param array<string, mixed> $priorityContext
     * @return list<string>
     */
    private function aiPageNudgeSuggestions(array $priorityContext = []): array
    {
        $path = trim((string) ($this->pageContext['path'] ?? ''));

        $pageSuggestions = match (true) {
            str_contains($path, '/admin/tenants/') => [
                'Проверь долги этого арендатора',
                'Покажи действующие договоры',
                'Подготовь сообщение арендатору',
            ],
            str_contains($path, '/admin/market-spaces/') => [
                'Проверь это место',
                'Покажи арендатора по месту',
                'Создай задачу по этому месту',
            ],
            str_contains($path, '/admin/tenant-contracts') => [
                'Проверь этот договор',
                'Найди связанные начисления',
                'Создай напоминание по договору',
            ],
            str_contains($path, '/admin/requests') => [
                'Помоги ответить в диалоге',
                'Создай задачу из обращения',
                'Найди связанные данные',
            ],
            str_contains($path, '/admin/tasks') => [
                'Помоги уточнить задачу',
                'Создай напоминание',
                'Найди связанные ресурсы',
            ],
            default => [
                'Что важно на этой странице?',
                'Найди связанные данные',
                'Создай задачу по этой странице',
            ],
        };

        $prioritySuggestions = array_values(array_filter(array_map(
            static fn (mixed $suggestion): string => trim((string) $suggestion),
            (array) ($priorityContext['suggestions'] ?? []),
        )));

        return collect([...$prioritySuggestions, ...$pageSuggestions])
            ->filter()
            ->unique()
            ->take(3)
            ->values()
            ->all();
    }

    private function aiPageContextFingerprint(): string
    {
        $path = trim((string) ($this->pageContext['path'] ?? ''));
        $heading = trim((string) ($this->pageContext['heading'] ?? ''));
        $title = trim((string) ($this->pageContext['title'] ?? ''));

        return sha1($path.'|'.$heading.'|'.$title);
    }

    private function userFriendlyName(User $user): string
    {
        $name = trim((string) $user->name);
        if ($name === '') {
            return 'Коллега';
        }

        $lowerName = mb_strtolower($name);
        $roleLikeNames = ['admin', 'administrator', 'super', 'super admin', 'root'];
        if (in_array($lowerName, $roleLikeNames, true) || str_contains($lowerName, 'admin')) {
            return 'Коллега';
        }

        $parts = preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($parts === []) {
            return 'Коллега';
        }

        $firstPart = mb_strtolower((string) $parts[0]);
        if (in_array($firstPart, $roleLikeNames, true)) {
            return 'Коллега';
        }

        if (count($parts) >= 3 && preg_match('/[А-Яа-яЁё]/u', $name) === 1) {
            return $parts[1];
        }

        return $parts[0];
    }

    private function touchAiConversationPageContext(User $user, ?AiConversation $conversation = null): void
    {
        if (! $this->aiTablesAvailable()) {
            return;
        }

        $conversation ??= $this->aiConversation($user, create: false);
        if (! $conversation instanceof AiConversation) {
            return;
        }

        $url = trim((string) ($this->pageContext['url'] ?? ''));
        $label = trim((string) ($this->pageContext['heading'] ?? ''));
        if ($label === '') {
            $label = trim((string) ($this->pageContext['title'] ?? ''));
        }

        if ($url === '' && $label === '') {
            return;
        }

        $conversation->forceFill([
            'context_page_url' => $url !== '' ? $url : $conversation->context_page_url,
            'context_page_label' => $label !== '' ? $label : $conversation->context_page_label,
        ])->save();
    }

    private function formatAiMessage(AiMessage $message): array
    {
        $metadata = (array) ($message->metadata ?? []);
        $createdAt = $message->created_at?->timezone(config('app.timezone'));
        $isOwn = $message->role === AiMessage::ROLE_USER;
        $suggestions = $this->normalizeAiSuggestions((array) ($metadata['suggestions'] ?? []));

        if (! $isOwn && $this->aiMessageTopicRejected($metadata)) {
            $suggestions = [];
        }

        return [
            'id' => 'ai-message-'.(int) $message->id,
            'user_name' => trim((string) ($metadata['user_name'] ?? '')) ?: ($isOwn ? 'Вы' : 'ИИ-консультант'),
            'body' => trim((string) $message->body),
            'is_own' => $isOwn,
            'created_at' => $this->formatDateTime($createdAt),
            'date_key' => optional($createdAt)->toDateString(),
            'date_label' => $this->formatDateLabel($createdAt),
            'attachments' => [],
            'chips' => $this->normalizeAiChips((array) ($metadata['chips'] ?? [])),
            'suggestions' => $suggestions,
            'pending_action' => $this->normalizeAiPendingAction($metadata['pending_action'] ?? null),
        ];
    }

    /**
     * @param  array<string,mixed>  $metadata
     */
    private function aiMessageTopicRejected(array $metadata): bool
    {
        $topic = trim((string) data_get($metadata, 'priority_context.topic', ''));
        if ($topic === '') {
            return false;
        }

        $user = $this->currentUser();
        if (! $user) {
            return false;
        }

        return in_array($topic, app(AiUserProfileService::class)->rejectedTopicKeys($user), true);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function normalizeAiPendingAction(mixed $pendingAction): ?array
    {
        if (! is_array($pendingAction)) {
            return null;
        }

        $status = strtolower(trim((string) ($pendingAction['status'] ?? 'pending')));
        if (! in_array($status, ['pending', 'running', 'confirmed', 'cancelled', 'failed'], true)) {
            $status = 'pending';
        }

        $summary = collect((array) ($pendingAction['summary'] ?? []))
            ->filter(static fn (mixed $row): bool => is_array($row))
            ->map(static fn (array $row): array => [
                'label' => Str::limit(trim((string) ($row['label'] ?? '')), 80, ''),
                'value' => Str::limit(trim((string) ($row['value'] ?? '')), 280, ''),
            ])
            ->filter(static fn (array $row): bool => $row['label'] !== '' && $row['value'] !== '')
            ->values()
            ->all();

        return [
            'status' => $status,
            'status_label' => $this->aiPendingActionStatusLabel($status),
            'title' => Str::limit(trim((string) ($pendingAction['title'] ?? 'Действие агента')), 120, ''),
            'summary' => $summary,
            'confirm_label' => Str::limit(trim((string) ($pendingAction['confirm_label'] ?? 'Подтвердить')), 80, ''),
            'cancel_label' => Str::limit(trim((string) ($pendingAction['cancel_label'] ?? 'Отменить')), 80, ''),
            'result_message' => Str::limit(trim((string) ($pendingAction['result_message'] ?? '')), 220, ''),
        ];
    }

    private function aiPendingActionStatusLabel(string $status): string
    {
        return match ($status) {
            'running' => 'Выполняется',
            'confirmed' => 'Выполнено',
            'cancelled' => 'Отменено',
            'failed' => 'Не выполнено',
            default => 'Ожидает подтверждения',
        };
    }

    /**
     * @param  array<int, mixed>  $chips
     * @return list<array{label:string,url:string}>
     */
    private function normalizeAiChips(array $chips): array
    {
        return collect($chips)
            ->filter(static fn (mixed $chip): bool => is_array($chip))
            ->map(static fn (array $chip): array => [
                'label' => Str::limit(trim((string) ($chip['label'] ?? '')), 120, ''),
                'url' => trim((string) ($chip['url'] ?? '')),
            ])
            ->filter(static fn (array $chip): bool => $chip['label'] !== '' && $chip['url'] !== '')
            ->values()
            ->all();
    }

    /**
     * @param  array<int, mixed>  $suggestions
     * @return list<string>
     */
    private function normalizeAiSuggestions(array $suggestions): array
    {
        return collect($suggestions)
            ->map(static fn (mixed $suggestion): string => Str::limit(trim((string) $suggestion), 120, ''))
            ->filter(static fn (string $suggestion): bool => $suggestion !== '')
            ->unique()
            ->values()
            ->take(4)
            ->all();
    }

    private function aiTablesAvailable(): bool
    {
        return Schema::hasTable('ai_conversations')
            && Schema::hasTable('ai_messages');
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
                'id' => 'staff-message-'.(int) $message->id,
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

        if (! $this->isAllowedInternalStaffPeer($peer)) {
            return false;
        }

        if ($this->isSuperAdmin($user)) {
            return true;
        }

        return (int) ($user->market_id ?? 0) > 0
            && (
                (int) ($user->market_id ?? 0) === (int) ($peer->market_id ?? 0)
                || $this->isSuperAdmin($peer)
            );
    }

    private function isAllowedInternalStaffPeer(User $peer): bool
    {
        if ((int) ($peer->tenant_id ?? 0) > 0) {
            return false;
        }

        if (! method_exists($peer, 'hasAnyRole')) {
            return true;
        }

        return ! $peer->hasAnyRole(['merchant', 'merchant-user', 'tenant', 'buyer', 'user']);
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

        LooseSearch::applySearch($query, $search, [
            static function (Builder $searchQuery, array $termPatterns): void {
                LooseSearch::orWhereMatchesColumns($searchQuery, ['subject', 'description'], $termPatterns);
                $searchQuery->orWhereHas('tenant', function (Builder $tenantQuery) use ($termPatterns): void {
                    LooseSearch::orWhereMatchesColumns($tenantQuery, ['name', 'short_name'], $termPatterns);
                });
            },
        ]);
    }

    private function scopeStaffSearch(Builder $query): void
    {
        $search = trim($this->search);
        if ($search === '') {
            return;
        }

        LooseSearch::applySearch($query, $search, [
            function (Builder $searchQuery, array $termPatterns): void {
                LooseSearch::orWhereMatchesColumn($searchQuery, 'subject', $termPatterns);
                $searchQuery->orWhereHas('starter', fn (Builder $userQuery): Builder => $this->scopeUserSearch($userQuery, $termPatterns));
                $searchQuery->orWhereHas('recipient', fn (Builder $userQuery): Builder => $this->scopeUserSearch($userQuery, $termPatterns));
            },
        ]);
    }

    /**
     * @param  string|array{normalized:list<string>,compact:list<string>}  $search
     */
    private function scopeUserSearch(Builder $query, string|array $search): Builder
    {
        if (is_string($search)) {
            return LooseSearch::applySearchToColumns($query, $search, ['name', 'email']);
        }

        LooseSearch::orWhereMatchesColumns($query, ['name', 'email'], $search);

        return $query;
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

    private function unreadIncomingMessagesCount(): int
    {
        $user = $this->currentUser();
        if (! $user) {
            return 0;
        }

        return $this->unreadStaffMessagesCount()
            + $this->unreadTicketMessageNotificationsCount($user);
    }

    private function unreadTicketMessageNotificationsCount(User $user, ?int $ticketId = null): int
    {
        if (! Schema::hasTable('notifications')) {
            return 0;
        }

        return (int) $this->ticketMessageNotificationsQuery($user, $ticketId)->count();
    }

    private function markTicketMessageNotificationsRead(User $user, int $ticketId): void
    {
        if (! Schema::hasTable('notifications')) {
            return;
        }

        $this->ticketMessageNotificationsQuery($user, $ticketId)
            ->update(['read_at' => now()]);
    }

    private function ticketMessageNotificationsQuery(User $user, ?int $ticketId = null)
    {
        return DB::table('notifications')
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', (int) $user->id)
            ->whereNull('read_at')
            ->where('type', TicketChatNotification::class)
            ->where('data->event_type', TicketChatNotification::EVENT_MESSAGE_CREATED)
            ->when($ticketId !== null, function ($query) use ($ticketId) {
                return $query->where('data->ticket_id', $ticketId);
            });
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
