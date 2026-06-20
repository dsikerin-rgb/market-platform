<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Services\Ai\AiAgentSettings;
use App\Support\AdminCapabilities;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;

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

    public static function canAccess(): bool
    {
        return AdminCapabilities::canAccessMarketSettings(Filament::auth()->user());
    }

    public function mount(AiAgentSettings $settings): void
    {
        abort_unless(static::canAccess(), 403);

        $data = $settings->get();
        $data['allowed_tables'] = implode("\n", (array) $data['allowed_tables']);

        $this->form->fill($data);
    }

    public function getHeading(): string|Htmlable|null
    {
        return null;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
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

                        Forms\Components\Textarea::make('system_prompt')
                            ->label('Системный промпт')
                            ->rows(12)
                            ->required()
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make('Самостоятельные проверки')
                    ->description('Агент может сам выполнять безопасные проверки данных. Запросы проходят через read-only шлюз приложения.')
                    ->schema([
                        Forms\Components\Toggle::make('read_only_sql_enabled')
                            ->label('Разрешить read-only SQL-инструмент')
                            ->helperText('Модель не получает пароль от базы. Приложение проверяет SELECT-запрос и выполняет его в read-only режиме.')
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
                            ->label('Разрешенные таблицы')
                            ->helperText('Одна таблица на строку. Все запросы также обязаны фильтроваться по текущему market_id.')
                            ->rows(8)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

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
                    ])
                    ->columns(3),
            ]);
    }

    public function save(AiAgentSettings $settings): void
    {
        abort_unless(static::canAccess(), 403);

        $settings->save($this->form->getState());

        Notification::make()
            ->title('Настройки ИИ-агента сохранены')
            ->success()
            ->send();
    }
}
