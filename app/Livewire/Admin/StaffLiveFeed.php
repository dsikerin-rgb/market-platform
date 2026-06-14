<?php

namespace App\Livewire\Admin;

use App\Models\StaffFeedPost;
use App\Models\StaffConversationMessage;
use App\Models\User;
use App\Support\MarketplaceMediaStorage;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

class StaffLiveFeed extends Component
{
    use WithFileUploads;

    public string $body = '';

    /**
     * @var array<int, TemporaryUploadedFile>
     */
    public array $attachments = [];

    /**
     * @var array<int, string>
     */
    public array $commentBodies = [];

    public function createPost(): void
    {
        if (! Schema::hasTable('staff_feed_posts')) {
            Notification::make()
                ->title('Лента еще не готова')
                ->body('Дождитесь завершения миграций базы данных.')
                ->warning()
                ->send();

            return;
        }

        $this->validate([
            'body' => ['nullable', 'string', 'max:5000'],
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => ['file', 'max:20480'],
        ]);

        $body = trim($this->body);
        $files = array_values(array_filter(
            $this->attachments,
            static fn ($file): bool => $file instanceof TemporaryUploadedFile,
        ));

        if ($body === '' && $files === []) {
            $this->addError('body', 'Напишите сообщение или прикрепите файл.');

            return;
        }

        $user = Filament::auth()->user();
        if (! $user) {
            return;
        }

        $marketId = $this->resolveMarketId();
        if (! $this->isSuperAdmin($user) && $marketId <= 0) {
            $this->addError('body', 'Для публикации нужен выбранный рынок.');

            return;
        }

        $storedAttachments = [];
        foreach ($files as $file) {
            $mimeType = (string) ($file->getMimeType() ?: 'application/octet-stream');
            $path = MarketplaceMediaStorage::store($file, 'staff-feed');

            $storedAttachments[] = [
                'path' => $path,
                'name' => (string) $file->getClientOriginalName(),
                'mime' => $mimeType,
                'size' => (int) ($file->getSize() ?: 0),
                'is_image' => str_starts_with($mimeType, 'image/'),
            ];
        }

        StaffFeedPost::query()->create([
            'market_id' => $marketId > 0 ? $marketId : null,
            'author_user_id' => (int) $user->id,
            'type' => 'message',
            'body' => $body,
            'meta' => [
                'attachments' => $storedAttachments,
            ],
        ]);

        $this->reset(['body', 'attachments']);

        Notification::make()
            ->title('Сообщение опубликовано')
            ->success()
            ->send();
    }

    public function createComment(int $postId): void
    {
        if (! Schema::hasTable('staff_feed_comments')) {
            Notification::make()
                ->title('Комментарии еще не готовы')
                ->body('Дождитесь завершения миграций базы данных.')
                ->warning()
                ->send();

            return;
        }

        $field = 'commentBodies.' . $postId;

        $this->validate([
            $field => ['required', 'string', 'max:2000'],
        ], [], [
            $field => 'комментарий',
        ]);

        $body = trim((string) ($this->commentBodies[$postId] ?? ''));
        if ($body === '') {
            $this->addError($field, 'Напишите комментарий.');

            return;
        }

        $user = Filament::auth()->user();
        if (! $user) {
            return;
        }

        $post = $this->visiblePostsQuery()->whereKey($postId)->first();
        if (! $post) {
            Notification::make()
                ->title('Сообщение не найдено')
                ->warning()
                ->send();

            return;
        }

        $post->comments()->create([
            'author_user_id' => (int) $user->id,
            'body' => $body,
        ]);

        unset($this->commentBodies[$postId]);

        Notification::make()
            ->title('Комментарий добавлен')
            ->success()
            ->send();
    }

    public function render(): View
    {
        return view('livewire.admin.staff-live-feed', [
            'posts' => $this->posts(),
            'commentsReady' => Schema::hasTable('staff_feed_comments'),
            'unreadSummary' => $this->unreadStaffMessageSummary(),
        ]);
    }

    private function posts(): Collection
    {
        if (! Schema::hasTable('staff_feed_posts')) {
            return collect();
        }

        $userColumns = $this->staffUserColumns();

        $query = $this->visiblePostsQuery()
            ->with(['author' => fn ($author) => $author->select($userColumns)]);

        if (Schema::hasTable('staff_feed_comments')) {
            $query->with([
                'comments' => fn ($comments) => $comments
                    ->with(['author' => fn ($author) => $author->select($userColumns)])
                    ->oldest(),
            ]);
        }

        return $query
            ->latest()
            ->limit(20)
            ->get();
    }

    private function visiblePostsQuery(): Builder
    {
        $user = Filament::auth()->user();
        $marketId = $this->resolveMarketId();

        $query = StaffFeedPost::query();

        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if ($this->isSuperAdmin($user)) {
            return $query->when($marketId > 0, function (Builder $scoped) use ($marketId): Builder {
                return $scoped->where(function (Builder $marketScoped) use ($marketId): void {
                    $marketScoped
                        ->whereNull('market_id')
                        ->orWhere('market_id', $marketId);
                });
            });
        }

        if ($marketId <= 0) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('market_id', $marketId);
    }

    private function resolveMarketId(): int
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return 0;
        }

        if (! $this->isSuperAdmin($user)) {
            return (int) ($user->market_id ?: 0);
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

    /**
     * @return array{count:int,senders:list<string>}
     */
    private function unreadStaffMessageSummary(): array
    {
        $user = Filament::auth()->user();

        if (! $user || ! $this->canReadStaffMessageState()) {
            return [
                'count' => 0,
                'senders' => [],
            ];
        }

        $baseQuery = StaffConversationMessage::query()
            ->join('staff_conversations', 'staff_conversations.id', '=', 'staff_conversation_messages.staff_conversation_id')
            ->whereNull('staff_conversation_messages.read_at')
            ->where('staff_conversation_messages.user_id', '<>', (int) $user->id)
            ->where(function ($query) use ($user): void {
                $query
                    ->where('staff_conversations.created_by_user_id', (int) $user->id)
                    ->orWhere('staff_conversations.recipient_user_id', (int) $user->id);
            });

        $count = (clone $baseQuery)->count('staff_conversation_messages.id');

        if ($count < 1) {
            return [
                'count' => 0,
                'senders' => [],
            ];
        }

        $senders = (clone $baseQuery)
            ->join('users as senders', 'senders.id', '=', 'staff_conversation_messages.user_id')
            ->orderByDesc('staff_conversation_messages.created_at')
            ->limit(5)
            ->pluck('senders.name')
            ->map(static fn ($name): string => trim((string) $name))
            ->filter()
            ->unique()
            ->take(3)
            ->values()
            ->all();

        return [
            'count' => (int) $count,
            'senders' => $senders,
        ];
    }

    private function canReadStaffMessageState(): bool
    {
        return Schema::hasTable('staff_conversations')
            && Schema::hasTable('staff_conversation_messages')
            && Schema::hasColumn('staff_conversation_messages', 'read_at');
    }

    /**
     * @return list<string>
     */
    private function staffUserColumns(): array
    {
        $columns = ['id', 'name', 'email'];

        if (Schema::hasColumn('users', 'staff_avatar_path')) {
            $columns[] = 'staff_avatar_path';
        }

        if (Schema::hasColumn('users', 'staff_avatar_color')) {
            $columns[] = 'staff_avatar_color';
        }

        return $columns;
    }
}
