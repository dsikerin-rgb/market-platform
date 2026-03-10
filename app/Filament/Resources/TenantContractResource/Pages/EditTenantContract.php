<?php

declare(strict_types=1);

namespace App\Filament\Resources\TenantContractResource\Pages;

use App\Filament\Resources\Pages\BaseEditRecord;
use App\Filament\Resources\TenantContractResource;
use Filament\Actions;
use Filament\Facades\Filament;

class EditTenantContract extends BaseEditRecord
{
    protected static string $resource = TenantContractResource::class;

    protected static ?string $title = 'Карточка договора';

    public function getBreadcrumb(): string
    {
        return 'Карточка договора';
    }

    protected function isReadOnly(): bool
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return true;
        }

        if ($user->isSuperAdmin()) {
            return false;
        }

        return ! $user->hasRole('market-admin');
    }

    protected function getHeaderActions(): array
    {
        if (! $this->isReadOnly()) {
            return [];
        }

        return [
            Actions\Action::make('readonly_hint')
                ->label('Только просмотр')
                ->color('gray')
                ->disabled()
                ->action(fn () => null),
        ];
    }

    protected function getFormActions(): array
    {
        if ($this->isReadOnly()) {
            return [];
        }

        return parent::getFormActions();
    }
}
