<?php
# tests/Feature/TaskModuleTest.php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Market;
use App\Models\MarketHoliday;
use App\Models\MarketHolidayTaskLink;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TaskAssignedNotification;
use App\Policies\TaskPolicy;
use App\Services\TaskHolidayLinkService;
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

    public function test_task_shows_linked_holiday(): void
    {
        $market = Market::create([
            'name' => 'Рынок для теста связи',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $user = User::factory()->create(['market_id' => $market->id]);
        $role = Role::findOrCreate('market-admin');
        $user->assignRole($role);

        $holiday = MarketHoliday::create([
            'market_id' => $market->id,
            'title' => 'Праздничный день',
            'starts_at' => now()->addDays(5),
            'source' => 'market_event',
        ]);

        $task = Task::create([
            'market_id' => $market->id,
            'title' => 'Подготовить мероприятие',
            'status' => Task::STATUS_NEW,
            'priority' => Task::PRIORITY_NORMAL,
            'source_type' => MarketHoliday::class,
            'source_id' => $holiday->id,
        ]);

        $this->actingAs($user)
            ->get(route('filament.admin.resources.tasks.edit', ['record' => $task]))
            ->assertOk()
            ->assertSeeText('Праздничный день');
    }

    public function test_holiday_shows_linked_tasks(): void
    {
        $market = Market::create([
            'name' => 'Рынок для теста задач события',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $user = User::factory()->create(['market_id' => $market->id]);
        $role = Role::findOrCreate('market-admin');
        $user->assignRole($role);

        $holiday = MarketHoliday::create([
            'market_id' => $market->id,
            'title' => 'Новогодняя ярмарка',
            'starts_at' => now()->addDays(10),
            'source' => 'market_event',
        ]);

        $task = Task::create([
            'market_id' => $market->id,
            'title' => 'Украшение территории',
            'status' => Task::STATUS_IN_PROGRESS,
            'priority' => Task::PRIORITY_HIGH,
            'due_at' => now()->addDays(3),
            'assignee_id' => $user->id,
        ]);

        MarketHolidayTaskLink::create([
            'market_id' => $market->id,
            'market_holiday_id' => $holiday->id,
            'task_id' => $task->id,
            'scenario_key' => 'manual_task_' . $task->id,
        ]);

        // Проверка через модель что связь существует
        $this->assertDatabaseHas('market_holiday_task_links', [
            'market_holiday_id' => $holiday->id,
            'task_id' => $task->id,
        ]);

        // Проверка что задача связана со событием
        $linkedHoliday = $task->linkedMarketHoliday();
        $this->assertNotNull($linkedHoliday);
        $this->assertEquals($holiday->id, $linkedHoliday->id);
    }

    public function test_create_holiday_from_task_via_service(): void
    {
        $market = Market::create([
            'name' => 'Рынок для теста создания события',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $user = User::factory()->create(['market_id' => $market->id]);
        $role = Role::findOrCreate('market-admin');
        $user->assignRole($role);

        $task = Task::create([
            'market_id' => $market->id,
            'title' => 'Провести встречу с арендаторами',
            'description' => 'Обсудить план мероприятий на квартал.',
            'status' => Task::STATUS_NEW,
            'priority' => Task::PRIORITY_NORMAL,
            'due_at' => now()->addDays(7),
        ]);

        $service = new TaskHolidayLinkService();
        $result = $service->createHolidayFromTask($task);

        $this->assertTrue($result['success']);
        $this->assertNotNull($result['holiday']);
        $this->assertEquals('Провести встречу с арендаторами', $result['holiday']->title);
        $this->assertEquals('market_event', $result['holiday']->source);

        // Проверка audience_payload
        $this->assertFalse($result['holiday']->audience_payload['scenarios']['enabled_tasks']);

        // Проверка связи
        $this->assertDatabaseHas('market_holiday_task_links', [
            'market_id' => $market->id,
            'task_id' => $task->id,
            'market_holiday_id' => $result['holiday']->id,
        ]);

        // Проверка что source_type/source_id заполнились
        $task->refresh();
        $this->assertEquals(MarketHoliday::class, $task->source_type);
        $this->assertEquals($result['holiday']->id, $task->source_id);
    }

    public function test_create_holiday_from_task_prevents_duplicate(): void
    {
        $market = Market::create([
            'name' => 'Рынок для теста дублирования',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $user = User::factory()->create(['market_id' => $market->id]);
        $role = Role::findOrCreate('market-admin');
        $user->assignRole($role);

        $holiday = MarketHoliday::create([
            'market_id' => $market->id,
            'title' => 'Существующее событие',
            'starts_at' => now()->addDays(5),
        ]);

        $task = Task::create([
            'market_id' => $market->id,
            'title' => 'Задача для события',
            'status' => Task::STATUS_NEW,
            'priority' => Task::PRIORITY_NORMAL,
            'source_type' => MarketHoliday::class,
            'source_id' => $holiday->id,
        ]);

        $service = new TaskHolidayLinkService();
        $result = $service->createHolidayFromTask($task);

        $this->assertFalse($result['success']);
        $this->assertEquals('Связанное событие уже существует', $result['message']);

        // Проверка что дубль не создан
        $this->assertEquals(1, MarketHoliday::count());
        $this->assertNull(MarketHolidayTaskLink::where('task_id', $task->id)->where('market_holiday_id', '!=', $holiday->id)->first());
    }

    public function test_link_task_to_holiday_via_service(): void
    {
        $market = Market::create([
            'name' => 'Рынок для теста связи',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $user = User::factory()->create(['market_id' => $market->id]);
        $role = Role::findOrCreate('market-admin');
        $user->assignRole($role);

        $holiday = MarketHoliday::create([
            'market_id' => $market->id,
            'title' => 'Событие для связи',
            'starts_at' => now()->addDays(5),
        ]);

        $task = Task::create([
            'market_id' => $market->id,
            'title' => 'Задача для связывания',
            'status' => Task::STATUS_NEW,
            'priority' => Task::PRIORITY_NORMAL,
        ]);

        $service = new TaskHolidayLinkService();
        $result = $service->linkTaskToHoliday($task, $holiday);

        $this->assertTrue($result['success']);
        $this->assertNotNull($result['link']);

        // Проверка связи
        $this->assertDatabaseHas('market_holiday_task_links', [
            'market_id' => $market->id,
            'task_id' => $task->id,
            'market_holiday_id' => $holiday->id,
        ]);

        // Проверка что source_type/source_id заполнились
        $task->refresh();
        $this->assertEquals(MarketHoliday::class, $task->source_type);
        $this->assertEquals($holiday->id, $task->source_id);
    }

    public function test_link_task_to_holiday_prevents_duplicate_link(): void
    {
        $market = Market::create([
            'name' => 'Рынок для теста дублирования связи',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $user = User::factory()->create(['market_id' => $market->id]);
        $role = Role::findOrCreate('market-admin');
        $user->assignRole($role);

        $holiday = MarketHoliday::create([
            'market_id' => $market->id,
            'title' => 'Событие',
            'starts_at' => now()->addDays(5),
        ]);

        $task = Task::create([
            'market_id' => $market->id,
            'title' => 'Задача',
            'status' => Task::STATUS_NEW,
            'priority' => Task::PRIORITY_NORMAL,
        ]);

        // Создаём связь
        MarketHolidayTaskLink::create([
            'market_id' => $market->id,
            'market_holiday_id' => $holiday->id,
            'task_id' => $task->id,
            'scenario_key' => 'test_key',
        ]);

        $service = new TaskHolidayLinkService();
        $result = $service->linkTaskToHoliday($task, $holiday);

        $this->assertFalse($result['success']);
        $this->assertEquals('Связь уже существует', $result['message']);
    }

    public function test_create_holiday_from_task_button_visible_when_no_link(): void
    {
        $market = Market::create([
            'name' => 'Рынок для теста кнопки создания',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $user = User::factory()->create(['market_id' => $market->id]);
        $role = Role::findOrCreate('market-admin');
        $user->assignRole($role);

        $task = Task::create([
            'market_id' => $market->id,
            'title' => 'Провести встречу с арендаторами',
            'description' => 'Обсудить план мероприятий на квартал.',
            'status' => Task::STATUS_NEW,
            'priority' => Task::PRIORITY_NORMAL,
            'due_at' => now()->addDays(7),
        ]);

        // Проверка что задача не связана с событием
        $linkedHoliday = $task->linkedMarketHoliday();
        $this->assertNull($linkedHoliday, 'Задача не должна быть связана со событием');
    }

    public function test_create_holiday_from_task_button_hidden_when_linked(): void
    {
        $market = Market::create([
            'name' => 'Рынок для теста скрытой кнопки',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $user = User::factory()->create(['market_id' => $market->id]);
        $role = Role::findOrCreate('market-admin');
        $user->assignRole($role);

        $holiday = MarketHoliday::create([
            'market_id' => $market->id,
            'title' => 'Существующее событие',
            'starts_at' => now()->addDays(5),
        ]);

        $task = Task::create([
            'market_id' => $market->id,
            'title' => 'Задача для события',
            'status' => Task::STATUS_NEW,
            'priority' => Task::PRIORITY_NORMAL,
            'source_type' => MarketHoliday::class,
            'source_id' => $holiday->id,
        ]);

        // Проверка что задача связана со событием
        $linkedHoliday = $task->linkedMarketHoliday();
        $this->assertNotNull($linkedHoliday, 'Задача должна быть связана со событием');
        $this->assertEquals($holiday->id, $linkedHoliday->id);
    }

    public function test_market_holiday_edit_page_shows_linked_tasks_section(): void
    {
        $market = Market::create([
            'name' => 'Рынок для теста UI события',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $user = User::factory()->create(['market_id' => $market->id]);
        $role = Role::findOrCreate('market-admin');
        $user->assignRole($role);

        $holiday = MarketHoliday::create([
            'market_id' => $market->id,
            'title' => 'Событие для UI теста',
            'starts_at' => now()->addDays(5),
        ]);

        $task = Task::create([
            'market_id' => $market->id,
            'title' => 'Задача для связи со событием',
            'status' => Task::STATUS_NEW,
            'priority' => Task::PRIORITY_NORMAL,
        ]);

        // Создаём связь
        MarketHolidayTaskLink::create([
            'market_id' => $market->id,
            'market_holiday_id' => $holiday->id,
            'task_id' => $task->id,
            'scenario_key' => 'test_scenario_key',
        ]);

        // GET edit-страница события должна возвращать 200
        $response = $this->actingAs($user)
            ->get(route('filament.admin.resources.market-holidays.edit', ['record' => $holiday]));

        $response->assertOk();

        $html = $response->getContent();

        // Проверка, что секция и связанная задача реально попали в HTML edit-страницы.
        $this->assertStringContainsString('Связанные задачи', $html);
        $this->assertStringContainsString('Задача для связи со событием', $html);
        $this->assertStringContainsString('Открыть задачу', $html);
    }

    public function test_create_holiday_from_task_does_not_overwrite_existing_source(): void
    {
        $market = Market::create([
            'name' => 'Рынок для теста сохранения источника',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $ticket = Ticket::create([
            'market_id' => $market->id,
            'subject' => 'Источник задачи',
            'description' => 'Задача уже создана из заявки.',
            'category' => 'maintenance',
            'priority' => 'normal',
            'status' => 'new',
        ]);

        $task = Task::create([
            'market_id' => $market->id,
            'title' => 'Задача с существующим источником',
            'description' => 'Нужно добавить событие, но не терять исходную заявку.',
            'status' => Task::STATUS_NEW,
            'priority' => Task::PRIORITY_NORMAL,
            'due_at' => now()->addDays(3),
            'source_type' => Ticket::class,
            'source_id' => $ticket->id,
        ]);

        $service = new TaskHolidayLinkService();
        $result = $service->createHolidayFromTask($task);

        $this->assertTrue($result['success']);

        $task->refresh();

        $this->assertEquals(Ticket::class, $task->source_type);
        $this->assertEquals($ticket->id, $task->source_id);
        $this->assertDatabaseHas('market_holiday_task_links', [
            'market_id' => $market->id,
            'task_id' => $task->id,
            'market_holiday_id' => $result['holiday']->id,
        ]);
    }

    public function test_link_task_to_holiday_rejects_cross_market_link(): void
    {
        $marketA = Market::create([
            'name' => 'Рынок A для связи',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $marketB = Market::create([
            'name' => 'Рынок B для связи',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $holiday = MarketHoliday::create([
            'market_id' => $marketB->id,
            'title' => 'Событие другого рынка',
            'starts_at' => now()->addDays(5),
        ]);

        $task = Task::create([
            'market_id' => $marketA->id,
            'title' => 'Задача другого рынка',
            'status' => Task::STATUS_NEW,
            'priority' => Task::PRIORITY_NORMAL,
        ]);

        $service = new TaskHolidayLinkService();
        $result = $service->linkTaskToHoliday($task, $holiday);

        $this->assertFalse($result['success']);
        $this->assertEquals('Задача и событие относятся к разным рынкам', $result['message']);
        $this->assertDatabaseMissing('market_holiday_task_links', [
            'task_id' => $task->id,
            'market_holiday_id' => $holiday->id,
        ]);
    }

    public function test_unlink_task_from_holiday_preserves_unrelated_market_holiday_source(): void
    {
        $market = Market::create([
            'name' => 'Рынок для теста удаления связи',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $sourceHoliday = MarketHoliday::create([
            'market_id' => $market->id,
            'title' => 'Исходное событие задачи',
            'starts_at' => now()->addDays(2),
        ]);

        $linkedHoliday = MarketHoliday::create([
            'market_id' => $market->id,
            'title' => 'Удаляемая ручная связь',
            'starts_at' => now()->addDays(5),
        ]);

        $task = Task::create([
            'market_id' => $market->id,
            'title' => 'Задача со сторонним source-событием',
            'status' => Task::STATUS_NEW,
            'priority' => Task::PRIORITY_NORMAL,
            'source_type' => MarketHoliday::class,
            'source_id' => $sourceHoliday->id,
        ]);

        MarketHolidayTaskLink::create([
            'market_id' => $market->id,
            'market_holiday_id' => $linkedHoliday->id,
            'task_id' => $task->id,
            'scenario_key' => 'manual_task_' . $task->id,
        ]);

        $service = new TaskHolidayLinkService();
        $result = $service->unlinkTaskFromHoliday($task);

        $this->assertTrue($result['success']);
        $this->assertDatabaseMissing('market_holiday_task_links', [
            'task_id' => $task->id,
        ]);

        $task->refresh();

        $this->assertEquals(MarketHoliday::class, $task->source_type);
        $this->assertEquals($sourceHoliday->id, $task->source_id);
    }
}
