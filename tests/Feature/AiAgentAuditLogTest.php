<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiAgentAuditEvent;
use App\Models\User;
use App\Services\Ai\AiAgentActionTool;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AiAgentAuditLogTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);

        DB::purge('sqlite');
        DB::reconnect('sqlite');

        Schema::create('ai_agent_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('market_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('ai_conversation_id')->nullable();
            $table->unsignedBigInteger('ai_message_id')->nullable();
            $table->string('event_type', 64);
            $table->string('tool', 96)->nullable();
            $table->string('status', 32);
            $table->string('title')->nullable();
            $table->json('summary')->nullable();
            $table->json('request_payload')->nullable();
            $table->json('result_payload')->nullable();
            $table->text('result_message')->nullable();
            $table->json('chips')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('error_type', 64)->nullable();
            $table->timestamps();
        });
    }

    public function test_agent_action_tool_writes_audit_event(): void
    {
        $user = new AiAgentAuditLogTestUser;
        $user->id = 77;
        $user->market_id = 12;
        $user->tenant_id = 0;
        $user->exists = true;

        $result = app(AiAgentActionTool::class)->run($user, 12, [
            'tool' => 'unknown_test_tool',
        ]);

        $this->assertFalse((bool) $result['ok']);

        $event = AiAgentAuditEvent::query()->firstOrFail();
        $this->assertSame(12, (int) $event->market_id);
        $this->assertSame((int) $user->id, (int) $event->user_id);
        $this->assertSame('tool_call', $event->event_type);
        $this->assertSame('unknown_test_tool', $event->tool);
        $this->assertSame('failed', $event->status);
        $this->assertSame('Неизвестное действие агента.', $event->result_message);
    }

    public function test_action_log_view_describes_full_audit_not_only_pending_drafts(): void
    {
        $view = file_get_contents(resource_path('views/filament/pages/partials/ai-agent-action-log.blade.php'));

        $this->assertIsString($view);
        $this->assertStringContainsString('Здесь видны проверки, ссылки, подготовленные действия и результат выполнения', $view);
        $this->assertStringContainsString('actionLogFilters.search', $view);
        $this->assertStringContainsString('$row[\'conversation_preview\']', $view);
        $this->assertStringContainsString('Переписки ИИ-агента', $view);
        $this->assertStringContainsString('$conversationLog', $view);
        $this->assertStringContainsString('$selectedConversation', $view);
        $this->assertStringContainsString('selectConversation', $view);
        $this->assertStringContainsString('ai-action-log__conversation-browser', $view);
        $this->assertStringContainsString('body_html', $view);
        $this->assertStringContainsString('$row[\'event_label\']', $view);
        $this->assertStringContainsString('Событий агента пока нет', $view);
    }
}

class AiAgentAuditLogTestUser extends User
{
    public function isSuperAdmin(): bool
    {
        return true;
    }
}
