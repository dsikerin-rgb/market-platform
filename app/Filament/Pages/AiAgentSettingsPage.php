<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\AiMessage;
use App\Models\AiKnowledgeEntry;
use App\Services\Ai\AiAgentSettings;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Str;

class AiAgentSettingsPage extends Page
{
    protected static ?string $title = 'Настройки ИИ-агента';

    protected static ?string $slug = 'ai-agent-settings';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.ai-agent-settings';

    /**
     * @var array<string, mixed>
     */
    public array $data = [];

    /**
     * @var list<array<string, mixed>>
     */
    public array $actionLog = [];

    /**
     * @var list<array<string, mixed>>
     */
    public array $knowledgeEntries = [];

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return (bool) $user && method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin();
    }

    public function mount(AiAgentSettings $settings): void
    {
        abort_unless(static::canAccess(), 403);

        $data = $settings->get();
        $data = $this->formatSettingsForForm($data);

        $this->form->fill($data);
        $this->actionLog = $this->loadActionLog();
        $this->knowledgeEntries = $this->loadKnowledgeEntries();
    }

    public function getHeading(): string|Htmlable|null
    {
        return null;
    }

    public function form(Schema $schema): Schema
    {
        $tabs = Tabs::make('ai_agent_settings_tabs')
            ->columnSpanFull();

        if (method_exists($tabs, 'persistTabInQueryString')) {
            $tabs->persistTabInQueryString();
        }

        return $schema
            ->statePath('data')
            ->components([
                $tabs->tabs([
                    Tab::make('Поведение')
                        ->schema([
                            Section::make('Поведение агента')
                                ->description('Эти настройки управляют тем, как ИИ-консультант отвечает сотрудникам в модалке "Диалоги".')
                                ->schema([
                                    Forms\Components\Toggle::make('enabled')
                                        ->label('Включить ИИ-консультанта')
                                        ->default(true),

                                    Forms\Components\Toggle::make('context_pack_enabled')
                                        ->label('Передавать краткий контекст рынка')
                                        ->helperText('Сводка по арендаторам, местам, договорам, обращениям и заметным проблемам.')
                                        ->default(true),

                                    Forms\Components\Toggle::make('page_context_enabled')
                                        ->label('Передавать текущую страницу')
                                        ->helperText('Агент будет видеть адрес и заголовок страницы, на которой сотрудник открыл диалог.')
                                        ->default(true),

                                    Forms\Components\Toggle::make('action_tools_enabled')
                                        ->label('Разрешить рабочие действия')
                                        ->helperText('Создание задач, событий, напоминаний, сообщений сотрудникам и обращений арендаторам через проверки приложения.')
                                        ->default(true),

                                    Forms\Components\Toggle::make('business_tools_enabled')
                                        ->label('Разрешить типовые проверки')
                                        ->helperText('Быстрые рабочие проверки: должники, ставки, свободные места, сводка по арендатору, обращения и договоры.')
                                        ->default(true),

                                    Forms\Components\Textarea::make('system_prompt')
                                        ->label('Системный промпт')
                                        ->rows(12)
                                        ->required()
                                        ->columnSpanFull(),
                                ])
                                ->columns(2),
                        ]),

                    Tab::make('Проверки')
                        ->schema([
                            Section::make('Самостоятельные проверки')
                                ->description('Агент может сам выполнять безопасные проверки данных. Приложение пропускает только проверки без изменения записей.')
                                ->schema([
                                    Forms\Components\Toggle::make('read_only_sql_enabled')
                                        ->label('Разрешить проверку данных без записи')
                                        ->helperText('Модель не получает пароль от базы. Приложение проверяет запрос и выполняет его в режиме без изменения данных.')
                                        ->default(true),

                                    Forms\Components\TextInput::make('max_tool_rounds')
                                        ->label('Максимум проверок за один вопрос')
                                        ->numeric()
                                        ->minValue(0)
                                        ->maxValue(6)
                                        ->step(1),

                                    Forms\Components\TextInput::make('sql_row_limit')
                                        ->label('Лимит строк результата')
                                        ->numeric()
                                        ->minValue(5)
                                        ->maxValue(200)
                                        ->step(5),

                                    Forms\Components\TextInput::make('sql_timeout_ms')
                                        ->label('Таймаут проверки')
                                        ->numeric()
                                        ->minValue(250)
                                        ->maxValue(10000)
                                        ->step(250)
                                        ->suffix('мс'),

                                    Forms\Components\Textarea::make('allowed_tables')
                                        ->label('Разделы данных для проверки')
                                        ->helperText('Одно служебное имя на строку. Все проверки также ограничиваются текущим рынком.')
                                        ->rows(8)
                                        ->columnSpanFull(),
                                ])
                                ->columns(2),
                        ]),

                    Tab::make('Права')
                        ->schema([
                            Section::make('Права по ролям')
                                ->description('Одна роль на строку. Эти правила применяются не только к интерфейсу, но и к серверной проверке действий агента.')
                                ->schema([
                                    Forms\Components\Textarea::make('roles_can_use_agent')
                                        ->label('Кто может открыть ИИ-консультанта')
                                        ->rows(5),

                                    Forms\Components\Textarea::make('roles_can_read_data')
                                        ->label('Кто может проверять данные рынка')
                                        ->rows(5),

                                    Forms\Components\Textarea::make('roles_can_prepare_tasks')
                                        ->label('Кто может создавать задачи и напоминания')
                                        ->rows(5),

                                    Forms\Components\Textarea::make('roles_can_prepare_events')
                                        ->label('Кто может создавать события')
                                        ->rows(5),

                                    Forms\Components\Textarea::make('roles_can_send_staff_messages')
                                        ->label('Кто может отправлять сообщения сотрудникам')
                                        ->rows(5),

                                    Forms\Components\Textarea::make('roles_can_send_tenant_messages')
                                        ->label('Кто может отправлять сообщения арендаторам')
                                        ->rows(5),
                                ])
                                ->columns(2),
                        ]),

                    Tab::make('Ответ')
                        ->schema([
                            Section::make('Параметры ответа')
                                ->schema([
                                    Forms\Components\TextInput::make('temperature')
                                        ->label('Свобода формулировок')
                                        ->numeric()
                                        ->minValue(0)
                                        ->maxValue(1)
                                        ->step(0.1)
                                        ->helperText('0-0.2: точнее и суше. Больше 0.4 обычно не нужно для рабочих ответов.'),

                                    Forms\Components\TextInput::make('max_tokens')
                                        ->label('Максимальная длина ответа')
                                        ->numeric()
                                        ->minValue(600)
                                        ->maxValue(6000)
                                        ->step(100),

                                    Forms\Components\TextInput::make('history_messages')
                                        ->label('Сообщений истории')
                                        ->numeric()
                                        ->minValue(0)
                                        ->maxValue(20)
                                        ->step(1)
                                        ->helperText('Помогает понимать продолжения вроде "проверь сам" или "а по этому месту?".'),

                                    Forms\Components\TextInput::make('history_budget_tokens')
                                        ->label('Лимит памяти диалога')
                                        ->numeric()
                                        ->minValue(300)
                                        ->maxValue(4000)
                                        ->step(100)
                                        ->helperText('Ограничивает, сколько прошлой переписки агент берет в новый ответ.'),

                                    Forms\Components\TextInput::make('context_budget_tokens')
                                        ->label('Лимит контекста')
                                        ->numeric()
                                        ->minValue(400)
                                        ->maxValue(8000)
                                        ->step(100)
                                        ->helperText('Ограничивает пакет данных по рынку и текущей странице, чтобы не тратить лишнее.'),

                                    Forms\Components\TextInput::make('context_item_limit')
                                        ->label('Записей в списках контекста')
                                        ->numeric()
                                        ->minValue(1)
                                        ->maxValue(20)
                                        ->step(1)
                                        ->helperText('Сколько арендаторов, мест, договоров и других записей передавать сразу.'),
                                ])
                                ->columns(3),
                        ]),

                    Tab::make('Журнал')
                        ->schema([
                            View::make('filament.pages.partials.ai-agent-action-log')
                                ->viewData(fn (): array => ['actionLog' => $this->actionLog])
                                ->columnSpanFull(),
                        ]),

                    Tab::make('Справочник')
                        ->visible(fn (): bool => $this->canViewAiResources())
                        ->schema([
                            View::make('filament.pages.partials.ai-agent-knowledge')
                                ->viewData(fn (): array => ['knowledgeEntries' => $this->knowledgeEntries])
                                ->columnSpanFull(),
                        ]),
                ]),
            ]);
    }

    public function save(AiAgentSettings $settings): void
    {
        abort_unless(static::canAccess(), 403);

        $settings->save($this->form->getState());
        $this->form->fill($this->formatSettingsForForm($settings->get()));
        $this->actionLog = $this->loadActionLog();
        $this->knowledgeEntries = $this->loadKnowledgeEntries();

        Notification::make()
            ->title('Настройки ИИ-агента сохранены')
            ->success()
            ->send();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function formatSettingsForForm(array $data): array
    {
        foreach ([
            'allowed_tables',
            'roles_can_use_agent',
            'roles_can_read_data',
            'roles_can_prepare_tasks',
            'roles_can_prepare_events',
            'roles_can_send_staff_messages',
            'roles_can_send_tenant_messages',
        ] as $key) {
            $data[$key] = implode("\n", (array) ($data[$key] ?? []));
        }

        return $data;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadActionLog(): array
    {
        return AiMessage::query()
            ->with(['conversation.user:id,name,email', 'conversation.market:id,name'])
            ->where('role', AiMessage::ROLE_ASSISTANT)
            ->latest()
            ->limit(200)
            ->get()
            ->filter(static fn (AiMessage $message): bool => is_array(data_get($message->metadata, 'pending_action')))
            ->take(50)
            ->map(function (AiMessage $message): array {
                $action = (array) data_get($message->metadata, 'pending_action', []);
                $status = strtolower(trim((string) ($action['status'] ?? 'pending')));
                if (! in_array($status, ['pending', 'running', 'confirmed', 'cancelled', 'failed'], true)) {
                    $status = 'pending';
                }

                return [
                    'created_at' => $this->formatActionLogDate($message->created_at),
                    'actor' => Str::limit(trim((string) ($message->conversation?->user?->name ?? 'Сотрудник')), 80, ''),
                    'market' => Str::limit(trim((string) ($message->conversation?->market?->name ?? 'Рынок')), 80, ''),
                    'title' => Str::limit(trim((string) ($action['title'] ?? 'Действие агента')), 120, ''),
                    'status' => $status,
                    'status_label' => $this->actionStatusLabel($status),
                    'summary' => $this->actionSummary((array) ($action['summary'] ?? [])),
                    'result_message' => Str::limit(trim((string) ($action['result_message'] ?? '')), 220, ''),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadKnowledgeEntries(): array
    {
        if (! $this->canViewAiResources()) {
            return [];
        }

        return AiKnowledgeEntry::query()
            ->with(['market:id,name', 'sourceUser:id,name,email'])
            ->latest('updated_at')
            ->limit(100)
            ->get()
            ->map(function (AiKnowledgeEntry $entry): array {
                $value = (array) ($entry->value ?? []);

                return [
                    'updated_at' => $this->formatActionLogDate($entry->updated_at),
                    'dictionary' => Str::limit((string) $entry->dictionary, 80, ''),
                    'label' => Str::limit((string) $entry->label, 160, ''),
                    'market' => Str::limit((string) ($entry->market?->name ?? 'Рынок'), 80, ''),
                    'source' => Str::limit((string) ($entry->sourceUser?->name ?? 'Не указан'), 80, ''),
                    'confidence' => (int) ($entry->confidence ?? 0),
                    'topic' => Str::limit((string) ($value['topic'] ?? ''), 120, ''),
                    'responsible' => Str::limit((string) ($value['responsible_name'] ?? ''), 120, ''),
                ];
            })
            ->all();
    }

    private function canViewAiResources(): bool
    {
        $user = Filament::auth()->user();

        return (bool) $user && method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin();
    }

    /**
     * @param  array<int, mixed>  $summary
     * @return list<array{label:string,value:string}>
     */
    private function actionSummary(array $summary): array
    {
        return collect($summary)
            ->filter(static fn (mixed $row): bool => is_array($row))
            ->map(static fn (array $row): array => [
                'label' => Str::limit(trim((string) ($row['label'] ?? '')), 80, ''),
                'value' => Str::limit(trim((string) ($row['value'] ?? '')), 180, ''),
            ])
            ->filter(static fn (array $row): bool => $row['label'] !== '' && $row['value'] !== '')
            ->values()
            ->all();
    }

    private function actionStatusLabel(string $status): string
    {
        return match ($status) {
            'running' => 'Выполняется',
            'confirmed' => 'Выполнено',
            'cancelled' => 'Отменено',
            'failed' => 'Не выполнено',
            default => 'Ожидает подтверждения',
        };
    }

    private function formatActionLogDate(mixed $value): string
    {
        if (! $value) {
            return '';
        }

        try {
            return $value->timezone(config('app.timezone'))->format('d.m.Y H:i');
        } catch (\Throwable) {
            return '';
        }
    }
}
