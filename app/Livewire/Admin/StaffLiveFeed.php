<?php

namespace App\Livewire\Admin;

use App\Models\StaffFeedPost;
use App\Support\MarketplaceMediaStorage;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
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
            'market_id' => $this->resolveMarketId() ?: null,
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

    public function render(): View
    {
        return view('livewire.admin.staff-live-feed', [
            'posts' => $this->posts(),
        ]);
    }

    private function posts()
    {
        if (! Schema::hasTable('staff_feed_posts')) {
            return collect();
        }

        $marketId = $this->resolveMarketId();

        return StaffFeedPost::query()
            ->with(['author:id,name,email'])
            ->when($marketId > 0, function (Builder $query) use ($marketId): Builder {
                return $query->where(function (Builder $scoped) use ($marketId): void {
                    $scoped
                        ->whereNull('market_id')
                        ->orWhere('market_id', $marketId);
                });
            })
            ->latest()
            ->limit(20)
            ->get();
    }

    private function resolveMarketId(): int
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return 0;
        }

        if (! (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin())) {
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
}
