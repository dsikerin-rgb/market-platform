<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\TaskResource;
use App\Models\Market;
use App\Models\User;
use App\Support\TaskAssignmentRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TaskAssignmentHierarchyTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_member_cannot_assign_task_to_higher_level_employee(): void
    {
        $market = Market::create([
            'name' => 'Hierarchy market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $employee = $this->staff($market, 'employee@example.test', 5);
        $director = $this->staff($market, 'director@example.test', 2);

        $rules = app(TaskAssignmentRules::class);

        $this->assertFalse($rules->canAssignWork($employee, $director));
        $this->assertTrue($rules->canObserve($employee, $director));
    }

    public function test_staff_member_cannot_assign_task_to_manager_from_manager_chain(): void
    {
        $market = Market::create([
            'name' => 'Manager chain market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $manager = $this->staff($market, 'manager@example.test', null);
        $employee = $this->staff($market, 'employee-chain@example.test', null, [
            'manager_user_id' => $manager->id,
        ]);

        $rules = app(TaskAssignmentRules::class);

        $this->assertFalse($rules->canAssignWork($employee, $manager));
        $this->assertTrue($rules->canAssignWork($manager, $employee));
    }

    public function test_task_resource_assignable_scope_excludes_higher_level_users(): void
    {
        $market = Market::create([
            'name' => 'Scope market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $employee = $this->staff($market, 'scope-employee@example.test', 5);
        $peer = $this->staff($market, 'scope-peer@example.test', 5);
        $director = $this->staff($market, 'scope-director@example.test', 2);

        $ids = TaskResource::limitAssignableUsersToMarket(User::query(), $employee)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $this->assertContains((int) $employee->id, $ids);
        $this->assertContains((int) $peer->id, $ids);
        $this->assertNotContains((int) $director->id, $ids);
    }

    public function test_market_admin_can_assign_higher_level_employee(): void
    {
        $market = Market::create([
            'name' => 'Admin assignment market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        Role::findOrCreate('market-admin');

        $admin = $this->staff($market, 'admin@example.test', 5);
        $admin->assignRole('market-admin');

        $director = $this->staff($market, 'admin-director@example.test', 2);

        $this->assertTrue(app(TaskAssignmentRules::class)->canAssignWork($admin, $director));
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function staff(Market $market, string $email, ?int $level, array $extra = []): User
    {
        $attributes = array_merge([
            'market_id' => $market->id,
            'tenant_id' => null,
            'email' => $email,
            'organization_level' => $level,
        ], $extra);

        return User::factory()->create($attributes);
    }
}
