<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\User;
use App\Support\StaffHierarchy;
use PHPUnit\Framework\TestCase;

class StaffHierarchyTest extends TestCase
{
    public function test_lower_level_employee_cannot_assign_task_to_higher_level_employee(): void
    {
        $manager = $this->user(id: 1, level: 10);
        $employee = $this->user(id: 2, level: 30);

        self::assertTrue(StaffHierarchy::canAssignTaskTo($manager, $employee));
        self::assertFalse(StaffHierarchy::canAssignTaskTo($employee, $manager));
    }

    public function test_employee_can_assign_task_to_self_and_same_level_employee(): void
    {
        $employee = $this->user(id: 2, level: 30);
        $colleague = $this->user(id: 3, level: 30);

        self::assertTrue(StaffHierarchy::canAssignTaskTo($employee, $employee));
        self::assertTrue(StaffHierarchy::canAssignTaskTo($employee, $colleague));
    }

    public function test_missing_hierarchy_level_does_not_block_existing_workflows(): void
    {
        $employee = $this->user(id: 2, level: null);
        $candidate = $this->user(id: 3, level: 10);

        self::assertTrue(StaffHierarchy::canAssignTaskTo($employee, $candidate));
    }

    public function test_market_admin_can_assign_across_hierarchy(): void
    {
        $admin = $this->user(id: 1, level: 30, marketAdmin: true);
        $manager = $this->user(id: 2, level: 10);

        self::assertTrue(StaffHierarchy::canAssignTaskTo($admin, $manager));
    }

    private function user(int $id, ?int $level, bool $marketAdmin = false): User
    {
        $user = new class extends User {
            public bool $marketAdmin = false;

            public function isSuperAdmin(): bool
            {
                return false;
            }

            public function isMarketAdmin(): bool
            {
                return $this->marketAdmin;
            }
        };

        $user->marketAdmin = $marketAdmin;
        $user->setRawAttributes([
            'id' => $id,
            'name' => 'User '.$id,
            'organization_level' => $level,
        ], true);

        return $user;
    }
}
