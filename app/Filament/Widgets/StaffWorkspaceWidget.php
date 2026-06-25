<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Resources\Staff\StaffResource;
use App\Filament\Resources\StaffInvitationResource;
use App\Support\MarketContext;
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
        $pendingInvitations = $this->countPendingInvitations($marketId);

        return [
            'pendingInvitations' => $pendingInvitations,
            'createUrl' => StaffResource::canCreate() ? StaffResource::getUrl('create') : null,
            'invitationsUrl' => StaffInvitationResource::canViewAny() ? StaffInvitationResource::getUrl('index') : null,
        ];
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
        return (int) (app(MarketContext::class)->currentMarketId() ?? 0);
    }
}
