<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AiKnowledgeGovernanceViewTest extends TestCase
{
    public function test_ai_knowledge_view_keeps_super_admin_governance_actions(): void
    {
        $view = File::get(resource_path('views/filament/pages/partials/ai-agent-knowledge.blade.php'));

        $this->assertStringContainsString('wire:click="approveKnowledge', $view);
        $this->assertStringContainsString('wire:click="rejectKnowledge', $view);
        $this->assertStringContainsString('wire:click="markKnowledgeStale', $view);
        $this->assertStringContainsString('wire:click="editKnowledge', $view);
        $this->assertStringContainsString('wire:click="deleteKnowledge', $view);
        $this->assertStringContainsString('wire:model.defer="knowledgeEditData.label"', $view);
    }
}
