<?php

namespace App\Filament\Resources\TenantResource\RelationManagers;

use App\Models\TenantRequest;
use App\Models\Ticket;
use App\Models\TicketComment;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema as DbSchema;

class RequestsRelationManager extends RelationManager
{
    protected static string $relationship = 'requests';

    protected static ?string $title = 'Обращения';

    protected static ?string $recordTitleAttribute = 'subject';

    protected static ?bool $hasTenantRequestTicketIdColumn = null;

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Components\TextInput::make('subject')
                ->label('Тема')
                ->required()
                ->maxLength(255),
            Forms\Components\Textarea::make('description')
                ->label('Описание обращения')
                ->required(),
            Forms\Components\Select::make('category')
                ->label('Категория')
                ->options([
                    'maintenance' => 'Обслуживание и ремонт',
                    'payment' => 'Оплата и расчеты',
                    'documents' => 'Документы и отчетность',
                    'technical' => 'Технические вопросы',
                    'other' => 'Другое',
                ])
                ->default('other'),
            Forms\Components\Select::make('status')
                ->label('Статус')
                ->options([
                    'new' => 'Новое',
                    'in_progress' => 'В работе',
                    'resolved' => 'Решено',
                    'closed' => 'Закрыто',
                ])
                ->default('new'),
        ]);
    }

    public function table(Table $table): Table
    {
        $headerActions = [];

        if (class_exists(\Filament\Actions\Action::class)) {
            $action = \Filament\Actions\Action::make('quick_chat')
                ->label('Написать арендатору')
                ->icon('heroicon-o-paper-airplane')
                ->color('primary')
                ->modalHeading('Чат с арендатором')
                ->modalSubmitActionLabel('Отправить')
                ->form([
                    Forms\Components\Textarea::make('body')
                        ->label('Сообщение')
                        ->rows(4)
                        ->required()
                        ->placeholder('Напишите сообщение арендатору...'),
                ])
                ->action(fn (array $data) => $this->sendOwnerChatMessage($data));

            if (method_exists($action, 'slideOver')) {
                $action->slideOver();
            }
            if (method_exists($action, 'modalContent')) {
                $action->modalContent(fn () => view('filament.tenants.request-chat', $this->buildOwnerChatViewData()));
            }

            $headerActions[] = $action;
        } elseif (class_exists(\Filament\Tables\Actions\Action::class)) {
            $action = \Filament\Tables\Actions\Action::make('quick_chat')
                ->label('Написать арендатору')
                ->icon('heroicon-o-paper-airplane')
                ->color('primary')
                ->modalHeading('Чат с арендатором')
                ->modalSubmitActionLabel('Отправить')
                ->form([
                    Forms\Components\Textarea::make('body')
                        ->label('Сообщение')
                        ->rows(4)
                        ->required()
                        ->placeholder('Напишите сообщение арендатору...'),
                ])
                ->action(fn (array $data) => $this->sendOwnerChatMessage($data));

            if (method_exists($action, 'slideOver')) {
                $action->slideOver();
            }
            if (method_exists($action, 'modalContent')) {
                $action->modalContent(fn () => view('filament.tenants.request-chat', $this->buildOwnerChatViewData()));
            }

            $headerActions[] = $action;
        }

        $rowActions = [];
        if (class_exists(\Filament\Tables\Actions\Action::class)) {
            $rowActions[] = $this->buildTableChatAction();
        } elseif (class_exists(\Filament\Actions\Action::class)) {
            $rowActions[] = $this->buildGenericChatAction();
        }

        $table = $table
            ->columns([
                TextColumn::make('subject')
                    ->label('Тема')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('category')
                    ->label('Категория')
                    ->formatStateUsing(fn (?string $state) => match ($state) {
                        'maintenance' => 'Обслуживание и ремонт',
                        'payment' => 'Оплата и расчеты',
                        'documents' => 'Документы и отчетность',
                        'technical' => 'Технические вопросы',
                        'other' => 'Другое',
                        default => $state,
                    }),
                TextColumn::make('status')
                    ->label('Статус')
                    ->formatStateUsing(fn (?string $state) => match ($state) {
                        'new' => 'Новое',
                        'in_progress' => 'В работе',
                        'resolved' => 'Решено',
                        'closed' => 'Закрыто',
                        default => $state,
                    }),
                TextColumn::make('ticket_id')
                    ->label('Чат')
                    ->visible(fn (): bool => static::hasTenantRequestTicketIdColumn())
                    ->formatStateUsing(fn ($state): string => $state ? ('#' . (int) $state) : 'не начат'),
                TextColumn::make('created_at')
                    ->label('Создано')
                    ->dateTime()
                    ->sortable(),
            ])
            ->headerActions($headerActions)
            ->emptyStateHeading('Обращений пока нет')
            ->emptyStateDescription('Нажмите "Написать арендатору", чтобы начать диалог.')
            ->emptyStateActions([]);

        if (! empty($rowActions)) {
            $table = $table->actions($rowActions);
        }

        return $table;
    }

    public function getTableQuery(): Builder
    {
        $user = Filament::auth()->user();

        /** @var Builder $query */
        $query = $this->getRelationship()->getQuery()->with('createdBy');
        if (static::hasTenantRequestTicketIdColumn()) {
            $query->with('ticket');
        }

        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return $query;
        }

        if ($user->market_id) {
            return $query->where('market_id', $user->market_id);
        }

        return $query->whereRaw('1 = 0');
    }

    protected function buildTableChatAction(): \Filament\Tables\Actions\Action
    {
        $action = \Filament\Tables\Actions\Action::make('chat')
            ->label('Чат')
            ->icon('heroicon-o-chat-bubble-left-right')
            ->modalHeading('Переписка')
            ->modalSubmitActionLabel('Отправить')
            ->form([
                Forms\Components\Textarea::make('body')
                    ->label('Сообщение')
                    ->rows(4)
                    ->placeholder('Напишите сообщение арендатору...')
                    ->nullable(),
            ])
            ->action(fn ($record, array $data) => $this->sendChatMessage($record, $data));

        if (method_exists($action, 'slideOver')) {
            $action->slideOver();
        }
        if (method_exists($action, 'modalContent')) {
            $action->modalContent(fn ($record) => $record instanceof TenantRequest
                ? view('filament.tenants.request-chat', $this->buildChatViewData($record))
                : null
            );
        }

        return $action;
    }

    protected function buildGenericChatAction(): \Filament\Actions\Action
    {
        $action = \Filament\Actions\Action::make('chat')
            ->label('Чат')
            ->icon('heroicon-o-chat-bubble-left-right')
            ->modalHeading('Переписка')
            ->modalSubmitActionLabel('Отправить')
            ->form([
                Forms\Components\Textarea::make('body')
                    ->label('Сообщение')
                    ->rows(4)
                    ->placeholder('Напишите сообщение арендатору...')
                    ->nullable(),
            ])
            ->action(fn ($record, array $data) => $this->sendChatMessage($record, $data));

        if (method_exists($action, 'slideOver')) {
            $action->slideOver();
        }
        if (method_exists($action, 'modalContent')) {
            $action->modalContent(fn ($record) => $record instanceof TenantRequest
                ? view('filament.tenants.request-chat', $this->buildChatViewData($record))
                : null
            );
        }

        return $action;
    }

    protected function buildOwnerChatViewData(): array
    {
        $request = $this->findOwnerConversationRequest(forSend: false);
        if (! $request) {
            return [
                'request' => null,
                'ticket' => null,
                'messages' => [],
            ];
        }

        return $this->buildChatViewData($request);
    }

    protected function buildChatViewData(TenantRequest $request): array
    {
        $request->loadMissing('createdBy');

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

    protected function buildChatMessages(TenantRequest $request, ?Ticket $ticket): array
    {
        $messages = [];
        $authUserId = (int) (Filament::auth()->id() ?? 0);
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

    protected function sendOwnerChatMessage(array $data): void
    {
        $body = trim((string) ($data['body'] ?? ''));
        if ($body === '') {
            Notification::make()
                ->warning()
                ->title('Введите текст сообщения')
                ->send();

            return;
        }

        $request = $this->findOwnerConversationRequest(forSend: true);
        if (! $request) {
            $owner = $this->getOwnerRecord();
            $authId = Filament::auth()->id();

            if (! $owner) {
                Notification::make()
                    ->danger()
                    ->title('Не удалось определить арендатора')
                    ->send();

                return;
            }

            $subject = static::resolveSubject('', $body);

            $ticket = Ticket::query()->create([
                'market_id' => (int) $owner->market_id,
                'tenant_id' => (int) $owner->id,
                'subject' => $subject,
                'description' => $body,
                'category' => 'other',
                'priority' => 'normal',
                'status' => 'new',
            ]);

            TenantRequest::query()->create([
                'market_id' => (int) $owner->market_id,
                'tenant_id' => (int) $owner->id,
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

        $this->sendChatMessage($request, ['body' => $body]);
    }

    protected function sendChatMessage(mixed $record, array $data): void
    {
        if (! $record instanceof TenantRequest) {
            return;
        }

        $body = trim((string) ($data['body'] ?? ''));
        if ($body === '') {
            Notification::make()
                ->warning()
                ->title('Введите текст сообщения')
                ->send();

            return;
        }

        $userId = (int) (Filament::auth()->id() ?? 0);
        if ($userId <= 0) {
            Notification::make()
                ->danger()
                ->title('Не удалось определить пользователя')
                ->send();

            return;
        }

        $ticket = $this->ensureTicketForRequest($record);
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

        if ((string) $record->status === 'new') {
            $record->status = 'in_progress';
            $record->save();
        }

        Notification::make()
            ->success()
            ->title('Сообщение отправлено')
            ->send();
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
            'category' => static::mapRequestCategoryToTicket($request->category),
            'priority' => 'normal',
            'status' => static::mapRequestStatusToTicket($request->status),
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

    protected function findOwnerConversationRequest(bool $forSend): ?TenantRequest
    {
        $owner = $this->getOwnerRecord();
        if (! $owner) {
            return null;
        }

        $base = TenantRequest::query()
            ->where('market_id', (int) $owner->market_id)
            ->where('tenant_id', (int) $owner->id);

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

    protected static function mapRequestCategoryToTicket(?string $category): string
    {
        return match ($category) {
            'maintenance' => 'repair',
            'payment' => 'payment',
            default => 'other',
        };
    }

    protected static function mapRequestStatusToTicket(?string $status): string
    {
        return match ($status) {
            'in_progress' => 'in_progress',
            'resolved' => 'resolved',
            'closed' => 'closed',
            default => 'new',
        };
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
        if (static::$hasTenantRequestTicketIdColumn !== null) {
            return static::$hasTenantRequestTicketIdColumn;
        }

        try {
            static::$hasTenantRequestTicketIdColumn = DbSchema::hasColumn('tenant_requests', 'ticket_id');
        } catch (\Throwable) {
            static::$hasTenantRequestTicketIdColumn = false;
        }

        return static::$hasTenantRequestTicketIdColumn;
    }
}

