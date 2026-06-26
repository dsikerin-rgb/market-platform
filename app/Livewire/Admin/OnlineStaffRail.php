<?php

namespace App\Livewire\Admin;

use App\Models\StaffConversationMessage;
use App\Models\User;
use App\Support\MarketContext;
use App\Support\StaffConversationService;
use App\Support\SystemAgentService;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Component;

class OnlineStaffRail extends Component
{
    public ?int $selectedStaffId = null;

    public string $messageBody = '';

    public function render(): View
    {
        $this->touchCurrentUserPresence();

        return view('livewire.admin.online-staff-rail', [
            'onlineStaff' => $this->onlineStaff(),
            'offlineStaff' => $this->offlineStaff(),
            'selectedStaff' => $this->selectedStaff(),
            'selectedStaffUnreadMessages' => $this->selectedStaffUnreadMessages(),
        ]);
    }

    private function touchCurrentUserPresence(): void
    {
        if (! Schema::hasColumn('users', 'last_seen_at')) {
            return;
        }

        $user = Filament::auth()->user();

        if (! $user) {
            return;
        }

        $lastSeenAt = $user->last_seen_at;
        if ($lastSeenAt && $lastSeenAt->greaterThan(now()->subMinute())) {
            return;
        }

        $now = now();

        DB::table('users')
            ->where('id', $user->getAuthIdentifier())
            ->update(['last_seen_at' => $now]);

        $user->forceFill(['last_seen_at' => $now]);
    }

    private function onlineStaff(): Collection
    {
        if (! Schema::hasColumn('users', 'last_seen_at')) {
            return collect();
        }

        $user = Filament::auth()->user();

        if (! $user) {
            return collect();
        }

        $staff = $this->staffBaseQuery($this->resolveMarketId(), $user)
            ->whereNotNull('last_seen_at')
            ->where('last_seen_at', '>=', now()->subMinutes(5))
            ->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [(int) $user->id])
            ->orderByDesc('last_seen_at')
            ->limit(12)
            ->get();

        return $this->attachUnreadCounts($staff, $user);
    }

    private function offlineStaff(): Collection
    {
        if (! Schema::hasColumn('users', 'last_seen_at')) {
            return collect();
        }

        $user = Filament::auth()->user();

        if (! $user) {
            return collect();
        }

        $marketId = $this->resolveMarketId();

        $staff = $this->staffBaseQuery($marketId, $user)
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('last_seen_at')
                    ->orWhere('last_seen_at', '<', now()->subMinutes(5));
            })
            ->orderByDesc('last_seen_at')
            ->orderBy('name')
            ->limit(10)
            ->get();

        return $this->attachUnreadCounts($staff, $user);
    }

    public function openStaffModal(int $userId): void
    {
        $this->selectedStaffId = $userId;
        $this->messageBody = '';
    }

    public function closeStaffModal(): void
    {
        $this->selectedStaffId = null;
        $this->messageBody = '';
        $this->resetErrorBag('messageBody');
    }

    public function markSelectedStaffMessagesRead(StaffConversationService $service): void
    {
        $user = Filament::auth()->user();
        $staff = $this->selectedStaff();

        if (! $user || ! $staff) {
            return;
        }

        $service->markIncomingFromStaffRead($user, $staff);
    }

    public function sendStaffMessage(StaffConversationService $service): void
    {
        $this->validate([
            'messageBody' => ['required', 'string', 'min:2', 'max:5000'],
        ]);

        $user = Filament::auth()->user();
        $staff = $this->selectedStaff();

        if (! $user || ! $staff) {
            return;
        }

        if ((int) $staff->id === (int) $user->id) {
            $this->addError('messageBody', 'Нельзя отправить сообщение самому себе.');

            return;
        }

        if (! Schema::hasTable('staff_conversations') || ! Schema::hasTable('staff_conversation_messages')) {
            $this->addError('messageBody', 'Внутренние диалоги сотрудников пока недоступны.');

            return;
        }

        $service->startConversation($user, $staff, '', trim($this->messageBody));

        Notification::make()
            ->title('Сообщение отправлено')
            ->success()
            ->send();

        $this->closeStaffModal();
    }

    private function selectedStaff(): ?User
    {
        $user = Filament::auth()->user();

        if (! $user || ! $this->selectedStaffId) {
            return null;
        }

        return $this->staffBaseQuery($this->resolveMarketId(), $user)
            ->whereKey($this->selectedStaffId)
            ->first();
    }

    private function staffBaseQuery(int $marketId, User $user): Builder
    {
        $query = User::query()
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('tenant_id')
                    ->orWhere('tenant_id', 0);
            })
            ->whereDoesntHave('roles', function (Builder $query): void {
                $query->whereIn('name', ['merchant', 'merchant-user', 'tenant', 'buyer', 'user']);
            })
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('email')
                    ->orWhereRaw('LOWER(email) NOT LIKE ?', ['%@' . SystemAgentService::EMAIL_DOMAIN]);
            });

        if ($this->isSuperAdmin($user)) {
            return $query->when($marketId > 0, function (Builder $scoped) use ($marketId, $user): Builder {
                return $scoped->where(function (Builder $marketScoped) use ($marketId, $user): void {
                    $marketScoped
                        ->where('market_id', $marketId)
                        ->orWhere('id', (int) $user->id);
                });
            });
        }

        if ($marketId <= 0) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $marketScoped) use ($marketId): void {
            $marketScoped
                ->where('market_id', $marketId)
                ->orWhereHas('roles', function (Builder $roleQuery): void {
                    $roleQuery->where('name', 'super-admin');
                });
        });
    }

    private function attachUnreadCounts(Collection $staff, User $user): Collection
    {
        if ($staff->isEmpty() || ! $this->canReadMessageState()) {
            return $staff;
        }

        $counts = $this->unreadCountsBySender($user);

        return $staff->map(function (User $person) use ($counts): User {
            $person->setAttribute('unread_staff_messages_count', (int) ($counts[(int) $person->id] ?? 0));

            return $person;
        });
    }

    private function unreadCountsBySender(User $user): Collection
    {
        if (! $this->canReadMessageState()) {
            return collect();
        }

        return StaffConversationMessage::query()
            ->selectRaw('staff_conversation_messages.user_id as sender_user_id, COUNT(*) as unread_count')
            ->join('staff_conversations', 'staff_conversations.id', '=', 'staff_conversation_messages.staff_conversation_id')
            ->whereNull('staff_conversation_messages.read_at')
            ->where('staff_conversation_messages.user_id', '<>', (int) $user->id)
            ->where(function ($query) use ($user): void {
                $query
                    ->where('staff_conversations.created_by_user_id', (int) $user->id)
                    ->orWhere('staff_conversations.recipient_user_id', (int) $user->id);
            })
            ->groupBy('staff_conversation_messages.user_id')
            ->pluck('unread_count', 'sender_user_id')
            ->mapWithKeys(static fn ($count, $userId): array => [(int) $userId => (int) $count]);
    }

    private function selectedStaffUnreadMessages(): Collection
    {
        $user = Filament::auth()->user();
        $staff = $this->selectedStaff();

        if (! $user || ! $staff || ! $this->canReadMessageState()) {
            return collect();
        }

        return StaffConversationMessage::query()
            ->select(['staff_conversation_messages.id', 'staff_conversation_messages.staff_conversation_id', 'staff_conversation_messages.user_id', 'staff_conversation_messages.body', 'staff_conversation_messages.created_at'])
            ->join('staff_conversations', 'staff_conversations.id', '=', 'staff_conversation_messages.staff_conversation_id')
            ->whereNull('staff_conversation_messages.read_at')
            ->where('staff_conversation_messages.user_id', (int) $staff->id)
            ->where(function ($query) use ($user, $staff): void {
                $query->where(function ($pair) use ($user, $staff): void {
                    $pair
                        ->where('staff_conversations.created_by_user_id', (int) $user->id)
                        ->where('staff_conversations.recipient_user_id', (int) $staff->id);
                })->orWhere(function ($pair) use ($user, $staff): void {
                    $pair
                        ->where('staff_conversations.created_by_user_id', (int) $staff->id)
                        ->where('staff_conversations.recipient_user_id', (int) $user->id);
                });
            })
            ->orderByDesc('staff_conversation_messages.created_at')
            ->limit(3)
            ->get();
    }

    private function canReadMessageState(): bool
    {
        return Schema::hasTable('staff_conversations')
            && Schema::hasTable('staff_conversation_messages')
            && Schema::hasColumn('staff_conversation_messages', 'read_at');
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

        return (int) (app(MarketContext::class)->currentMarketId($user) ?? 0);
    }

    private function isSuperAdmin(User $user): bool
    {
        return method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin();
    }
}
