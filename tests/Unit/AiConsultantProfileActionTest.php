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
}
