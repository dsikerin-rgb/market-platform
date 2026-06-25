<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Filament\Resources\Staff\StaffResource;
use App\Models\User;
use Tests\TestCase;

class StaffAccessTabVisibilityTest extends TestCase
{
    public function test_access_tab_is_hidden_for_non_admins_on_other_staff_profiles(): void
    {
        $viewer = $this->staffUser(id: 1);
        $peer = $this->staffUser(id: 2);
        $marketAdmin = $this->staffUser(id: 3, marketAdmin: true);
        $superAdmin = $this->staffUser(id: 4, superAdmin: true);

        self::assertFalse(StaffResource::canManageStaffAccess($viewer));
        self::assertFalse(StaffResource::canViewStaffAccessTab($peer, $viewer, 'edit'));
        self::assertFalse(StaffResource::canViewStaffAccessTab(null, $viewer, 'create'));
        self::assertTrue(StaffResource::canViewStaffAccessTab($viewer, $viewer, 'edit'));

        self::assertTrue(StaffResource::canManageStaffAccess($marketAdmin));
        self::assertTrue(StaffResource::canViewStaffAccessTab($peer, $marketAdmin, 'edit'));
        self::assertTrue(StaffResource::canViewStaffAccessTab(null, $marketAdmin, 'create'));

        self::assertTrue(StaffResource::canManageStaffAccess($superAdmin));
        self::assertTrue(StaffResource::canViewStaffAccessTab($peer, $superAdmin, 'edit'));
    }

    private function staffUser(int $id, bool $superAdmin = false, bool $marketAdmin = false): StaffAccessTabUser
    {
        $user = (new StaffAccessTabUser())->forceFill([
            'id' => $id,
            'market_id' => 1,
            'name' => 'User ' . $id,
            'email' => 'user-' . $id . '@example.test',
        ]);

        $user->superAdmin = $superAdmin;
        $user->marketAdmin = $marketAdmin;

        return $user;
    }
}

class StaffAccessTabUser extends User
{
    public bool $superAdmin = false;

    public bool $marketAdmin = false;

    public function isSuperAdmin(): bool
    {
        return $this->superAdmin;
    }

    public function isMarketAdmin(): bool
    {
        return $this->marketAdmin;
    }
}
