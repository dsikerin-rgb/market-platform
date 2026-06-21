<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Ai\AiConsultantService;
use ReflectionMethod;
use Tests\TestCase;

class AiConsultantProfileActionTest extends TestCase
{
    public function test_profile_update_draft_uses_human_labels_and_preserves_lists(): void
    {
        $method = new ReflectionMethod(AiConsultantService::class, 'pendingActionDraft');
        $method->setAccessible(true);

        $draft = $method->invoke(new AiConsultantService, [
            'tool' => 'update_my_profile',
            'job_title' => 'Управляющий',
            'responsibility_scope' => 'работа с арендаторами',
            'regular_tasks' => ['обход рынка', 'контроль задач'],
            'notification_channels' => ['database', 'telegram'],
            'communication_status' => 'available',
            'unknown_internal_field' => 'не должно попасть в черновик',
        ]);

        $this->assertSame('pending', $draft['status']);
        $this->assertSame('update_my_profile', $draft['tool']);
        $this->assertSame('Обновление профиля', $draft['title']);
        $this->assertSame('Сохранить в профиль', $draft['confirm_label']);
        $this->assertArrayNotHasKey('unknown_internal_field', $draft['payload']);
        $this->assertSame(['обход рынка', 'контроль задач'], $draft['payload']['regular_tasks']);

        $summary = collect($draft['summary'])->pluck('value', 'label')->all();

        $this->assertSame('Управляющий', $summary['Должность'] ?? null);
        $this->assertSame('работа с арендаторами', $summary['Зона ответственности'] ?? null);
        $this->assertSame('обход рынка, контроль задач', $summary['Регулярные задачи'] ?? null);
        $this->assertSame('database, telegram', $summary['Каналы уведомлений'] ?? null);
        $this->assertSame('можно писать', $summary['Готовность к общению'] ?? null);
    }

    public function test_work_action_drafts_use_human_labels_for_reminders_and_events(): void
    {
        $method = new ReflectionMethod(AiConsultantService::class, 'pendingActionDraft');
        $method->setAccessible(true);

        $reminderDraft = $method->invoke(new AiConsultantService, [
            'tool' => 'create_reminder',
            'title' => 'Позвонить арендатору',
            'description' => 'Уточнить документы.',
            'due_at' => '2026-06-21 15:30',
        ]);

        $eventDraft = $method->invoke(new AiConsultantService, [
            'tool' => 'create_event',
            'title' => 'Санитарный день',
            'description' => 'Рынок закрыт для посетителей.',
            'starts_at' => '2026-06-24',
            'all_day' => true,
        ]);

        $reminderSummary = collect($reminderDraft['summary'])->pluck('value', 'label')->all();
        $eventSummary = collect($eventDraft['summary'])->pluck('value', 'label')->all();

        $this->assertSame('Новое напоминание', $reminderDraft['title']);
        $this->assertSame('Создать напоминание', $reminderDraft['confirm_label']);
        $this->assertSame('Позвонить арендатору', $reminderSummary['Напомнить'] ?? null);
        $this->assertSame('2026-06-21 15:30', $reminderSummary['Когда'] ?? null);
        $this->assertSame('мне', $reminderSummary['Кому'] ?? null);

        $this->assertSame('Новое событие', $eventDraft['title']);
        $this->assertSame('Создать событие', $eventDraft['confirm_label']);
        $this->assertSame('Санитарный день', $eventSummary['Событие'] ?? null);
        $this->assertSame('2026-06-24', $eventSummary['Дата начала'] ?? null);
        $this->assertSame('весь день', $eventSummary['Формат'] ?? null);
    }
}
