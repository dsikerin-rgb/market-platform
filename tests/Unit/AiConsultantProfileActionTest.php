<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Ai\AiConsultantService;
use App\Services\Ai\AiAgentActionTool;
use App\Models\User;
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
            'notification_topics' => ['messages', 'tasks'],
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
        $this->assertSame('В кабинете, Telegram', $summary['Каналы уведомлений'] ?? null);
        $this->assertSame('Сообщения, Назначения задач', $summary['Темы уведомлений'] ?? null);
        $this->assertSame('можно писать', $summary['Готовность к общению'] ?? null);
    }

    public function test_profile_and_notification_resource_links_use_human_chips(): void
    {
        $method = new ReflectionMethod(AiAgentActionTool::class, 'resourceLink');
        $method->setAccessible(true);
        $tool = new AiAgentActionTool;
        $user = new User;

        $profile = $method->invoke($tool, $user, 1, [
            'resource_type' => 'profile',
        ]);
        $notifications = $method->invoke($tool, $user, 1, [
            'resource_type' => 'notification_settings',
            'label' => 'Подключить Telegram',
        ]);

        $this->assertTrue($profile['ok']);
        $this->assertSame('Открыть профиль', $profile['chips'][0]['label'] ?? null);
        $this->assertSame('/admin/profile', $profile['chips'][0]['url'] ?? null);

        $this->assertTrue($notifications['ok']);
        $this->assertSame('Подключить Telegram', $notifications['chips'][0]['label'] ?? null);
        $this->assertSame('/admin/profile/notifications', $notifications['chips'][0]['url'] ?? null);
    }

    public function test_telegram_connect_link_falls_back_to_notification_settings_when_disabled(): void
    {
        config()->set('services.telegram.enabled', false);

        $method = new ReflectionMethod(AiAgentActionTool::class, 'telegramConnectLink');
        $method->setAccessible(true);

        $result = $method->invoke(new AiAgentActionTool, new User);

        $this->assertTrue($result['ok']);
        $this->assertSame('Telegram-подключение сейчас отключено в настройках сервиса.', $result['message']);
        $this->assertSame('Открыть уведомления', $result['chips'][0]['label'] ?? null);
        $this->assertSame('/admin/profile/notifications', $result['chips'][0]['url'] ?? null);
    }
}
