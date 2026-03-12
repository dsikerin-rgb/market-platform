<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Resources\Staff\StaffResource;
use App\Filament\Resources\StaffInvitationResource;
use App\Models\Market;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class StaffWorkspaceWidget extends Widget
{
    protected string $view = 'filament.widgets.staff-workspace-widget';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = -100;

    public static function canView(): bool
    {
        return StaffResource::canViewAny();
    }

    protected function getViewData(): array
    {
        $marketId = $this->resolveMarketId();
        $market = $marketId > 0
            ? Market::query()->select(['id', 'name'])->find($marketId)
            : null;

        $staffQuery = StaffResource::getEloquentQuery();

        $total = (clone $staffQuery)->count();
        $admins = $this->countStaffByRole($marketId, 'market-admin');
        $managers = $this->countStaffByRole($marketId, 'market-manager');
        $operators = $this->countStaffByRole($marketId, 'market-operator');
        $pendingInvitations = $this->countPendingInvitations($marketId);

        return [
            'marketName' => $market?->name,
            'total' => $total,
            'admins' => $admins,
            'managers' => $managers,
            'operators' => $operators,
            'pendingInvitations' => $pendingInvitations,
            'allUrl' => StaffResource::getUrl('index'),
            'createUrl' => StaffResource::canCreate() ? StaffResource::getUrl('create') : null,
            'invitationsUrl' => StaffInvitationResource::canViewAny() ? StaffInvitationResource::getUrl('index') : null,
        ];
    }

    private function countStaffByRole(int $marketId, string $role): int
    {
        if (
            $marketId <= 0
            || ! Schema::hasTable('users')
            || ! Schema::hasTable('roles')
            || ! Schema::hasTable('model_has_roles')
        ) {
            return 0;
        }

        $query = DB::table('users')
            ->join('model_has_roles', function ($join): void {
                $join->on('model_has_roles.model_id', '=', 'users.id')
                    ->where('model_has_roles.model_type', '=', 'App\Models\User');
            })
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('users.market_id', $marketId)
            ->where(function ($staffOnly): void {
                $staffOnly
                    ->whereNull('users.tenant_id')
                    ->orWhere('users.tenant_id', 0);
            })
            ->where('roles.name', $role);

        return (int) $query->distinct()->count('users.id');
    }

    private function countPendingInvitations(int $marketId): int
    {
        if ($marketId <= 0 || ! Schema::hasTable('staff_invitations')) {
            return 0;
        }

        return (int) DB::table('staff_invitations')
            ->where('market_id', $marketId)
            ->whereNull('accepted_at')
            ->count();
    }

    private function resolveMarketId(): int
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return 0;
        }

        if (! $user->isSuperAdmin()) {
            return (int) ($user->market_id ?: 0);
        }

        $panelId = Filament::getCurrentPanel()?->getId() ?? 'admin';
        $value = session("filament_{$panelId}_market_id");

        return filled($value) ? (int) $value : 0;
    }
}
