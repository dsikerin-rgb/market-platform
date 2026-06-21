<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\User;
use App\Services\Ai\AiConsultantService;
use ReflectionMethod;
use Tests\TestCase;

class AiConsultantSystemPromptTest extends TestCase
{
    public function test_broad_work_question_keeps_onboarding_optional(): void
    {
        $method = new ReflectionMethod(AiConsultantService::class, 'systemPrompt');
        $method->setAccessible(true);

        $prompt = $method->invoke(new AiConsultantService, [
            'system_prompt' => 'Базовый промпт.',
            'read_only_sql_enabled' => false,
            'business_tools_enabled' => false,
            'action_tools_enabled' => false,
        ], 1, new User(['name' => 'Super Admin']), [
            'missing_fields' => ['job_title', 'responsibility_scope'],
            'onboarding_questions' => ['Какая у вас роль на рынке?'],
        ]);

        $this->assertStringContainsString('чем займёмся', $prompt);
        $this->assertStringContainsString('сначала предложи несколько полезных рабочих направлений', $prompt);
        $this->assertStringContainsString('Короткое знакомство можно предложить как один дополнительный вариант', $prompt);
        $this->assertStringContainsString('не делай его единственным или обязательным следующим шагом', $prompt);
    }

    public function test_agent_knowledge_status_is_respected_in_prompt(): void
    {
        $method = new ReflectionMethod(AiConsultantService::class, 'systemPrompt');
        $method->setAccessible(true);

        $prompt = $method->invoke(new AiConsultantService, [
            'system_prompt' => 'Базовый промпт.',
            'read_only_sql_enabled' => false,
            'business_tools_enabled' => false,
            'action_tools_enabled' => false,
        ], 1, new User(['name' => 'Super Admin']), []);

        $this->assertStringContainsString('Учитывай status, confidence и source_authority', $prompt);
        $this->assertStringContainsString('approved можно использовать уверенно', $prompt);
        $this->assertStringContainsString('draft формулируй осторожно', $prompt);
        $this->assertStringContainsString('rejected и stale не используй как основание для ответа', $prompt);
    }
}
