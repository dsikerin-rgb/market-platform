<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\TaskResource;
use App\Models\Market;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\TaskParticipant;
use App\Models\User;
use App\Notifications\StaffAddedNotification;
use App\Notifications\StaffMessageNotification;
use App\Notifications\TaskCommentAddedNotification;
use App\Notifications\TaskParticipantAssignedNotification;
use App\Notifications\TaskStatusChangedNotification;
use App\Notifications\TaskUpdatedNotification;
use App\Support\StaffConversationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use ReflectionMethod;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StaffAndTaskEmailNotificationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_message_notification_uses_mail_by_default(): void
    {
        Notification::fake();

        $market = $this->createMarket();
        [$author, $recipient] = $this->createStaffPair($market);

        app(StaffConversationService::class)->startConversation(
            $author,
            $recipient,
            'Проверка смены',
            'Посмотри, пожалуйста, новое сообщение.',
        );

        Notification::assertSentTo(
            $recipient,
            StaffMessageNotification::class,
            fn (StaffMessageNotification $notification): bool => in_array('mail', $notification->via($recipient), true)
        );
    }

    public function test_staff_added_notification_uses_mail_by_default(): void
    {
        $market = $this->createMarket();
        [$actor, $staff] = $this->createStaffPair($market);

        $notification = new StaffAddedNotification($staff, $actor);

        $this->assertContains('mail', $notification->via($staff));
    }

    public function test_task_status_change_notifies_task_participants_by_mail(): void
    {
        Notification::fake();

        $market = $this->createMarket();
        [$actor, $assignee] = $this->createStaffPair($market);
        $coexecutor = User::factory()->create([
            'market_id' => (int) $market->id,
            'email' => 'coexecutor@example.test',
        ]);
        $observer = User::factory()->create([
            'market_id' => (int) $market->id,
            'email' => 'observer@example.test',
        ]);

        $task = Task::query()->create([
            'market_id' => (int) $market->id,
            'title' => 'Проверить уведомления',
            'status' => Task::STATUS_NEW,
            'priority' => Task::PRIORITY_NORMAL,
            'created_by_user_id' => (int) $actor->id,
            'created_by' => (int) $actor->id,
            'assignee_id' => (int) $assignee->id,
        ]);

        $this->syncParticipants($task, [(int) $observer->id], [(int) $coexecutor->id]);
        Notification::fake();

        $this->actingAs($actor);
        $task->forceFill(['status' => Task::STATUS_IN_PROGRESS])->save();

        foreach ([$assignee, $coexecutor, $observer] as $recipient) {
            Notification::assertSentTo(
                $recipient,
                TaskStatusChangedNotification::class,
                fn (TaskStatusChangedNotification $notification): bool => in_array('mail', $notification->via($recipient), true)
            );
        }

        Notification::assertNotSentTo($actor, TaskStatusChangedNotification::class);
    }

    public function test_task_participant_assignment_notifies_new_participants_by_mail(): void
    {
        Notification::fake();

        $market = $this->createMarket();
        [$actor, $assignee] = $this->createStaffPair($market);
        $coexecutor = User::factory()->create([
            'market_id' => (int) $market->id,
            'email' => 'new-coexecutor@example.test',
        ]);
        $observer = User::factory()->create([
            'market_id' => (int) $market->id,
            'email' => 'new-observer@example.test',
        ]);

        $task = Task::query()->create([
            'market_id' => (int) $market->id,
            'title' => 'Назначить участников',
            'status' => Task::STATUS_NEW,
            'priority' => Task::PRIORITY_NORMAL,
            'created_by_user_id' => (int) $actor->id,
            'created_by' => (int) $actor->id,
            'assignee_id' => (int) $assignee->id,
        ]);

        Notification::fake();
        $this->actingAs($actor);

        $this->syncParticipants($task, [(int) $observer->id], [(int) $coexecutor->id]);

        foreach ([$coexecutor, $observer] as $recipient) {
            Notification::assertSentTo(
                $recipient,
                TaskParticipantAssignedNotification::class,
                fn (TaskParticipantAssignedNotification $notification): bool => in_array('mail', $notification->via($recipient), true)
            );
        }

        Notification::assertNotSentTo($assignee, TaskParticipantAssignedNotification::class);
    }

    public function test_direct_task_participant_assignment_notifies_by_mail(): void
    {
        Notification::fake();

        $market = $this->createMarket();
        [$actor, $assignee] = $this->createStaffPair($market);
        $coexecutor = User::factory()->create([
            'market_id' => (int) $market->id,
            'email' => 'direct-coexecutor@example.test',
        ]);

        $task = Task::query()->create([
            'market_id' => (int) $market->id,
            'title' => 'Назначить участника напрямую',
            'status' => Task::STATUS_NEW,
            'priority' => Task::PRIORITY_NORMAL,
            'created_by_user_id' => (int) $actor->id,
            'created_by' => (int) $actor->id,
            'assignee_id' => (int) $assignee->id,
        ]);

        Notification::fake();
        $this->actingAs($actor);

        TaskParticipant::query()->create([
            'task_id' => (int) $task->id,
            'user_id' => (int) $coexecutor->id,
            'role' => Task::PARTICIPANT_ROLE_COEXECUTOR,
        ]);

        Notification::assertSentTo(
            $coexecutor,
            TaskParticipantAssignedNotification::class,
            fn (TaskParticipantAssignedNotification $notification): bool => in_array('mail', $notification->via($coexecutor), true)
        );
    }

    public function test_task_comment_notifies_task_participants_by_mail(): void
    {
        Notification::fake();

        $market = $this->createMarket();
        [$actor, $assignee] = $this->createStaffPair($market);
        $observer = User::factory()->create([
            'market_id' => (int) $market->id,
            'email' => 'comment-observer@example.test',
        ]);

        $task = Task::query()->create([
            'market_id' => (int) $market->id,
            'title' => 'Проверить комментарии',
            'status' => Task::STATUS_NEW,
            'priority' => Task::PRIORITY_NORMAL,
            'created_by_user_id' => (int) $actor->id,
            'created_by' => (int) $actor->id,
            'assignee_id' => (int) $assignee->id,
        ]);

        $this->syncParticipants($task, [(int) $observer->id], []);
        Notification::fake();

        TaskComment::query()->create([
            'task_id' => (int) $task->id,
            'author_user_id' => (int) $actor->id,
            'body' => 'Есть новый комментарий по задаче.',
        ]);

        foreach ([$assignee, $observer] as $recipient) {
            Notification::assertSentTo(
                $recipient,
                TaskCommentAddedNotification::class,
                fn (TaskCommentAddedNotification $notification): bool => in_array('mail', $notification->via($recipient), true)
            );
        }

        Notification::assertNotSentTo($actor, TaskCommentAddedNotification::class);
    }

    public function test_task_data_change_notifies_task_participants_by_mail(): void
    {
        Notification::fake();

        $market = $this->createMarket();
        [$actor, $assignee] = $this->createStaffPair($market);
        $observer = User::factory()->create([
            'market_id' => (int) $market->id,
            'email' => 'update-observer@example.test',
        ]);

        $task = Task::query()->create([
            'market_id' => (int) $market->id,
            'title' => 'Проверить изменения',
            'status' => Task::STATUS_NEW,
            'priority' => Task::PRIORITY_NORMAL,
            'created_by_user_id' => (int) $actor->id,
            'created_by' => (int) $actor->id,
            'assignee_id' => (int) $assignee->id,
        ]);

        $this->syncParticipants($task, [(int) $observer->id], []);
        Notification::fake();

        $this->actingAs($actor);
        $task->forceFill(['priority' => Task::PRIORITY_HIGH])->save();

        foreach ([$assignee, $observer] as $recipient) {
            Notification::assertSentTo(
                $recipient,
                TaskUpdatedNotification::class,
                fn (TaskUpdatedNotification $notification): bool => in_array('mail', $notification->via($recipient), true)
            );
        }

        Notification::assertNotSentTo($actor, TaskUpdatedNotification::class);
    }

    private function createMarket(): Market
    {
        return Market::query()->create([
            'name' => 'Test market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);
    }

    /**
     * @return array{0: User, 1: User}
     */
    private function createStaffPair(Market $market): array
    {
        Role::findOrCreate('staff', 'web');

        $author = User::factory()->create([
            'market_id' => (int) $market->id,
            'email' => 'author@example.test',
        ]);
        $author->assignRole('staff');

        $recipient = User::factory()->create([
            'market_id' => (int) $market->id,
            'email' => 'recipient@example.test',
        ]);
        $recipient->assignRole('staff');

        return [$author, $recipient];
    }

    private function syncParticipants(Task $task, array $observerIds, array $coexecutorIds): void
    {
        $method = new ReflectionMethod(TaskResource::class, 'syncParticipantsByRole');
        $method->setAccessible(true);
        $method->invoke(null, $task, $observerIds, $coexecutorIds);
    }
}
