<?php

namespace App\Filament\Resources\TenantResource\Pages;

use App\Filament\Resources\TenantResource;
use App\Filament\Resources\TenantResource\Pages\Concerns\InteractsWithTenantCabinet;
use App\Models\TenantRequest;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\User;
use App\Services\Cabinet\TenantImpersonationService;
use Filament\Actions;
use Filament\Notifications\Notification;
use App\Filament\Resources\Pages\BaseEditRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Schema as DbSchema;

class EditTenant extends BaseEditRecord
{
    use InteractsWithTenantCabinet;

    protected static string $resource = TenantResource::class;

    public function getTitle(): string|Htmlable
    {
        $name = trim((string) ($this->record?->name ?? ''));

        return $name !== '' ? $name : 'Арендатор';
    }

    public function getBreadcrumb(): string
    {
        return 'Арендатор';
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return array_merge($data, $this->buildCabinetFormData($this->record));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->cabinetPayload = $this->pullCabinetPayloadFromForm($data);
        $this->validateCabinetPayload($this->cabinetPayload, $this->record);

        return $data;
    }

    protected function afterSave(): void
    {
        $this->syncCabinetPayload($this->record, $this->cabinetPayload);
    }

    protected function getHeaderActions(): array
    {
        $chatAction = Actions\Action::make('write_to_tenant')
            ->label('Написать арендатору')
            ->icon('heroicon-o-paper-airplane')
            ->color('primary')
            ->size('lg')
            ->outlined()
            ->extraAttributes([
                'class' => 'tenant-card-action tenant-card-action--secondary',
                'data-subtitle' => 'Сообщение арендатору',
            ])
            ->modalHeading('Чат с арендатором')
            ->modalSubmitActionLabel('Отправить')
            ->form([
                \Filament\Forms\Components\Textarea::make('body')
                    ->label('Сообщение')
                    ->required()
                    ->rows(4)
                    ->placeholder('Напишите сообщение арендатору...'),
            ])
            ->action(fn (array $data) => $this->sendHeaderChatMessage($data));

        if (method_exists($chatAction, 'slideOver')) {
            $chatAction->slideOver();
        }
        if (method_exists($chatAction, 'modalContent')) {
            $chatAction->modalContent(fn () => view('filament.tenants.request-chat', $this->buildHeaderChatViewData()));
        }

        return [
            Actions\Action::make('cabinet_impersonate')
                ->label('Войти в кабинет')
                ->icon('heroicon-o-arrow-right-on-rectangle')
                ->color('gray')
                ->size('lg')
                ->outlined()
                ->extraAttributes([
                    'class' => 'tenant-card-action tenant-card-action--primary',
                    'data-subtitle' => 'Откроется кабинет арендатора',
                ])
                ->requiresConfirmation()
                ->modalHeading('Войти в кабинет арендатора?')
                ->modalDescription('Откроется кабинет арендатора в текущей сессии. Возврат в админку доступен из шапки кабинета.')
                ->visible(fn (): bool => $this->canImpersonateCabinet())
                ->action(function () {
                    $service = app(TenantImpersonationService::class);
                    $impersonator = \Filament\Facades\Filament::auth()->user();

                    if (! $impersonator instanceof User) {
                        abort(403);
                    }

                    if (! $service->canIssue($impersonator, $this->record)) {
                        $reason = $service->isCrossMarketDenied($impersonator, $this->record)
                            ? 'cross_market_denied'
                            : 'forbidden_role';
                        $service->recordDenied($impersonator, $this->record, request(), $reason);

                        Notification::make()
                            ->danger()
                            ->title('Недостаточно прав для входа в кабинет арендатора')
                            ->send();
                        abort(403);
                    }

                    $cabinetUser = $service->resolveCabinetUser($this->record);
                    if (! $cabinetUser) {
                        $service->recordDenied($impersonator, $this->record, request(), 'cabinet_user_missing');

                        Notification::make()
                            ->warning()
                            ->title('Не найден пользователь кабинета арендатора')
                            ->body('Создайте логин на вкладке «Кабинет», затем повторите вход.')
                            ->send();

                        return redirect()->to(url('/admin/tenants/' . (int) $this->record->id . '/edit?tab=kabinet::data::tab'));
                    }

                    $url = $service->issue($impersonator, $this->record, $cabinetUser, request());

                    return redirect()->to($url);
                }),
            $chatAction,
            Actions\DeleteAction::make()
                ->label('Удалить арендатора')
                ->size('lg')
                ->outlined()
                ->extraAttributes([
                    'class' => 'tenant-card-action tenant-card-action--danger',
                    'data-subtitle' => 'Удалит арендатора без возврата',
                ]),
        ];
    }

    public function getPageClasses(): array
    {
        return [
            ...parent::getPageClasses(),
            'fi-resource-tenants-edit-page',
        ];
    }

    protected function canImpersonateCabinet(): bool
    {
        $user = \Filament\Facades\Filament::auth()->user();

        if (! $user instanceof User) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        if (! $user->hasRole('market-admin')) {
            return false;
        }

        return (int) ($user->market_id ?? 0) === (int) ($this->record->market_id ?? 0);
    }

    protected function buildHeaderChatViewData(): array
    {
        $request = $this->findConversationRequest(forSend: false);
        if (! $request) {
            return [
                'request' => null,
                'ticket' => null,
                'messages' => [],
            ];
        }

        $ticket = null;
        if (static::hasTenantRequestTicketIdColumn()) {
            $ticketId = (int) ($request->ticket_id ?? 0);
            if ($ticketId > 0) {
                $ticket = Ticket::query()->find($ticketId);
            }
        } else {
            $ticket = $this->findFallbackTicketForRequest($request);
        }

        return [
            'request' => $request,
            'ticket' => $ticket,
            'messages' => $this->buildChatMessages($request, $ticket),
        ];
    }

    protected function sendHeaderChatMessage(array $data): void
    {
        $body = trim((string) ($data['body'] ?? ''));
        if ($body === '') {
            Notification::make()
                ->warning()
                ->title('Введите текст сообщения')
                ->send();

            return;
        }

        $request = $this->findConversationRequest(forSend: true);
        if (! $request) {
            $authId = \Filament\Facades\Filament::auth()->id();
            $subject = static::resolveSubject('', $body);

            $ticket = Ticket::query()->create([
                'market_id' => (int) $this->record->market_id,
                'tenant_id' => (int) $this->record->id,
                'subject' => $subject,
                'description' => $body,
                'category' => 'other',
                'priority' => 'normal',
                'status' => 'new',
            ]);

            TenantRequest::query()->create([
                'market_id' => (int) $this->record->market_id,
                'tenant_id' => (int) $this->record->id,
                'subject' => $subject,
                'description' => $body,
                'category' => 'other',
                'priority' => 'normal',
                'status' => 'new',
                'created_by_user_id' => $authId ? (int) $authId : null,
                'is_active' => true,
            ] + (static::hasTenantRequestTicketIdColumn() ? ['ticket_id' => (int) $ticket->id] : []));

            Notification::make()
                ->success()
                ->title('Сообщение отправлено')
                ->send();

            return;
        }

        $this->sendMessageToRequest($request, $body);
    }

    protected function sendMessageToRequest(TenantRequest $request, string $body): void
    {
        $userId = (int) (\Filament\Facades\Filament::auth()->id() ?? 0);
        if ($userId <= 0) {
            Notification::make()
                ->danger()
                ->title('Не удалось определить пользователя')
                ->send();

            return;
        }

        $ticket = $this->ensureTicketForRequest($request);
        if (! $ticket) {
            Notification::make()
                ->danger()
                ->title('Не удалось создать чат')
                ->send();

            return;
        }

        TicketComment::query()->create([
            'ticket_id' => (int) $ticket->id,
            'user_id' => $userId,
            'body' => $body,
        ]);

        if ((string) $ticket->status === 'new') {
            $ticket->status = 'in_progress';
            $ticket->save();
        }

        if ((string) $request->status === 'new') {
            $request->status = 'in_progress';
            $request->save();
        }

        Notification::make()
            ->success()
            ->title('Сообщение отправлено')
            ->send();
    }

    protected function findConversationRequest(bool $forSend): ?TenantRequest
    {
        $base = TenantRequest::query()
            ->where('market_id', (int) $this->record->market_id)
            ->where('tenant_id', (int) $this->record->id);

        $open = (clone $base)
            ->whereIn('status', ['new', 'in_progress'])
            ->orderByDesc('id')
            ->first();

        if ($open) {
            return $open;
        }

        if ($forSend) {
            return null;
        }

        return (clone $base)
            ->orderByDesc('id')
            ->first();
    }

    protected function buildChatMessages(TenantRequest $request, ?Ticket $ticket): array
    {
        $messages = [];
        $authUserId = (int) (\Filament\Facades\Filament::auth()->id() ?? 0);
        $tenantId = (int) ($request->tenant_id ?? 0);

        $initialBody = trim((string) ($request->description ?? ''));
        if ($initialBody !== '') {
            $initialAuthorId = (int) ($request->created_by_user_id ?? 0);
            $initialAuthorName = trim((string) ($request->createdBy?->name ?? ''));
            if ($initialAuthorName === '') {
                $initialAuthorName = $initialAuthorId > 0 ? ('Пользователь #' . $initialAuthorId) : 'Система';
            }

            $messages[] = [
                'author' => $initialAuthorName,
                'time' => $request->created_at?->format('d.m.Y H:i') ?? '—',
                'body' => $initialBody,
                'is_mine' => $authUserId > 0 && $initialAuthorId > 0 && $initialAuthorId === $authUserId,
                'is_tenant_side' => false,
            ];
        }

        if (! $ticket) {
            return $messages;
        }

        $comments = TicketComment::query()
            ->with('user:id,name,tenant_id')
            ->where('ticket_id', (int) $ticket->id)
            ->orderBy('created_at')
            ->limit(300)
            ->get();

        foreach ($comments as $comment) {
            $authorId = (int) ($comment->user_id ?? 0);
            $authorName = trim((string) ($comment->user?->name ?? ''));
            if ($authorName === '') {
                $authorName = $authorId > 0 ? ('Пользователь #' . $authorId) : 'Пользователь';
            }

            $messages[] = [
                'author' => $authorName,
                'time' => $comment->created_at?->format('d.m.Y H:i') ?? '—',
                'body' => (string) ($comment->body ?? ''),
                'is_mine' => $authUserId > 0 && $authorId === $authUserId,
                'is_tenant_side' => (int) ($comment->user?->tenant_id ?? 0) === $tenantId,
            ];
        }

        return $messages;
    }

    protected function ensureTicketForRequest(TenantRequest $request): ?Ticket
    {
        if (static::hasTenantRequestTicketIdColumn()) {
            $ticketId = (int) ($request->ticket_id ?? 0);
            if ($ticketId > 0) {
                $ticket = Ticket::query()->find($ticketId);
                if ($ticket) {
                    return $ticket;
                }
            }
        } else {
            $existing = $this->findFallbackTicketForRequest($request);
            if ($existing) {
                return $existing;
            }
        }

        $ticket = Ticket::query()->create([
            'market_id' => (int) $request->market_id,
            'tenant_id' => (int) $request->tenant_id,
            'subject' => (string) $request->subject,
            'description' => (string) $request->description,
            'category' => match ((string) $request->category) {
                'maintenance' => 'repair',
                'payment' => 'payment',
                default => 'other',
            },
            'priority' => 'normal',
            'status' => match ((string) $request->status) {
                'in_progress' => 'in_progress',
                'resolved' => 'resolved',
                'closed' => 'closed',
                default => 'new',
            },
        ]);

        if (static::hasTenantRequestTicketIdColumn()) {
            $request->ticket_id = (int) $ticket->id;
            $request->save();
        }

        return $ticket;
    }

    protected function findFallbackTicketForRequest(TenantRequest $request): ?Ticket
    {
        return Ticket::query()
            ->where('market_id', (int) $request->market_id)
            ->where('tenant_id', (int) $request->tenant_id)
            ->where('subject', (string) $request->subject)
            ->where('description', (string) $request->description)
            ->orderByDesc('id')
            ->first();
    }

    protected static function resolveSubject(string $subject, string $description): string
    {
        $subject = trim($subject);
        if ($subject !== '') {
            return mb_substr($subject, 0, 255, 'UTF-8');
        }

        $description = trim(preg_replace('/\s+/u', ' ', $description) ?? '');
        if ($description === '') {
            return 'Обращение';
        }

        return mb_substr($description, 0, 255, 'UTF-8');
    }

    protected static function hasTenantRequestTicketIdColumn(): bool
    {
        try {
            return DbSchema::hasColumn('tenant_requests', 'ticket_id');
        } catch (\Throwable) {
            return false;
        }
    }
}
