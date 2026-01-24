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
}
