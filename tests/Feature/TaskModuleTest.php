<?php
# tests/Feature/TaskModuleTest.php

namespace Tests\Feature;

use App\Models\Market;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TaskAssignedNotification;
use App\Policies\TaskPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TaskModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_task_created_from_ticket_category(): void
    {
        config(['tasks.auto_create_from_ticket_categories' => ['maintenance']]);

        $market = Market::create([
            'name' => 'Рынок 1',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $ticket = Ticket::create([
            'market_id' => $market->id,
            'subject' => 'Не работает свет',
            'description' => 'Нужно заменить лампы в проходе.',
            'category' => 'maintenance',
            'priority' => 'normal',
            'status' => 'new',
        ]);

        $this->assertDatabaseHas('tasks', [
            'market_id' => $market->id,
            'title' => "Заявка #{$ticket->id}: {$ticket->subject}",
            'source_type' => Ticket::class,
            'source_id' => $ticket->id,
            'status' => Task::STATUS_NEW,
        ]);
    }

    public function test_task_market_isolation(): void
    {
        $marketA = Market::create([
            'name' => 'Рынок А',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $marketB = Market::create([
            'name' => 'Рынок Б',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'market_id' => $marketA->id,
        ]);

        Role::findOrCreate('market-maintenance');
        $user->assignRole('market-maintenance');

        $task = Task::create([
            'market_id' => $marketB->id,
            'title' => 'Проверка вентиляции',
            'status' => Task::STATUS_NEW,
            'priority' => Task::PRIORITY_NORMAL,
        ]);

        $policy = app(TaskPolicy::class);

        $this->assertFalse($policy->view($user, $task));
    }

    public function test_notification_sent_on_assignment(): void
    {
        Notification::fake();

        $market = Market::create([
            'name' => 'Рынок 2',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $assignee = User::factory()->create([
            'market_id' => $market->id,
        ]);

        Task::create([
            'market_id' => $market->id,
            'title' => 'Проверить кондиционеры',
            'status' => Task::STATUS_NEW,
            'priority' => Task::PRIORITY_NORMAL,
            'assignee_id' => $assignee->id,
        ]);

        Notification::assertSentTo($assignee, TaskAssignedNotification::class);
    }

    public function test_relevant_tab_shows_only_upcoming_and_no_deadline_tasks(): void
    {
        $market = Market::create([
            'name' => 'Рынок для теста',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'market_id' => $market->id,
        ]);

        // Дадим пользователю роль, чтобы он мог видеть задачи
        $role = Role::findOrCreate('market-admin');
        $user->assignRole($role);

        $today = now()->startOfDay();
        $tomorrow = $today->copy()->addDay();
        $yesterday = $today->copy()->subDay();

        // Актуальные задачи (должны попадать в таб "relevant")
        $taskNoDeadline = Task::create([
            'market_id' => $market->id,
            'title' => 'Без дедлайна',
            'status' => Task::STATUS_NEW,
            'priority' => Task::PRIORITY_NORMAL,
        ]);

        $taskToday = Task::create([
            'market_id' => $market->id,
            'title' => 'Дедлайн сегодня',
            'status' => Task::STATUS_NEW,
            'priority' => Task::PRIORITY_NORMAL,
            'due_at' => $today,
        ]);

        $taskFuture = Task::create([
            'market_id' => $market->id,
            'title' => 'Дедлайн в будущем',
            'status' => Task::STATUS_IN_PROGRESS,
            'priority' => Task::PRIORITY_NORMAL,
            'due_at' => $tomorrow,
        ]);

        // Неохватные задачи (не должны попадать в таб "relevant")
        $taskOverdue = Task::create([
            'market_id' => $market->id,
            'title' => 'Просроченная',
            'status' => Task::STATUS_NEW,
            'priority' => Task::PRIORITY_NORMAL,
            'due_at' => $yesterday,
        ]);

        $taskCompleted = Task::create([
            'market_id' => $market->id,
            'title' => 'Завершённая без дедлайна',
            'status' => Task::STATUS_COMPLETED,
            'priority' => Task::PRIORITY_NORMAL,
        ]);

        $taskCancelledPast = Task::create([
            'market_id' => $market->id,
            'title' => 'Отменённая с прошлым дедлайном',
            'status' => Task::STATUS_CANCELLED,
            'priority' => Task::PRIORITY_NORMAL,
            'due_at' => $yesterday,
        ]);

        $this->actingAs($user)
            ->get(route('filament.admin.resources.tasks.index', ['tab' => 'relevant']))
            ->assertOk()
            ->assertSeeText($taskNoDeadline->title)
            ->assertSeeText($taskToday->title)
            ->assertSeeText($taskFuture->title)
            ->assertDontSeeText($taskOverdue->title)
            ->assertDontSeeText($taskCompleted->title)
            ->assertDontSeeText($taskCancelledPast->title);
    }

    public function test_all_tab_shows_all_tasks(): void
    {
        $market = Market::create([
            'name' => 'Рынок для теста all',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'market_id' => $market->id,
        ]);

        // Дадим пользователю роль, чтобы он мог видеть задачи
        $role = Role::findOrCreate('market-admin');
        $user->assignRole($role);

        $today = now()->startOfDay();
        $yesterday = $today->copy()->subDay();

        $taskRelevant = Task::create([
            'market_id' => $market->id,
            'title' => 'Актуальная задача',
            'status' => Task::STATUS_NEW,
            'priority' => Task::PRIORITY_NORMAL,
        ]);

        $taskOverdue = Task::create([
            'market_id' => $market->id,
            'title' => 'Просроченная',
            'status' => Task::STATUS_NEW,
            'priority' => Task::PRIORITY_NORMAL,
            'due_at' => $yesterday,
        ]);

        $taskClosed = Task::create([
            'market_id' => $market->id,
            'title' => 'Завершённая',
            'status' => Task::STATUS_COMPLETED,
            'priority' => Task::PRIORITY_NORMAL,
        ]);

        $this->actingAs($user)
            ->get(route('filament.admin.resources.tasks.index', ['tab' => 'all']))
            ->assertOk()
            ->assertSeeText($taskRelevant->title)
            ->assertSeeText($taskOverdue->title)
            ->assertSeeText($taskClosed->title);
    }
}
