<?php

namespace App\Livewire\Admin;

use App\Models\User;
use App\Support\StaffConversationService;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use Livewire\Component;

class OnlineStaffRail extends Component
{
    public ?int $selectedStaffId = null;

    public string $messageBody = '';

    public function render(): View
    {
        return view('livewire.admin.online-staff-rail', [
            'onlineStaff' => $this->onlineStaff(),
            'offlineStaff' => $this->offlineStaff(),
            'selectedStaff' => $this->selectedStaff(),
        ]);
    }

    private function onlineStaff()
    {
        if (! Schema::hasColumn('users', 'last_seen_at')) {
            return collect();
        }

        $user = Filament::auth()->user();

        if (! $user) {
            return collect();
        }

        $marketId = $this->resolveMarketId();

        return User::query()
            ->select(['id', 'name', 'email', 'market_id', 'last_seen_at'])
            ->whereNotNull('last_seen_at')
            ->where('last_seen_at', '>=', now()->subMinutes(5))
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('tenant_id')
                    ->orWhere('tenant_id', 0);
            })
            ->whereDoesntHave('roles', function (Builder $query): void {
                $query->whereIn('name', ['merchant', 'merchant-user', 'tenant', 'buyer', 'user']);
            })
            ->when($marketId > 0, function (Builder $query) use ($marketId, $user): Builder {
                return $query->where(function (Builder $scoped) use ($marketId, $user): void {
                    $scoped
                        ->where('market_id', $marketId)
                        ->orWhere('id', (int) $user->id);
                });
            })
            ->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [(int) $user->id])
            ->orderByDesc('last_seen_at')
            ->limit(12)
            ->get();
    }

    private function offlineStaff()
    {
        if (! Schema::hasColumn('users', 'last_seen_at')) {
            return collect();
        }

        $user = Filament::auth()->user();

        if (! $user) {
            return collect();
        }

        $marketId = $this->resolveMarketId();

        return $this->staffBaseQuery($marketId, $user)
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('last_seen_at')
                    ->orWhere('last_seen_at', '<', now()->subMinutes(5));
            })
            ->orderByDesc('last_seen_at')
            ->orderBy('name')
            ->limit(10)
            ->get();
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
        return User::query()
            ->select(['id', 'name', 'email', 'market_id', 'last_seen_at'])
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('tenant_id')
                    ->orWhere('tenant_id', 0);
            })
            ->whereDoesntHave('roles', function (Builder $query): void {
                $query->whereIn('name', ['merchant', 'merchant-user', 'tenant', 'buyer', 'user']);
            })
            ->when($marketId > 0, function (Builder $query) use ($marketId, $user): Builder {
                return $query->where(function (Builder $scoped) use ($marketId, $user): void {
                    $scoped
                        ->where('market_id', $marketId)
                        ->orWhere('id', (int) $user->id);
                });
            });
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
