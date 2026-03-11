<?php

declare(strict_types=1);

namespace App\Filament\Resources\TenantContractResource\Pages;

use App\Filament\Resources\Pages\BaseEditRecord;
use App\Filament\Resources\TenantContractResource;
use App\Models\TenantContract;
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

    protected function mutateFormDataBeforeSave(array $data): array
    {
        unset($data['is_active']);

        $currentMode = $this->record->effectiveSpaceMappingMode();
        $submittedMode = trim((string) ($data['space_mapping_mode'] ?? $currentMode));

        if (! in_array($submittedMode, TenantContract::spaceMappingModes(), true)) {
            $submittedMode = $currentMode;
        }

        $currentSpaceId = $this->normalizeNullableInt($this->record->market_space_id);
        $submittedSpaceId = $this->normalizeNullableInt($data['market_space_id'] ?? null);
        $spaceWasChanged = $currentSpaceId !== $submittedSpaceId;

        if ($submittedMode === TenantContract::SPACE_MAPPING_MODE_EXCLUDED) {
            $submittedSpaceId = null;
            $data['market_space_id'] = null;
            $spaceWasChanged = $currentSpaceId !== null;
        } elseif ($spaceWasChanged) {
            $submittedMode = TenantContract::SPACE_MAPPING_MODE_MANUAL;
        }

        $data['space_mapping_mode'] = $submittedMode;

        if ($spaceWasChanged || $submittedMode !== $currentMode) {
            $data['space_mapping_updated_at'] = now();
            $data['space_mapping_updated_by_user_id'] = Filament::auth()->id();
        } else {
            $data['space_mapping_updated_at'] = $this->record->space_mapping_updated_at;
            $data['space_mapping_updated_by_user_id'] = $this->record->space_mapping_updated_by_user_id;
        }

        return $data;
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

    private function normalizeNullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }
}
