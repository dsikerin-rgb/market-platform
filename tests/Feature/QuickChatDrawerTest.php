<?php

// tests/Feature/QuickChatDrawerTest.php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Admin\QuickChatDrawer;
use App\Models\AiConversation;
use App\Models\AiKnowledgeEntry;
use App\Models\AiMessage;
use App\Models\AiUserProfile;
use App\Models\Market;
use App\Models\MarketHoliday;
use App\Models\StaffConversation;
use App\Models\StaffConversationMessage;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TicketChatNotification;
use App\Services\Ai\AiAgentActionTool;
use App\Services\Ai\AiConsultantService;
use App\Support\StaffConversationService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class QuickChatDrawerTest extends TestCase
{
    use RefreshDatabase;

    public function test_regular_requests_page_ticket_query_does_not_auto_open_drawer(): void
    {
        $market = Market::query()->create([
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $this->actingAsMarketAdmin($market);

        $ticket = Ticket::query()->create([
            'market_id' => (int) $market->id,
            'subject' => 'Tenant request',
            'description' => 'Tenant request body',
            'category' => 'other',
            'priority' => 'normal',
            'status' => 'new',
        ]);

        Livewire::withQueryParams([
            'channel' => 'tenants',
            'ticket_id' => (int) $ticket->id,
        ])
            ->test(QuickChatDrawer::class)
            ->assertSet('isOpen', false)
            ->assertSet('selectedType', null)
            ->assertSet('selectedId', null);
    }

    public function test_explicit_quick_chat_ticket_query_auto_opens_drawer(): void
    {
        $market = Market::query()->create([
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $this->actingAsMarketAdmin($market);

        $ticket = Ticket::query()->create([
            'market_id' => (int) $market->id,
            'subject' => 'Tenant request',
            'description' => 'Tenant request body',
            'category' => 'other',
            'priority' => 'normal',
            'status' => 'new',
        ]);

        Livewire::withQueryParams([
            'quick_chat' => 'ticket',
            'channel' => 'tenants',
            'ticket_id' => (int) $ticket->id,
        ])
            ->test(QuickChatDrawer::class)
            ->assertSet('isOpen', true)
            ->assertSet('selectedType', 'ticket')
            ->assertSet('selectedId', (int) $ticket->id);
    }

    public function test_explicit_quick_chat_ai_query_auto_opens_consultant(): void
    {
        $market = Market::query()->create([
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $user = $this->actingAsMarketAdmin($market);

        Livewire::withQueryParams([
            'quick_chat' => 'ai',
        ])
            ->test(QuickChatDrawer::class)
            ->assertSet('isOpen', true)
            ->assertSet('selectedType', 'ai')
            ->assertSet('selectedId', 1)
            ->assertSee('ИИ-консультант')
            ->assertSee('Чем помочь по этой странице')
            ->assertSee('Если хотите, могу за минуту познакомиться')
            ->assertSee('Давай познакомимся')
            ->assertSee('Потом');

        $message = AiMessage::query()
            ->where('role', AiMessage::ROLE_ASSISTANT)
            ->where('metadata->kind', 'greeting')
            ->firstOrFail();

        $this->assertStringContainsString('Чем помочь по этой странице', (string) $message->body);
        $this->assertStringContainsString('Если хотите, могу за минуту познакомиться', (string) $message->body);
        $this->assertSame(['Давай познакомимся', 'Потом'], $message->metadata['suggestions'] ?? null);

        $profile = AiUserProfile::query()->where('user_id', (int) $user->id)->firstOrFail();
        $this->assertNotEmpty($profile->facts['light_onboarding_offer_shown_at'] ?? null);
    }

    public function test_page_nudge_opens_ai_consultant_with_contextual_first_message(): void
    {
        $market = Market::query()->create([
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $user = $this->actingAsMarketAdmin($market);
        $user->forceFill(['name' => 'Иванов Алексей Петрович'])->save();

        $context = [
            'url' => 'https://market.example.test/admin/tenants/7/edit',
            'path' => '/admin/tenants/7/edit',
            'title' => 'Карточка арендатора',
            'heading' => 'Арендатор ОС8',
        ];

        app()->instance(AiConsultantService::class, new class extends AiConsultantService
        {
            public function answer(User $user, int $marketId, string $question, array $history = [], array $pageContext = []): array
            {
                return [
                    'answer' => 'Проверяю быстрый вопрос: '.$question,
                    'error_type' => null,
                    'chips' => [],
                ];
            }
        });

        $component = Livewire::test(QuickChatDrawer::class)
            ->call('openDrawer', 'ai', 1, 'page_nudge', $context)
            ->assertSet('isOpen', true)
            ->assertSet('selectedType', 'ai')
            ->assertSet('selectedId', 1)
            ->assertSee('Алексей, вижу, вы сейчас в карточке арендатора')
            ->assertSee('Давай коротко познакомимся')
            ->call('useAiSuggestion', 'Давай коротко познакомимся')
            ->assertSet('messageBody', '')
            ->assertSet('isAiReplyPending', true)
            ->assertSee('Давай коротко познакомимся')
            ->call('completeAiReply')
            ->assertSet('isAiReplyPending', false)
            ->assertSee('Проверяю быстрый вопрос: Давай коротко познакомимся');

        $conversation = AiConversation::query()->firstOrFail();
        $message = AiMessage::query()
            ->where('ai_conversation_id', (int) $conversation->id)
            ->where('role', AiMessage::ROLE_ASSISTANT)
            ->firstOrFail();

        $this->assertSame('page_nudge_greeting', $message->metadata['kind'] ?? null);
        $this->assertSame('Арендатор ОС8', $conversation->context_page_label);
        $this->assertSame(3, AiMessage::query()->count());

        $component->call('openDrawer', 'ai', 1, 'page_nudge', $context);

        $this->assertSame(3, AiMessage::query()->count());
    }

    public function test_page_nudge_uses_neutral_name_for_role_like_user_name(): void
    {
        $market = Market::query()->create([
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $user = $this->actingAsMarketAdmin($market);
        $user->forceFill(['name' => 'Super Admin'])->save();

        Livewire::test(QuickChatDrawer::class)
            ->call('openDrawer', 'ai', 1, 'page_nudge', [
                'url' => 'https://market.example.test/admin/tenants',
                'path' => '/admin/tenants',
                'title' => 'Арендаторы',
                'heading' => 'Арендаторы',
            ])
            ->assertSee('Коллега, вижу, вы сейчас в разделе арендаторов')
            ->assertDontSee('Super, вижу');
    }

    public function test_page_nudge_prioritizes_overdue_tasks_for_user(): void
    {
        $market = Market::query()->create([
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $user = $this->actingAsMarketAdmin($market);

        Task::query()->create([
            'market_id' => (int) $market->id,
            'title' => 'Просроченная проверка',
            'status' => Task::STATUS_IN_PROGRESS,
            'priority' => Task::PRIORITY_HIGH,
            'due_at' => now()->subDay(),
            'assignee_id' => (int) $user->id,
        ]);

        Livewire::test(QuickChatDrawer::class)
            ->call('openDrawer', 'ai', 1, 'page_nudge', [
                'url' => 'https://market.example.test/admin/tasks',
                'path' => '/admin/tasks',
                'title' => 'Задачи',
                'heading' => 'Задачи',
            ])
            ->assertSee('просроченных задач')
            ->assertSee('Покажи просроченные задачи');
    }

    public function test_page_nudge_does_not_repeat_rejected_topic(): void
    {
        $market = Market::query()->create([
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $user = $this->actingAsMarketAdmin($market);

        $conversation = AiConversation::query()->create([
            'market_id' => (int) $market->id,
            'user_id' => (int) $user->id,
            'title' => 'ИИ-консультант',
        ]);

        AiMessage::query()->create([
            'ai_conversation_id' => (int) $conversation->id,
            'role' => AiMessage::ROLE_ASSISTANT,
            'body' => 'Могу помочь с долгами арендаторов.',
            'metadata' => [
                'kind' => 'page_nudge_greeting',
                'priority_context' => ['topic' => 'debts'],
                'suggestions' => ['Покажи крупнейшие долги'],
            ],
        ]);

        AiMessage::query()->create([
            'ai_conversation_id' => (int) $conversation->id,
            'role' => AiMessage::ROLE_USER,
            'body' => 'Долги не моя компетенция, больше не предлагай.',
            'metadata' => [],
        ]);

        Task::query()->create([
            'market_id' => (int) $market->id,
            'title' => 'Просроченная проверка',
            'status' => Task::STATUS_IN_PROGRESS,
            'priority' => Task::PRIORITY_HIGH,
            'due_at' => now()->subDay(),
            'assignee_id' => (int) $user->id,
        ]);

        Livewire::test(QuickChatDrawer::class)
            ->call('openDrawer', 'ai', 1, 'page_nudge', [
                'url' => 'https://market.example.test/admin/tenants',
                'path' => '/admin/tenants',
                'title' => 'Арендаторы',
                'heading' => 'Арендаторы',
            ])
            ->assertDontSee('Покажи крупнейшие долги')
            ->assertSee('Покажи просроченные задачи');

        $this->assertDatabaseHas('ai_user_profiles', [
            'user_id' => (int) $user->id,
        ]);
    }

    public function test_ai_profile_learns_responsibility_for_mentioned_staff_member(): void
    {
        $market = Market::query()->create([
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $user = $this->actingAsMarketAdmin($market);

        User::factory()->create([
            'name' => 'Марина Николаевна',
            'market_id' => (int) $market->id,
            'tenant_id' => null,
            'email' => 'marina-ai-profile@example.test',
        ]);

        app()->instance(AiConsultantService::class, new class extends AiConsultantService
        {
            public function answer(User $user, int $marketId, string $question, array $history = [], array $pageContext = []): array
            {
                return [
                    'answer' => 'Запомнила.',
                    'error_type' => null,
                    'chips' => [],
                ];
            }
        });

        Livewire::test(QuickChatDrawer::class)
            ->call('openDrawer', 'ai', 1)
            ->set('messageBody', 'Долгами занимается Марина Николаевна.')
            ->call('sendMessage')
            ->assertSet('isAiReplyPending', true)
            ->call('completeAiReply')
            ->assertSet('isAiReplyPending', false)
            ->assertSee('Запомнила.');

        $this->assertDatabaseHas('ai_user_profiles', [
            'responsibility_scope' => 'долги и оплаты арендаторов',
        ]);

        $this->assertDatabaseHas('ai_knowledge_entries', [
            'market_id' => (int) $market->id,
            'dictionary' => 'responsibilities',
            'source_user_id' => (int) $user->id,
        ]);
    }

    public function test_ai_profile_remembers_preferred_user_name(): void
    {
        $market = Market::query()->create([
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $user = $this->actingAsMarketAdmin($market);

        Livewire::test(QuickChatDrawer::class)
            ->call('openDrawer', 'ai', 1)
            ->set('messageBody', 'Зови меня Саша')
            ->call('sendMessage')
            ->assertSet('isAiReplyPending', true)
            ->call('completeAiReply')
            ->assertSet('isAiReplyPending', false)
            ->assertSee('Хорошо, буду обращаться: Саша.');

        $this->assertDatabaseHas('ai_user_profiles', [
            'user_id' => (int) $user->id,
            'preferred_name' => 'Саша',
        ]);
    }

    public function test_ai_profile_onboarding_starts_as_short_scenario(): void
    {
        $market = Market::query()->create([
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $this->actingAsMarketAdmin($market);

        Livewire::test(QuickChatDrawer::class)
            ->call('openDrawer', 'ai', 1)
            ->set('messageBody', 'Давай коротко познакомимся')
            ->call('sendMessage')
            ->assertHasNoErrors()
            ->assertSet('isAiReplyPending', true)
            ->call('completeAiReply')
            ->assertSet('isAiReplyPending', false)
            ->assertSee('Давай познакомимся коротко')
            ->assertSee('Какая у вас роль')
            ->assertSee('покажу его перед сохранением');
    }

    public function test_ai_light_onboarding_offer_is_snoozed_after_later_reply(): void
    {
        $market = Market::query()->create([
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $user = $this->actingAsMarketAdmin($market);

        Livewire::test(QuickChatDrawer::class)
            ->call('openDrawer', 'ai', 1)
            ->call('useAiSuggestion', 'Потом')
            ->assertSet('isAiReplyPending', false)
            ->assertSee('Хорошо, не буду отвлекать');

        $profile = AiUserProfile::query()->where('user_id', (int) $user->id)->firstOrFail();
        $this->assertNotEmpty($profile->facts['light_onboarding_snoozed_until'] ?? null);
        $this->assertSame(2, AiMessage::query()->count());
    }

    public function test_ai_light_onboarding_offer_is_hidden_while_snoozed(): void
    {
        $market = Market::query()->create([
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $user = $this->actingAsMarketAdmin($market);

        AiUserProfile::query()->create([
            'user_id' => (int) $user->id,
            'market_id' => (int) $market->id,
            'facts' => [
                'light_onboarding_snoozed_until' => now()->addDays(7)->toDateTimeString(),
            ],
        ]);

        Livewire::test(QuickChatDrawer::class)
            ->call('openDrawer', 'ai', 1)
            ->assertSee('Чем помочь по этой странице')
            ->assertDontSee('Если хотите, могу за минуту познакомиться')
            ->assertDontSee('Давай познакомимся');
    }

    public function test_ai_consultant_dialog_accepts_question_and_renders_answer(): void
    {
        $market = Market::query()->create([
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $this->actingAsMarketAdmin($market);

        app()->instance(AiConsultantService::class, new class extends AiConsultantService
        {
            public function answer(User $user, int $marketId, string $question, array $history = [], array $pageContext = []): array
            {
                return [
                    'answer' => 'Ответ по базе для рынка #'.$marketId.': '.$question,
                    'error_type' => null,
                    'chips' => [],
                ];
            }
        });

        Livewire::test(QuickChatDrawer::class)
            ->call('openDrawer', 'ai', 1)
            ->assertSet('selectedType', 'ai')
            ->assertSee('База данных рынка')
            ->assertSee('Чем помочь по этой странице')
            ->set('messageBody', 'Что по месту ОС8 22?')
            ->call('sendMessage')
            ->assertHasNoErrors()
            ->assertSet('isAiReplyPending', true)
            ->assertSee('Что по месту ОС8 22?')
            ->call('completeAiReply')
            ->assertSet('isAiReplyPending', false)
            ->assertSee('Ответ по базе для рынка #'.(int) $market->id);
    }

    public function test_ai_consultant_dialog_persists_history_page_context_and_chips(): void
    {
        $market = Market::query()->create([
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $this->actingAsMarketAdmin($market);

        $fake = new class extends AiConsultantService
        {
            public array $lastHistory = [];

            public array $lastPageContext = [];

            public function answer(User $user, int $marketId, string $question, array $history = [], array $pageContext = []): array
            {
                $this->lastHistory = $history;
                $this->lastPageContext = $pageContext;

                return [
                    'answer' => 'Ответ с сохранённой ссылкой',
                    'error_type' => null,
                    'chips' => [
                        [
                            'label' => 'Открыть арендатора',
                            'url' => '/admin/tenants/7/edit',
                        ],
                    ],
                ];
            }
        };

        app()->instance(AiConsultantService::class, $fake);

        Livewire::test(QuickChatDrawer::class)
            ->call('updatePageContext', [
                'url' => 'https://market.example.test/admin/tenants/7/edit',
                'path' => '/admin/tenants/7/edit',
                'title' => 'Карточка арендатора',
                'heading' => 'Арендатор ОС8',
            ])
            ->call('openDrawer', 'ai', 1)
            ->set('messageBody', 'Что по этому арендатору?')
            ->call('sendMessage')
            ->assertHasNoErrors()
            ->assertSet('isAiReplyPending', true)
            ->call('completeAiReply')
            ->assertSet('isAiReplyPending', false)
            ->assertSee('Ответ с сохранённой ссылкой')
            ->assertSee('Открыть арендатора');

        $conversation = AiConversation::query()->firstOrFail();

        $this->assertSame((int) $market->id, (int) $conversation->market_id);
        $this->assertSame('https://market.example.test/admin/tenants/7/edit', $conversation->context_page_url);
        $this->assertSame('Арендатор ОС8', $conversation->context_page_label);
        $this->assertSame('/admin/tenants/7/edit', $fake->lastPageContext['path'] ?? null);

        $this->assertDatabaseHas('ai_messages', [
            'ai_conversation_id' => (int) $conversation->id,
            'role' => AiMessage::ROLE_USER,
            'body' => 'Что по этому арендатору?',
        ]);
        $this->assertDatabaseHas('ai_messages', [
            'ai_conversation_id' => (int) $conversation->id,
            'role' => AiMessage::ROLE_ASSISTANT,
            'body' => 'Ответ с сохранённой ссылкой',
        ]);

        Livewire::test(QuickChatDrawer::class)
            ->call('openDrawer', 'ai', 1)
            ->assertSee('Что по этому арендатору?')
            ->assertSee('Ответ с сохранённой ссылкой')
            ->assertSee('Открыть арендатора');

        $this->assertSame(
            1,
            AiMessage::query()
                ->where('role', AiMessage::ROLE_ASSISTANT)
                ->where('metadata->kind', 'greeting')
                ->count(),
        );
    }

    public function test_ai_consultant_requires_confirmation_before_mutating_action(): void
    {
        $market = Market::query()->create([
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $this->actingAsMarketAdmin($market);

        app()->instance(AiConsultantService::class, new class extends AiConsultantService
        {
            public function answer(User $user, int $marketId, string $question, array $history = [], array $pageContext = []): array
            {
                return [
                    'answer' => 'Подготовил задачу. Проверьте детали и подтвердите, чтобы я её создал.',
                    'error_type' => null,
                    'chips' => [],
                    'pending_action' => [
                        'status' => 'pending',
                        'tool' => 'create_task',
                        'payload' => [
                            'tool' => 'create_task',
                            'title' => 'Проверить витрину',
                            'description' => 'Проверить витрину после обращения арендатора.',
                            'due_at' => '2026-06-21 10:00',
                            'priority' => 'high',
                        ],
                        'title' => 'Новая задача',
                        'summary' => [
                            ['label' => 'Название', 'value' => 'Проверить витрину'],
                            ['label' => 'Описание', 'value' => 'Проверить витрину после обращения арендатора.'],
                            ['label' => 'Срок', 'value' => '2026-06-21 10:00'],
                        ],
                        'confirm_label' => 'Создать задачу',
                        'cancel_label' => 'Отменить',
                    ],
                ];
            }
        });

        $component = Livewire::test(QuickChatDrawer::class)
            ->call('openDrawer', 'ai', 1)
            ->set('messageBody', 'Создай задачу проверить витрину')
            ->call('sendMessage')
            ->assertHasNoErrors()
            ->assertSet('isAiReplyPending', true)
            ->call('completeAiReply')
            ->assertSet('isAiReplyPending', false)
            ->assertSee('Подготовил задачу')
            ->assertSee('Новая задача')
            ->assertSee('Создать задачу')
            ->assertSee('Проверить витрину');

        $this->assertDatabaseMissing('tasks', [
            'market_id' => (int) $market->id,
            'title' => 'Проверить витрину',
        ]);

        $message = AiMessage::query()
            ->where('role', AiMessage::ROLE_ASSISTANT)
            ->where('body', 'Подготовил задачу. Проверьте детали и подтвердите, чтобы я её создал.')
            ->firstOrFail();

        $component
            ->call('confirmAiAction', 'ai-message-'.(int) $message->id)
            ->assertHasNoErrors()
            ->assertSee('Выполнено')
            ->assertSee('Задача создана.')
            ->assertSee('Задача: Проверить витрину');

        $this->assertDatabaseHas('tasks', [
            'market_id' => (int) $market->id,
            'title' => 'Проверить витрину',
            'description' => 'Проверить витрину после обращения арендатора.',
            'priority' => Task::PRIORITY_HIGH,
        ]);

        $component
            ->call('confirmAiAction', 'ai-message-'.(int) $message->id)
            ->assertHasNoErrors();

        $this->assertSame(
            1,
            Task::query()
                ->where('market_id', (int) $market->id)
                ->where('title', 'Проверить витрину')
                ->count(),
        );

        $metadata = (array) $message->refresh()->metadata;

        $this->assertSame('confirmed', $metadata['pending_action']['status'] ?? null);
        $this->assertSame('Задача создана.', $metadata['pending_action']['result_message'] ?? null);
    }

    public function test_ai_profile_update_requires_confirmation_before_saving(): void
    {
        $market = Market::query()->create([
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $admin = $this->actingAsMarketAdmin($market);

        app()->instance(AiConsultantService::class, new class extends AiConsultantService
        {
            public function answer(User $user, int $marketId, string $question, array $history = [], array $pageContext = []): array
            {
                return [
                    'answer' => 'Подготовил обновление профиля. Проверьте, что всё верно, и подтвердите сохранение.',
                    'error_type' => null,
                    'chips' => [],
                    'pending_action' => [
                        'status' => 'pending',
                        'tool' => 'update_my_profile',
                        'payload' => [
                            'tool' => 'update_my_profile',
                            'job_title' => 'Управляющий',
                            'responsibility_scope' => 'работа с арендаторами и задачами рынка',
                            'phone' => '+7 913 000-00-00',
                        ],
                        'title' => 'Обновление профиля',
                        'summary' => [
                            ['label' => 'Должность', 'value' => 'Управляющий'],
                            ['label' => 'Зона ответственности', 'value' => 'работа с арендаторами и задачами рынка'],
                            ['label' => 'Телефон', 'value' => '+7 913 000-00-00'],
                        ],
                        'confirm_label' => 'Сохранить в профиль',
                        'cancel_label' => 'Отменить',
                    ],
                ];
            }
        });

        $component = Livewire::test(QuickChatDrawer::class)
            ->call('openDrawer', 'ai', 1)
            ->set('messageBody', 'Заполни мой профиль')
            ->call('sendMessage')
            ->assertHasNoErrors()
            ->assertSet('isAiReplyPending', true)
            ->call('completeAiReply')
            ->assertSet('isAiReplyPending', false)
            ->assertSee('Обновление профиля')
            ->assertSee('Сохранить в профиль')
            ->assertSee('Должность')
            ->assertSee('Зона ответственности');

        $this->assertDatabaseMissing('users', [
            'id' => (int) $admin->id,
            'job_title' => 'Управляющий',
        ]);

        $message = AiMessage::query()
            ->where('role', AiMessage::ROLE_ASSISTANT)
            ->where('body', 'Подготовил обновление профиля. Проверьте, что всё верно, и подтвердите сохранение.')
            ->firstOrFail();

        $component
            ->call('confirmAiAction', 'ai-message-'.(int) $message->id)
            ->assertHasNoErrors()
            ->assertSee('Обновила: должность, зона ответственности, телефон.');

        $this->assertDatabaseHas('users', [
            'id' => (int) $admin->id,
            'job_title' => 'Управляющий',
            'phone' => '+79130000000',
        ]);

        $this->assertDatabaseHas('ai_user_profiles', [
            'user_id' => (int) $admin->id,
            'responsibility_scope' => 'работа с арендаторами и задачами рынка',
        ]);

        $metadata = (array) $message->refresh()->metadata;

        $this->assertSame('confirmed', $metadata['pending_action']['status'] ?? null);
        $this->assertSame('Обновила: должность, зона ответственности, телефон.', $metadata['pending_action']['result_message'] ?? null);
    }

    public function test_ai_action_tool_creates_task_with_resource_chip(): void
    {
        $market = Market::query()->create([
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $admin = $this->actingAsMarketAdmin($market);

        $result = app(AiAgentActionTool::class)->run($admin, (int) $market->id, [
            'tool' => 'create_task',
            'title' => 'Проверить витрину',
            'description' => 'Проверить витрину после обращения арендатора.',
            'due_at' => '2026-06-21 10:00',
            'priority' => 'high',
        ]);

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertSame('Задача: Проверить витрину', $result['chips'][0]['label'] ?? null);
        $this->assertStringContainsString('/admin', $result['chips'][0]['url'] ?? '');

        $this->assertDatabaseHas('tasks', [
            'market_id' => (int) $market->id,
            'title' => 'Проверить витрину',
            'description' => 'Проверить витрину после обращения арендатора.',
            'priority' => Task::PRIORITY_HIGH,
            'created_by_user_id' => (int) $admin->id,
        ]);
    }

    public function test_ai_action_tool_creates_personal_reminder_without_duplicates(): void
    {
        $market = Market::query()->create([
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $admin = $this->actingAsMarketAdmin($market);

        $payload = [
            'tool' => 'create_reminder',
            'title' => 'Позвонить арендатору',
            'description' => 'Уточнить документы по договору.',
            'due_at' => '2026-06-21 15:30',
        ];

        $firstResult = app(AiAgentActionTool::class)->run($admin, (int) $market->id, $payload);
        $secondResult = app(AiAgentActionTool::class)->run($admin, (int) $market->id, $payload);

        $this->assertTrue($firstResult['ok'], $firstResult['message']);
        $this->assertSame('Напоминание создано.', $firstResult['message']);
        $this->assertSame('Напоминание: Позвонить арендатору', $firstResult['chips'][0]['label'] ?? null);
        $this->assertTrue((bool) ($firstResult['data']['created'] ?? false));

        $this->assertTrue($secondResult['ok'], $secondResult['message']);
        $this->assertSame('Такое напоминание уже есть.', $secondResult['message']);
        $this->assertSame('Напоминание: Позвонить арендатору', $secondResult['chips'][0]['label'] ?? null);
        $this->assertFalse((bool) ($secondResult['data']['created'] ?? true));

        $this->assertSame(
            1,
            Task::query()
                ->where('market_id', (int) $market->id)
                ->where('title', 'Позвонить арендатору')
                ->count(),
        );

        $task = Task::query()
            ->where('market_id', (int) $market->id)
            ->where('title', 'Позвонить арендатору')
            ->firstOrFail();

        $this->assertSame((int) $admin->id, (int) $task->assignee_id);
        $this->assertStringContainsString('Уточнить документы по договору.', (string) $task->description);
        $this->assertStringContainsString('Создано ИИ-агентом как напоминание.', (string) $task->description);
    }

    public function test_ai_action_tool_reuses_existing_event(): void
    {
        $market = Market::query()->create([
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $admin = $this->actingAsMarketAdmin($market);

        $payload = [
            'tool' => 'create_event',
            'title' => 'Санитарный день',
            'description' => 'Рынок закрыт для посетителей.',
            'starts_at' => '2026-06-24',
            'ends_at' => '2026-06-24',
            'all_day' => true,
        ];

        $firstResult = app(AiAgentActionTool::class)->run($admin, (int) $market->id, $payload);
        $secondResult = app(AiAgentActionTool::class)->run($admin, (int) $market->id, $payload);

        $this->assertTrue($firstResult['ok'], $firstResult['message']);
        $this->assertSame('Событие создано.', $firstResult['message']);
        $this->assertSame('Событие: Санитарный день', $firstResult['chips'][0]['label'] ?? null);
        $this->assertTrue((bool) ($firstResult['data']['created'] ?? false));

        $this->assertTrue($secondResult['ok'], $secondResult['message']);
        $this->assertSame('Такое событие уже есть.', $secondResult['message']);
        $this->assertSame('Событие: Санитарный день', $secondResult['chips'][0]['label'] ?? null);
        $this->assertFalse((bool) ($secondResult['data']['created'] ?? true));

        $this->assertSame(
            1,
            MarketHoliday::query()
                ->where('market_id', (int) $market->id)
                ->where('title', 'Санитарный день')
                ->count(),
        );
    }

    public function test_ai_action_tool_builds_tenant_chip_from_human_query(): void
    {
        $market = Market::query()->create([
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $admin = $this->actingAsMarketAdmin($market);

        $tenant = Tenant::query()->create([
            'market_id' => (int) $market->id,
            'name' => 'ИП Бабушка Мария Ивановна',
            'short_name' => 'Бабушка',
            'external_id' => 'tenant-ai-link-query-001',
            'is_active' => true,
        ]);

        $result = app(AiAgentActionTool::class)->run($admin, (int) $market->id, [
            'tool' => 'resource_link',
            'resource_type' => 'tenant',
            'query' => 'Бабушка',
        ]);

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertSame('Арендатор: Бабушка', $result['chips'][0]['label'] ?? null);
        $this->assertStringContainsString('/admin/tenants/'.(int) $tenant->id.'/edit', $result['chips'][0]['url'] ?? '');
        $this->assertStringNotContainsString('/view/', $result['chips'][0]['url'] ?? '');
    }

    public function test_ai_action_tool_saves_knowledge_with_source_authority(): void
    {
        $market = Market::query()->create([
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'name' => 'Обычный сотрудник',
            'market_id' => (int) $market->id,
            'tenant_id' => null,
            'email' => 'knowledge-source@example.test',
        ]);

        $result = app(AiAgentActionTool::class)->run($user, (int) $market->id, [
            'tool' => 'remember_knowledge',
            'dictionary' => 'people',
            'label' => 'Самоназванный руководитель',
            'subject' => 'роль сотрудника',
            'fact' => 'Я тут директор и самый главный.',
            'confidence' => 95,
        ]);

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertSame('нужно подтверждение', $result['data']['confidence_label'] ?? null);
        $this->assertSame(45, $result['data']['confidence'] ?? null);

        $entry = AiKnowledgeEntry::query()
            ->where('market_id', (int) $market->id)
            ->where('dictionary', 'people')
            ->firstOrFail();

        $value = (array) $entry->value;

        $this->assertSame('Самоназванный руководитель', $entry->label);
        $this->assertSame('Я тут директор и самый главный.', $value['fact'] ?? null);
        $this->assertStringContainsString('не подтверждено', (string) data_get($value, 'source_authority.reason'));
    }

    public function test_staff_conversations_are_grouped_by_counterparty(): void
    {
        $market = Market::query()->create([
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $admin = $this->actingAsMarketAdmin($market);
        $staff = User::factory()->create([
            'name' => 'Message Sender',
            'market_id' => (int) $market->id,
            'tenant_id' => null,
            'email' => 'message-sender-quick-chat@example.test',
        ]);

        $firstConversation = $this->createConversation($market, $staff, $admin, 'First topic', now()->subHour());
        StaffConversationMessage::query()->create([
            'staff_conversation_id' => (int) $firstConversation->id,
            'user_id' => (int) $staff->id,
            'body' => 'First body',
            'read_at' => null,
        ]);

        $secondConversation = $this->createConversation($market, $admin, $staff, 'Second topic', now());
        StaffConversationMessage::query()->create([
            'staff_conversation_id' => (int) $secondConversation->id,
            'user_id' => (int) $admin->id,
            'body' => 'Second body',
            'read_at' => now(),
        ]);

        Livewire::test(QuickChatDrawer::class)
            ->call('openDrawer')
            ->assertSee('Message Sender')
            ->assertDontSee('First topic')
            ->assertDontSee('Second topic')
            ->call('selectChat', 'staff', (int) $staff->id)
            ->assertSee('First body')
            ->assertSee('Second body');
    }

    public function test_staff_message_can_be_sent_with_attachment(): void
    {
        Storage::fake('public');

        $market = Market::query()->create([
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $admin = $this->actingAsMarketAdmin($market);
        $staff = User::factory()->create([
            'name' => 'Attachment Receiver',
            'market_id' => (int) $market->id,
            'tenant_id' => null,
            'email' => 'attachment-receiver-quick-chat@example.test',
        ]);

        $this->createConversation($market, $staff, $admin, 'Files', now());

        Livewire::test(QuickChatDrawer::class)
            ->call('openDrawer')
            ->call('selectChat', 'staff', (int) $staff->id)
            ->set('messageBody', '')
            ->set('messageAttachments', [
                UploadedFile::fake()->create('invoice.pdf', 12, 'application/pdf'),
            ])
            ->call('sendMessage')
            ->assertHasNoErrors()
            ->assertSee('invoice.pdf');

        $message = StaffConversationMessage::query()
            ->where('user_id', (int) $admin->id)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('', (string) $message->body);
        $this->assertIsArray($message->attachments);
        $this->assertSame('invoice.pdf', $message->attachments[0]['name'] ?? null);
    }

    public function test_staff_dialog_can_be_started_from_search(): void
    {
        $market = Market::query()->create([
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $admin = $this->actingAsMarketAdmin($market);
        $staff = User::factory()->create([
            'name' => 'Fresh Receiver',
            'market_id' => (int) $market->id,
            'tenant_id' => null,
            'email' => 'fresh-receiver-quick-chat@example.test',
        ]);

        Livewire::test(QuickChatDrawer::class)
            ->call('openDrawer')
            ->set('search', 'Fresh')
            ->assertSee('Fresh Receiver')
            ->assertSee('Новый диалог')
            ->call('selectChat', 'staff', (int) $staff->id)
            ->assertSee('Напишите первое сообщение, чтобы начать переписку.')
            ->set('messageBody', 'Первое сообщение')
            ->call('sendMessage')
            ->assertHasNoErrors()
            ->assertSee('Первое сообщение');

        $this->assertDatabaseHas('staff_conversations', [
            'market_id' => (int) $market->id,
            'created_by_user_id' => (int) $admin->id,
            'recipient_user_id' => (int) $staff->id,
        ]);

        $this->assertDatabaseHas('staff_conversation_messages', [
            'user_id' => (int) $admin->id,
            'body' => 'Первое сообщение',
        ]);
    }

    public function test_tenant_message_notifications_are_counted_and_marked_read(): void
    {
        $market = Market::query()->create([
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $admin = $this->actingAsMarketAdmin($market);
        $market->forceFill([
            'settings' => [
                'request_notification_recipient_user_ids' => [(int) $admin->id],
            ],
        ])->save();

        $ticket = Ticket::query()->create([
            'market_id' => (int) $market->id,
            'subject' => 'Tenant unread request',
            'description' => 'Tenant request body',
            'category' => 'other',
            'priority' => 'normal',
            'status' => 'new',
        ]);

        $notificationId = (string) Str::uuid();

        DB::table('notifications')->insert([
            'id' => $notificationId,
            'type' => TicketChatNotification::class,
            'notifiable_type' => User::class,
            'notifiable_id' => (int) $admin->id,
            'data' => json_encode([
                'ticket_id' => (int) $ticket->id,
                'market_id' => (int) $market->id,
                'event_type' => TicketChatNotification::EVENT_MESSAGE_CREATED,
                'title' => 'New chat message',
                'message' => 'Tenant wrote a message',
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'read_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Livewire::test(QuickChatDrawer::class)
            ->assertSeeHtml('<span class="quick-chat__badge">1</span>')
            ->call('openDrawer')
            ->assertSee('Tenant unread request')
            ->assertSeeHtml('<span class="quick-chat__count">1</span>')
            ->call('selectChat', 'ticket', (int) $ticket->id)
            ->assertHasNoErrors();

        $this->assertNotNull(DB::table('notifications')->where('id', $notificationId)->value('read_at'));
    }

    public function test_staff_conversation_service_reuses_existing_thread(): void
    {
        $market = Market::query()->create([
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $admin = $this->actingAsMarketAdmin($market);
        $staff = User::factory()->create([
            'name' => 'Existing Thread Peer',
            'market_id' => (int) $market->id,
            'tenant_id' => null,
            'email' => 'existing-thread-peer@example.test',
        ]);

        $conversation = $this->createConversation($market, $staff, $admin, 'Existing', now()->subHour());
        StaffConversationMessage::query()->create([
            'staff_conversation_id' => (int) $conversation->id,
            'user_id' => (int) $staff->id,
            'body' => 'Old message',
            'read_at' => null,
        ]);

        $reused = app(StaffConversationService::class)->startConversation(
            $admin,
            $staff,
            'New subject should not split',
            'New message in same thread',
        );

        $this->assertSame((int) $conversation->id, (int) $reused->id);
        $this->assertSame(1, StaffConversation::query()->count());
        $this->assertDatabaseHas('staff_conversation_messages', [
            'staff_conversation_id' => (int) $conversation->id,
            'user_id' => (int) $admin->id,
            'body' => 'New message in same thread',
        ]);
    }

    public function test_staff_search_can_mix_existing_chats_and_new_candidates(): void
    {
        $market = Market::query()->create([
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $admin = $this->actingAsMarketAdmin($market);
        $existingStaff = User::factory()->create([
            'name' => 'Existing Search Peer',
            'market_id' => (int) $market->id,
            'tenant_id' => null,
            'email' => 'existing-search-peer@example.test',
        ]);
        $newStaff = User::factory()->create([
            'name' => 'Fresh Search Peer',
            'market_id' => (int) $market->id,
            'tenant_id' => null,
            'email' => 'fresh-search-peer@example.test',
        ]);

        $this->createConversation($market, $admin, $existingStaff, 'Search topic', now());

        Livewire::test(QuickChatDrawer::class)
            ->call('openDrawer')
            ->set('search', 'Search Peer')
            ->assertSee('Existing Search Peer')
            ->assertSee('Fresh Search Peer')
            ->assertSee('Новый диалог')
            ->call('selectChat', 'staff', (int) $newStaff->id)
            ->assertSee('Напишите первое сообщение, чтобы начать переписку.');
    }

    public function test_super_admin_can_find_staff_dialog_candidate_without_selected_market(): void
    {
        $market = Market::query()->create([
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $this->actingAsSuperAdmin();
        Role::findOrCreate('market-admin', 'web');

        $staffCandidate = User::factory()->create([
            'name' => 'Searchable Staff Candidate',
            'market_id' => (int) $market->id,
            'tenant_id' => null,
            'email' => 'searchable-staff-candidate@example.test',
        ]);
        $staffCandidate->assignRole('market-admin');

        Livewire::test(QuickChatDrawer::class)
            ->call('openDrawer')
            ->set('search', 'Searchable')
            ->assertSee('Searchable Staff Candidate');
    }

    public function test_staff_can_start_staff_dialog_with_super_admin_without_market_id(): void
    {
        $market = Market::query()->create([
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $admin = $this->actingAsMarketAdmin($market);

        Role::findOrCreate('super-admin', 'web');

        $superAdmin = User::factory()->create([
            'name' => 'Internal Super Admin',
            'market_id' => null,
            'tenant_id' => null,
            'email' => 'internal-super-admin-qa@example.test',
        ]);
        $superAdmin->assignRole('super-admin');

        Livewire::test(QuickChatDrawer::class)
            ->call('openDrawer', 'staff', (int) $superAdmin->id)
            ->assertSet('isOpen', true)
            ->assertSet('selectedType', 'staff')
            ->assertSet('selectedId', (int) $superAdmin->id)
            ->assertSeeHtml('class="quick-chat__composer"')
            ->set('messageBody', 'Hello from staff')
            ->call('sendMessage')
            ->assertHasNoErrors()
            ->assertSee('Hello from staff');

        $this->assertDatabaseHas('staff_conversations', [
            'market_id' => (int) $market->id,
            'created_by_user_id' => (int) $admin->id,
            'recipient_user_id' => (int) $superAdmin->id,
        ]);

        $this->assertDatabaseHas('staff_conversation_messages', [
            'user_id' => (int) $admin->id,
            'body' => 'Hello from staff',
        ]);
    }

    public function test_staff_cannot_open_dialog_with_merchant_role(): void
    {
        $market = Market::query()->create([
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $this->actingAsMarketAdmin($market);

        Role::findOrCreate('merchant', 'web');

        $merchant = User::factory()->create([
            'name' => 'Blocked Merchant',
            'market_id' => (int) $market->id,
            'tenant_id' => null,
            'email' => 'blocked-merchant-qa@example.test',
        ]);
        $merchant->assignRole('merchant');

        Livewire::test(QuickChatDrawer::class)
            ->call('openDrawer', 'staff', (int) $merchant->id)
            ->assertSet('selectedType', null)
            ->assertSet('selectedId', null);
    }

    private function actingAsMarketAdmin(Market $market): User
    {
        Role::findOrCreate('market-admin', 'web');

        $user = User::factory()->create([
            'market_id' => (int) $market->id,
            'tenant_id' => null,
            'email' => 'quick-chat-admin-'.uniqid('', true).'@example.test',
        ]);
        $user->assignRole('market-admin');

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($user, Filament::getAuthGuard());

        return $user;
    }

    private function actingAsSuperAdmin(): User
    {
        Role::findOrCreate('super-admin', 'web');

        $user = User::factory()->create([
            'market_id' => null,
            'tenant_id' => null,
            'email' => 'quick-chat-super-admin-'.uniqid('', true).'@example.test',
        ]);
        $user->assignRole('super-admin');

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($user, Filament::getAuthGuard());

        return $user;
    }

    private function createConversation(
        Market $market,
        User $starter,
        User $recipient,
        string $subject,
        mixed $lastMessageAt,
    ): StaffConversation {
        return StaffConversation::query()->create([
            'market_id' => (int) $market->id,
            'created_by_user_id' => (int) $starter->id,
            'recipient_user_id' => (int) $recipient->id,
            'subject' => $subject,
            'last_message_at' => $lastMessageAt,
        ]);
    }
}
