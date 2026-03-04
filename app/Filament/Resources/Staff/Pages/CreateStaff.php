<?php

namespace App\Filament\Resources\Staff\Pages;

use App\Filament\Resources\Staff\StaffResource;
use App\Support\UserNotificationPreferences;
use Filament\Facades\Filament;
use App\Filament\Resources\Pages\BaseCreateRecord;
use Spatie\Permission\Models\Role;

class CreateStaff extends BaseCreateRecord
{
    protected static string $resource = StaffResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return $data;
        }

        // Рыночные роли всегда создают сотрудника только в своем рынке
        if (! $user->isSuperAdmin()) {
            $data['market_id'] = $user->market_id;

            // Нельзя назначить super-admin через подмену запроса
            if (isset($data['roles']) && is_array($data['roles'])) {
                $superAdminRoleId = Role::query()->where('name', 'super-admin')->value('id');

                if ($superAdminRoleId) {
                    $data['roles'] = array_values(array_filter(
                        $data['roles'],
                        fn ($roleId) => (int) $roleId !== (int) $superAdminRoleId,
                    ));
                }
            }
        }

        if ($user->isSuperAdmin() || $user->isMarketAdmin()) {
            $data = $this->normalizeNotificationPreferences($data);
        } else {
            unset($data['notification_preferences']);
        }

        return $data;
    }

    private function normalizeNotificationPreferences(array $data): array
    {
        $preferences = app(UserNotificationPreferences::class);

        $roleIds = array_values(array_filter(
            (array) ($data['roles'] ?? []),
            static fn ($value): bool => is_numeric($value),
        ));

        $roleNames = $roleIds === []
            ? []
            : Role::query()->whereIn('id', $roleIds)->pluck('name')->all();

        $mustSelfManage = in_array('super-admin', $roleNames, true)
            || in_array('market-admin', $roleNames, true);

        $raw = is_array($data['notification_preferences'] ?? null)
            ? $data['notification_preferences']
            : [];

        $raw['self_manage'] = $mustSelfManage || (bool) ($raw['self_manage'] ?? false);
        $data['notification_preferences'] = $preferences->normalizeForStorage(
            $raw,
            (bool) $raw['self_manage']
        );

        return $data;
    }
}
