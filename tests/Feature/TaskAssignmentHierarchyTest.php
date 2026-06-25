<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\TaskResource;
use App\Models\Market;
use App\Models\Task;
use App\Models\TaskParticipant;
use App\Models\User;
use App\Support\TaskAssignmentRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
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
            'manager_id' => $manager->id,
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

    public function test_direct_task_create_cannot_bypass_assignment_hierarchy(): void
    {
        $market = Market::create([
            'name' => 'Domain guard market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $employee = $this->staff($market, 'domain-employee@example.test', 5);
        $director = $this->staff($market, 'domain-director@example.test', 2);

        $this->actingAs($employee);

        $this->expectException(ValidationException::class);

        Task::create([
            'market_id' => $market->id,
            'title' => 'Direct bypass attempt',
            'created_by_user_id' => $employee->id,
            'assignee_id' => $director->id,
        ]);
    }

    public function test_direct_coexecutor_create_cannot_bypass_assignment_hierarchy(): void
    {
        $market = Market::create([
            'name' => 'Participant guard market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $employee = $this->staff($market, 'participant-employee@example.test', 5);
        $director = $this->staff($market, 'participant-director@example.test', 2);

        $task = Task::create([
            'market_id' => $market->id,
            'title' => 'Participant bypass attempt',
            'created_by_user_id' => $employee->id,
        ]);

        $this->actingAs($employee);

        $this->expectException(ValidationException::class);

        TaskParticipant::create([
            'task_id' => $task->id,
            'user_id' => $director->id,
            'role' => Task::PARTICIPANT_ROLE_COEXECUTOR,
        ]);
    }

    public function test_direct_observer_create_allows_higher_level_employee_in_same_market(): void
    {
        $market = Market::create([
            'name' => 'Observer guard market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $employee = $this->staff($market, 'observer-employee@example.test', 5);
        $director = $this->staff($market, 'observer-director@example.test', 2);

        $task = Task::create([
            'market_id' => $market->id,
            'title' => 'Observer allowed',
            'created_by_user_id' => $employee->id,
        ]);

        $this->actingAs($employee);

        $participant = TaskParticipant::create([
            'task_id' => $task->id,
            'user_id' => $director->id,
            'role' => Task::PARTICIPANT_ROLE_OBSERVER,
        ]);

        $this->assertTrue($participant->exists);
    }

    public function test_direct_participant_create_without_role_is_checked_as_observer(): void
    {
        $market = Market::create([
            'name' => 'Default observer guard market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);
        $otherMarket = Market::create([
            'name' => 'Other observer guard market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $employee = $this->staff($market, 'default-observer-employee@example.test', 5);
        $outsider = $this->staff($otherMarket, 'default-observer-outsider@example.test', 5);

        $task = Task::create([
            'market_id' => $market->id,
            'title' => 'Default observer bypass attempt',
            'created_by_user_id' => $employee->id,
        ]);

        $this->actingAs($employee);

        $this->expectException(ValidationException::class);

        TaskParticipant::create([
            'task_id' => $task->id,
            'user_id' => $outsider->id,
        ]);
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
