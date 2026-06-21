<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\User;
use App\Services\Ai\AiAgentActionTool;
use App\Services\Ai\AiKnowledgeService;
use Illuminate\Support\Collection;
use Tests\TestCase;

class AiKnowledgeServiceTest extends TestCase
{
    public function test_super_admin_is_high_authority_source(): void
    {
        $user = $this->userWithRoles(['super-admin']);

        $authority = app(AiKnowledgeService::class)->sourceAuthority(
            $user,
            null,
            'market_rules',
            'Правила работы с долгами утверждает администрация.',
        );

        $this->assertSame(95, $authority['score']);
        $this->assertSame('высокое доверие', $authority['label']);
    }

    public function test_unconfirmed_authority_claim_is_low_confidence(): void
    {
        $user = $this->userWithRoles(['market-marketer']);

        $authority = app(AiKnowledgeService::class)->sourceAuthority(
            $user,
            null,
            'people',
            'Я тут директор и самый главный.',
        );

        $this->assertSame(45, $authority['score']);
        $this->assertSame('нужно подтверждение', $authority['label']);
        $this->assertStringContainsString('не подтверждено', $authority['reason']);
    }

    public function test_knowledge_write_tool_is_not_advertised_as_read_only(): void
    {
        $tool = new AiAgentActionTool;

        $readOnlyHint = $tool->schemaHint(includeReadOnlyActions: true, includeMutatingActions: false);
        $mutatingHint = $tool->schemaHint(includeReadOnlyActions: false, includeMutatingActions: true);

        $this->assertStringNotContainsString('remember_knowledge', $readOnlyHint);
        $this->assertStringContainsString('remember_knowledge', $mutatingHint);
    }

    public function test_only_draft_and_approved_knowledge_is_visible_to_agent(): void
    {
        $this->assertSame([
            AiKnowledgeService::STATUS_DRAFT,
            AiKnowledgeService::STATUS_APPROVED,
        ], AiKnowledgeService::visibleStatuses());

        $this->assertNotContains(AiKnowledgeService::STATUS_REJECTED, AiKnowledgeService::visibleStatuses());
        $this->assertNotContains(AiKnowledgeService::STATUS_STALE, AiKnowledgeService::visibleStatuses());
    }

    public function test_knowledge_statuses_have_human_labels(): void
    {
        $this->assertSame('черновик', AiKnowledgeService::statusLabel(AiKnowledgeService::STATUS_DRAFT));
        $this->assertSame('подтверждено', AiKnowledgeService::statusLabel(AiKnowledgeService::STATUS_APPROVED));
        $this->assertSame('отклонено', AiKnowledgeService::statusLabel(AiKnowledgeService::STATUS_REJECTED));
        $this->assertSame('устарело', AiKnowledgeService::statusLabel(AiKnowledgeService::STATUS_STALE));
        $this->assertSame('черновик', AiKnowledgeService::statusLabel('unknown'));
    }

    /**
     * @param list<string> $roles
     */
    private function userWithRoles(array $roles): User
    {
        $user = new AiKnowledgeServiceTestUser;
        $user->rolesForTest = $roles;

        return $user;
    }
}

class AiKnowledgeServiceTestUser extends User
{
    /**
     * @var list<string>
     */
    public array $rolesForTest = [];

    public function isSuperAdmin(): bool
    {
        return in_array('super-admin', $this->rolesForTest, true);
    }

    public function isMarketAdmin(): bool
    {
        return in_array('market-admin', $this->rolesForTest, true);
    }

    public function getRoleNames(): Collection
    {
        return collect($this->rolesForTest);
    }
}
