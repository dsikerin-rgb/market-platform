<?php

namespace App\Filament\Resources\Staff\Pages;

use App\Filament\Resources\Staff\StaffResource;
use App\Filament\Resources\StaffInvitationResource;
use App\Filament\Widgets\StaffWorkspaceWidget;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

class ListStaff extends ListRecords
{
    protected static string $resource = StaffResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('invitations')
                ->label('Приглашения')
                ->icon('heroicon-o-envelope-open')
                ->url(fn () => StaffInvitationResource::getUrl('index'))
                ->visible(fn () => StaffInvitationResource::canViewAny()),

            CreateAction::make()
                ->label('Добавить сотрудника')
                ->visible(fn () => StaffResource::canCreate()),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            StaffWorkspaceWidget::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    public function getHeading(): string|Htmlable|null
    {
        return null;
    }
}
