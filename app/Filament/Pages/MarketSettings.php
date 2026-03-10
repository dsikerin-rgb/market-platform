<?php
# app/Filament/Pages/MarketSettings.php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Market;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;

class MarketSettings extends Page
{
    protected static ?string $navigationLabel = 'Настройки рынка';

    /**
     * На prod у market-admin этот пункт лежит в "Панель управления".
     * Важно: в Filament 4 тип должен совпадать с базовым классом.
     */
    protected static \UnitEnum|string|null $navigationGroup = 'Настройки';

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-cog-6-tooth';
    protected static ?int $navigationSort = 5;

    protected static ?string $slug = 'market-settings';
    protected static ?string $title = 'Настройки рынка';

    protected string $view = 'filament.pages.market-settings';

    public ?Market $market = null;

    public bool $isSuperAdmin = false;
    public bool $canEditMarket = false;

    public ?string $marketsUrl = null;
    public ?string $locationTypesUrl = null;
    public ?string $spaceTypesUrl = null;
    public ?string $staffUrl = null;
    public ?string $tenantUrl = null;
    public ?string $permissionsUrl = null;
    public ?string $rolesUrl = null;
    public ?string $integrationExchangesUrl = null;

    /**
     * URL страницы просмотра карты.
     * Ожидаем маршрут filament.admin.market-map (см. routes/web.php).
     */
    public ?string $marketMapViewerUrl = null;

    /**
     * @var array{
     *   name:?string,
     *   address:?string,
     *   timezone:?string,
     *   map_pdf_path?:string|null
     * }
     */
    public array $data = [
        'name' => null,
        'address' => null,
        'timezone' => null,
        'map_pdf_path' => null,
        'holiday_default_notify_before_days' => 7,
        'holiday_notification_recipient_user_ids' => [],
        'request_notification_recipient_user_ids' => [],
        'request_repair_notification_recipient_user_ids' => [],
        'request_help_notification_recipient_user_ids' => [],
        'notification_channels_calendar' => ['database'],
        'notification_channels_requests' => ['database'],
        'notification_channels_messages' => ['database'],
        'notification_channels_tasks' => ['database'],
        'notification_channels_reminders' => ['database'],
        'dashboard_enabled_widgets' => [],
    ];

    public static function shouldRegisterNavigation(): bool
    {
        return true;
    }

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return false;
        }

        // Super-admin имеет доступ всегда
        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return true;
        }

        // Market-admin имеет доступ к настройкам своего рынка
        if (method_exists($user, 'hasRole') && $user->hasRole('market-admin')) {
            return true;
        }

        // Проверка по permission
        return $user->can('market-settings.view') || $user->can('market-settings.edit');
    }

    public static function getNavigationItems(): array
    {
        return [];
    }

    public function mount(): void
    {
        $user = Filament::auth()->user();

        $this->isSuperAdmin = (bool) $user
            && method_exists($user, 'isSuperAdmin')
            && $user->isSuperAdmin();

        $this->market = $this->resolveMarketForUser();

        abort_unless($this->market, 404);

        $this->canEditMarket = $this->resolveCanEditMarket();

        $this->fillQuickLinks();

        $settings = (array) ($this->market->settings ?? []);

        $this->form->fill([
            'name' => $this->market->name,
            'address' => $this->market->address,
            'timezone' => $this->market->timezone ?: config('app.timezone', 'Europe/Moscow'),
            'map_pdf_path' => isset($settings['map_pdf_path']) && is_string($settings['map_pdf_path']) ? $settings['map_pdf_path'] : null,
            'holiday_default_notify_before_days' => is_numeric($settings['holiday_default_notify_before_days'] ?? null)
                ? (int) $settings['holiday_default_notify_before_days']
                : 7,
            'holiday_notification_recipient_user_ids' => array_values(array_filter(
                (array) ($settings['holiday_notification_recipient_user_ids'] ?? []),
                static fn ($value): bool => is_numeric($value),
            )),
            'request_notification_recipient_user_ids' => array_values(array_filter(
                (array) ($settings['request_notification_recipient_user_ids'] ?? []),
                static fn ($value): bool => is_numeric($value),
            )),
            'request_repair_notification_recipient_user_ids' => array_values(array_filter(
                (array) ($settings['request_repair_notification_recipient_user_ids'] ?? []),
                static fn ($value): bool => is_numeric($value),
            )),
            'request_help_notification_recipient_user_ids' => array_values(array_filter(
                (array) ($settings['request_help_notification_recipient_user_ids'] ?? []),
                static fn ($value): bool => is_numeric($value),
            )),
            'notification_channels_calendar' => $this->normalizeNotificationChannels(
                $settings['notification_channels_calendar'] ?? ['database']
            ),
            'notification_channels_requests' => $this->normalizeNotificationChannels(
                $settings['notification_channels_requests'] ?? ['database']
            ),
            'notification_channels_messages' => $this->normalizeNotificationChannels(
                $settings['notification_channels_messages'] ?? ['database']
            ),
            'notification_channels_tasks' => $this->normalizeNotificationChannels(
                $settings['notification_channels_tasks'] ?? ['database']
            ),
            'notification_channels_reminders' => $this->normalizeNotificationChannels(
                $settings['notification_channels_reminders'] ?? ['database']
            ),
            'dashboard_enabled_widgets' => $this->normalizeDashboardWidgetSelection(
                data_get($settings, 'dashboard.enabled_widgets')
            ),
            'debt_monitoring_grace_days' => is_numeric($settings['debt_monitoring']['grace_days'] ?? null)
                ? (int) $settings['debt_monitoring']['grace_days']
                : 5,
            'debt_monitoring_yellow_after_days' => is_numeric($settings['debt_monitoring']['yellow_after_days'] ?? $settings['debt_monitoring']['orange_after_days'] ?? null)
                ? (int) ($settings['debt_monitoring']['yellow_after_days'] ?? $settings['debt_monitoring']['orange_after_days'])
                : 1,
            'debt_monitoring_red_after_days' => is_numeric($settings['debt_monitoring']['red_after_days'] ?? null)
                ? (int) $settings['debt_monitoring']['red_after_days']
                : 30,
            'debt_monitoring_tenant_aggregate_mode' => in_array($settings['debt_monitoring']['tenant_aggregate_mode'] ?? null, ['worst', 'dominant'], true)
                ? $settings['debt_monitoring']['tenant_aggregate_mode']
                : 'worst',
        ]);
    }

    /**
     * Filament 4: Page-форма строится через Schema.
     */
    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->model($this->market)
            ->components([
                Section::make()
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Название рынка')
                            ->required()
                            ->maxLength(255)
                            ->autocomplete(false)
                            ->disabled(fn (): bool => ! $this->canEditMarket)
                            ->columnSpan([
                                'default' => 12,
                                'lg' => 8,
                            ]),

                        Forms\Components\TextInput::make('address')
                            ->label('Адрес')
                            ->required()
                            ->maxLength(255)
                            ->autocomplete(false)
                            ->disabled(fn (): bool => ! $this->canEditMarket)
                            ->columnSpan([
                                'default' => 12,
                                'lg' => 8,
                            ]),

                        Forms\Components\Select::make('timezone')
                            ->label('Часовой пояс')
                            ->options(fn (): array => $this->timezoneOptionsRu())
                            ->searchable()
                            ->native(false)
                            ->required()
                            ->helperText('Используется для дедлайнов, уведомлений и отображения дат.')
                            ->disabled(fn (): bool => ! $this->canEditMarket)
                            ->columnSpan([
                                'default' => 12,
                                'lg' => 4,
                            ]),
                    ])
                    ->columns(12),

                Section::make('Карта рынка')
                    ->description('PDF-карта для просмотра с масштабированием и перемещением.')
                    ->schema([
                        Forms\Components\FileUpload::make('map_pdf_path')
                            ->label('Карта (PDF)')
                            ->helperText('Загрузите векторный PDF (рекомендуется). Файл хранится приватно.')
                            ->acceptedFileTypes(['application/pdf'])
                            ->disk('local')
                            ->directory(fn (): string => $this->market ? 'market-maps/market_'.$this->market->id : 'market-maps')
                            ->visibility('private')
                            ->preserveFilenames()
                            ->maxSize(20480)
                            ->disabled(fn (): bool => ! $this->canEditMarket)
                            ->columnSpan([
                                'default' => 12,
                                'lg' => 7,   // ✅ не на всю ширину
                            ]),

                        Forms\Components\Placeholder::make('market_map_open_button')
                            ->hiddenLabel()
                            ->content(fn (): HtmlString => $this->renderOpenMapButton())
                            ->columnSpan([
                                'default' => 12,
                                'lg' => 5,
                            ]),
                    ])
                    ->columns(12)
                    ->collapsible()
                    ->collapsed(),

                Section::make('Праздники рынка')
                    ->description('Настройки уведомлений о праздниках рынка.')
                    ->schema([
                        Forms\Components\TextInput::make('holiday_default_notify_before_days')
                            ->label('Уведомлять за (дней)')
                            ->numeric()
                            ->minValue(0)
                            ->helperText('Значение по умолчанию для новых праздников.')
                            ->disabled(fn (): bool => ! $this->canEditMarket)
                            ->columnSpan([
                                'default' => 12,
                                'lg' => 4,
                            ]),

                        Forms\Components\Select::make('holiday_notification_recipient_user_ids')
                            ->label('Получатели уведомлений')
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->options(function (): array {
                                if (! $this->market) {
                                    return [];
                                }

                                return User::query()
                                    ->where('market_id', $this->market->id)
                                    ->orderBy('name')
                                    ->pluck('name', 'id')
                                    ->all();
                            })
                            ->helperText('Если список пуст, уведомления получат market-admin.')
                            ->disabled(fn (): bool => ! $this->canEditMarket)
                            ->columnSpan([
                                'default' => 12,
                                'lg' => 8,
                            ]),
                    ])
                    ->columns(12)
                    ->collapsible()
                    ->collapsed(),

                Section::make('Обращения и чат')
                    ->description('Получатели уведомлений о новых сообщениях от арендаторов.')
                    ->schema([
                        Forms\Components\Select::make('request_notification_recipient_user_ids')
                            ->label('Получатели обращений (общие)')
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->options(function (): array {
                                if (! $this->market) {
                                    return [];
                                }

                                return User::query()
                                    ->where('market_id', $this->market->id)
                                    ->orderBy('name')
                                    ->pluck('name', 'id')
                                    ->all();
                            })
                            ->helperText('Если список пуст, общие уведомления не отправляются.')
                            ->disabled(fn (): bool => ! $this->canEditMarket)
                            ->columnSpan([
                                'default' => 12,
                                'lg' => 4,
                            ]),

                        Forms\Components\Select::make('request_repair_notification_recipient_user_ids')
                            ->label('Получатели ремонта/обслуживания')
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->options(function (): array {
                                if (! $this->market) {
                                    return [];
                                }

                                return User::query()
                                    ->where('market_id', $this->market->id)
                                    ->orderBy('name')
                                    ->pluck('name', 'id')
                                    ->all();
                            })
                            ->helperText('Приоритетный список для категории "repair".')
                            ->disabled(fn (): bool => ! $this->canEditMarket)
                            ->columnSpan([
                                'default' => 12,
                                'lg' => 4,
                            ]),

                        Forms\Components\Select::make('request_help_notification_recipient_user_ids')
                            ->label('Получатели поддержки (Помощь)')
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->options(function (): array {
                                if (! $this->market) {
                                    return [];
                                }

                                return User::query()
                                    ->where('market_id', $this->market->id)
                                    ->orderBy('name')
                                    ->pluck('name', 'id')
                                    ->all();
                            })
                            ->helperText('Приоритетный список для категории "help".')
                            ->disabled(fn (): bool => ! $this->canEditMarket)
                            ->columnSpan([
                                'default' => 12,
                                'lg' => 4,
                            ]),
                    ])
                    ->columns(12)
                    ->collapsible()
                    ->collapsed(),

                Section::make('Каналы уведомлений')
                    ->description('Каналы доставки по темам. Telegram начнет работать после подключения транспорта.')
                    ->schema([
                        Forms\Components\Select::make('notification_channels_calendar')
                            ->label('Календарь')
                            ->multiple()
                            ->options(fn (): array => $this->notificationChannelOptions())
                            ->helperText('Уведомления о праздниках и санитарных днях.')
                            ->disabled(fn (): bool => ! $this->canEditMarket)
                            ->columnSpan([
                                'default' => 12,
                                'lg' => 6,
                            ]),
                        Forms\Components\Select::make('notification_channels_requests')
                            ->label('Обращения')
                            ->multiple()
                            ->options(fn (): array => $this->notificationChannelOptions())
                            ->helperText('Новые обращения арендаторов.')
                            ->disabled(fn (): bool => ! $this->canEditMarket)
                            ->columnSpan([
                                'default' => 12,
                                'lg' => 6,
                            ]),
                        Forms\Components\Select::make('notification_channels_messages')
                            ->label('Сообщения')
                            ->multiple()
                            ->options(fn (): array => $this->notificationChannelOptions())
                            ->helperText('Ответы в чатах и диалогах.')
                            ->disabled(fn (): bool => ! $this->canEditMarket)
                            ->columnSpan([
                                'default' => 12,
                                'lg' => 6,
                            ]),
                        Forms\Components\Select::make('notification_channels_tasks')
                            ->label('Назначение задач')
                            ->multiple()
                            ->options(fn (): array => $this->notificationChannelOptions())
                            ->helperText('Новые задачи для сотрудника.')
                            ->disabled(fn (): bool => ! $this->canEditMarket)
                            ->columnSpan([
                                'default' => 12,
                                'lg' => 6,
                            ]),
                        Forms\Components\Select::make('notification_channels_reminders')
                            ->label('Напоминания по задачам')
                            ->multiple()
                            ->options(fn (): array => $this->notificationChannelOptions())
                            ->helperText('Просроченные и приближающиеся сроки задач.')
                            ->disabled(fn (): bool => ! $this->canEditMarket)
                            ->columnSpan([
                                'default' => 12,
                                'lg' => 6,
                            ]),
                    ])
                    ->columns(12)
                    ->collapsible()
                    ->collapsed(),

                Section::make('Главная страница')
                    ->description('Настройте, какие виджеты показывать на главной странице для этого рынка.')
                    ->schema([
                        Forms\Components\CheckboxList::make('dashboard_enabled_widgets')
                            ->label('Виджеты панели управления')
                            ->options(fn (): array => Dashboard::getAvailableDashboardWidgetOptions())
                            ->descriptions([
                                'overview' => 'Ключевые показатели рынка и месячной отчётности.',
                                'revenue_year' => 'Динамика выручки по месяцам.',
                                'tenant_activity' => 'Оперативная активность арендаторов.',
                                'spaces_status' => 'Распределение торговых мест по статусам.',
                                'expiring_contracts' => 'Договоры, требующие продления.',
                                'recent_requests' => 'Последние обращения арендаторов.',
                            ])
                            ->helperText('Если ничего не выбрано, будут показаны все виджеты по умолчанию.')
                            ->columns([
                                'default' => 1,
                                'lg' => 2,
                            ])
                            ->disabled(fn (): bool => ! $this->canEditMarket)
                            ->columnSpanFull(),
                    ])
                    ->columns(12)
                    ->collapsible()
                    ->collapsed(),

                Section::make('Мониторинг задолженности')
                    ->description('Настройки расчёта и отображения задолженности арендаторов.')
                    ->schema([
                        Forms\Components\TextInput::make('debt_monitoring_grace_days')
                            ->label('Льготный срок оплаты, дней')
                            ->helperText('Сколько дней после выставления начисления долг ещё не считается просроченным (статус pending).')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(30)
                            ->default(5)
                            ->disabled(fn (): bool => ! $this->canEditMarket)
                            ->columnSpan([
                                'default' => 12,
                                'lg' => 3,
                            ]),

                        Forms\Components\TextInput::make('debt_monitoring_yellow_after_days')
                            ->label('Жёлтый статус после, дней просрочки')
                            ->helperText('Через сколько дней просрочки статус становится жёлтым (orange). Обычно 1 день.')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(60)
                            ->default(1)
                            ->disabled(fn (): bool => ! $this->canEditMarket)
                            ->columnSpan([
                                'default' => 12,
                                'lg' => 3,
                            ]),

                        Forms\Components\TextInput::make('debt_monitoring_red_after_days')
                            ->label('Красный статус после, дней просрочки')
                            ->helperText('Через сколько дней просрочки статус становится красным (red). Рекомендуемое значение: 30 дней.')
                            ->numeric()
                            ->minValue(2)
                            ->maxValue(180)
                            ->default(30)
                            ->disabled(fn (): bool => ! $this->canEditMarket)
                            ->columnSpan([
                                'default' => 12,
                                'lg' => 3,
                            ]),

                        Forms\Components\Select::make('debt_monitoring_tenant_aggregate_mode')
                            ->label('Агрегация по арендатору')
                            ->options([
                                'worst' => 'По худшему месту',
                                'dominant' => 'По преобладающему статусу',
                            ])
                            ->helperText('Как рассчитывать итоговый статус арендатора, если у него несколько торговых мест.')
                            ->default('worst')
                            ->native(false)
                            ->disabled(fn (): bool => ! $this->canEditMarket)
                            ->columnSpan([
                                'default' => 12,
                                'lg' => 3,
                            ]),
                    ])
                    ->columns(12)
                    ->collapsible()
                    ->collapsed(),
            ]);
    }

    public function save(): void
    {
        abort_unless($this->market, 404);
        abort_unless($this->canEditMarket, 403);

        $state = $this->form->getState();

        // Нормализуем путь (Filament отдаёт строку для single upload).
        $newMapPath = $state['map_pdf_path'] ?? null;
        if (is_array($newMapPath)) {
            $newMapPath = $newMapPath[0] ?? null;
        }
        $newMapPath = (is_string($newMapPath) && $newMapPath !== '') ? $newMapPath : null;

        $settings = (array) ($this->market->settings ?? []);
        $oldMapPath = isset($settings['map_pdf_path']) && is_string($settings['map_pdf_path']) ? $settings['map_pdf_path'] : null;

        // Если файл заменили/удалили — удаляем старый, чтобы не копить мусор.
        if ($oldMapPath && $oldMapPath !== $newMapPath) {
            try {
                Storage::disk('local')->delete($oldMapPath);
            } catch (\Throwable) {
                // ignore
            }
        }

        $settings['map_pdf_path'] = $newMapPath;
        $settings['holiday_default_notify_before_days'] = is_numeric($state['holiday_default_notify_before_days'] ?? null)
            ? max(0, (int) $state['holiday_default_notify_before_days'])
            : 7;
        $settings['holiday_notification_recipient_user_ids'] = array_values(array_filter(
            (array) ($state['holiday_notification_recipient_user_ids'] ?? []),
            static fn ($value): bool => is_numeric($value),
        ));
        $settings['request_notification_recipient_user_ids'] = array_values(array_filter(
            (array) ($state['request_notification_recipient_user_ids'] ?? []),
            static fn ($value): bool => is_numeric($value),
        ));
        $settings['request_repair_notification_recipient_user_ids'] = array_values(array_filter(
            (array) ($state['request_repair_notification_recipient_user_ids'] ?? []),
            static fn ($value): bool => is_numeric($value),
        ));
        $settings['request_help_notification_recipient_user_ids'] = array_values(array_filter(
            (array) ($state['request_help_notification_recipient_user_ids'] ?? []),
            static fn ($value): bool => is_numeric($value),
        ));
        $settings['notification_channels_calendar'] = $this->normalizeNotificationChannels(
            $state['notification_channels_calendar'] ?? ['database']
        );
        $settings['notification_channels_requests'] = $this->normalizeNotificationChannels(
            $state['notification_channels_requests'] ?? ['database']
        );
        $settings['notification_channels_messages'] = $this->normalizeNotificationChannels(
            $state['notification_channels_messages'] ?? ['database']
        );
        $settings['notification_channels_tasks'] = $this->normalizeNotificationChannels(
            $state['notification_channels_tasks'] ?? ['database']
        );
        $settings['notification_channels_reminders'] = $this->normalizeNotificationChannels(
            $state['notification_channels_reminders'] ?? ['database']
        );
        $settings['dashboard'] = array_merge(
            (array) ($settings['dashboard'] ?? []),
            [
                'enabled_widgets' => $this->normalizeDashboardWidgetSelection(
                    $state['dashboard_enabled_widgets'] ?? null
                ),
            ],
        );
        $yellowAfterDays = is_numeric($state['debt_monitoring_yellow_after_days'] ?? null)
            ? max(1, min(60, (int) $state['debt_monitoring_yellow_after_days']))
            : 1;
        $redAfterDays = is_numeric($state['debt_monitoring_red_after_days'] ?? null)
            ? max(2, min(180, (int) $state['debt_monitoring_red_after_days']))
            : 30;
        
        // Гарантируем, что red_after_days > yellow_after_days
        if ($redAfterDays <= $yellowAfterDays) {
            $redAfterDays = $yellowAfterDays + 1;
        }
        
        $settings['debt_monitoring'] = [
            'grace_days' => is_numeric($state['debt_monitoring_grace_days'] ?? null)
                ? max(0, min(30, (int) $state['debt_monitoring_grace_days']))
                : 5,
            'yellow_after_days' => $yellowAfterDays,
            'red_after_days' => $redAfterDays,
            'tenant_aggregate_mode' => in_array($state['debt_monitoring_tenant_aggregate_mode'] ?? null, ['worst', 'dominant'], true)
                ? $state['debt_monitoring_tenant_aggregate_mode']
                : 'worst',
        ];

        $this->market->fill([
            'name' => (string) ($state['name'] ?? ''),
            'address' => (string) ($state['address'] ?? ''),
            // Храним IANA timezone (Asia/Omsk и т.д.) — это стандарт.
            'timezone' => (string) ($state['timezone'] ?? config('app.timezone', 'Europe/Moscow')),
            'settings' => $settings,
        ]);

        $this->market->save();

        Notification::make()
            ->title('Сохранено')
            ->body('Настройки рынка обновлены.')
            ->success()
            ->send();
    }

    protected function resolveCanEditMarket(): bool
    {
        $user = Filament::auth()->user();

        if (! $user || ! $this->market) {
            return false;
        }

        if ($this->isSuperAdmin) {
            return true;
        }

        $sameMarket = (int) ($user->market_id ?? 0) > 0
            && (int) $user->market_id === (int) $this->market->id;

        if (! $sameMarket) {
            return false;
        }

        // Редактировать — market-admin (и/или permission markets.update).
        $canEditByRole = method_exists($user, 'hasRole') && $user->hasRole('market-admin');

        return $canEditByRole || $user->can('markets.update');
    }

    protected function resolveMarketForUser(): ?Market
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return null;
        }

        if ($this->isSuperAdmin) {
            $selectedMarketId = $this->selectedMarketIdFromSession();

            if ($selectedMarketId) {
                return Market::query()->whereKey($selectedMarketId)->first();
            }

            return Market::query()->orderBy('id')->first();
        }

        $marketId = (int) ($user->market_id ?? 0);

        return $marketId > 0
            ? Market::query()->whereKey($marketId)->first()
            : null;
    }

    protected function selectedMarketIdFromSession(): ?int
    {
        // сначала единый ключ дашборда (он у тебя уже используется в виджетах)
        $value = session('dashboard_market_id');

        if (! filled($value)) {
            $panelId = Filament::getCurrentPanel()?->getId() ?? 'admin';
            $value = session("filament.{$panelId}.selected_market_id");
        }

        if (! filled($value)) {
            $value = session('filament.admin.selected_market_id');
        }

        return filled($value) ? (int) $value : null;
    }

    /**
     * @return array<string, string>
     */
    protected function notificationChannelOptions(): array
    {
        return [
            'database' => 'В кабинете',
            'mail' => 'Email',
            'telegram' => 'Telegram',
        ];
    }

    /**
     * @param mixed $channels
     * @return list<string>
     */
    protected function normalizeNotificationChannels(mixed $channels): array
    {
        if (! is_array($channels)) {
            return ['database'];
        }

        $allowed = ['database', 'mail', 'telegram'];

        $normalized = array_values(array_unique(array_filter(
            array_map(static fn ($channel) => is_string($channel) ? trim(mb_strtolower($channel)) : '', $channels),
            static fn (string $channel): bool => in_array($channel, $allowed, true),
        )));

        return $normalized === [] ? ['database'] : $normalized;
    }

    /**
     * @param  mixed  $selected
     * @return list<string>
     */
    protected function normalizeDashboardWidgetSelection(mixed $selected): array
    {
        $allowed = Dashboard::getDefaultDashboardWidgetKeys();
        $allowedSet = array_flip($allowed);

        if (! is_array($selected)) {
            return $allowed;
        }

        $normalized = array_values(array_filter(
            $selected,
            static fn ($key): bool => is_string($key) && isset($allowedSet[$key]),
        ));

        return $normalized === [] ? $allowed : $normalized;
    }

    protected function fillQuickLinks(): void
    {
        $this->marketsUrl = $this->isSuperAdmin
            ? $this->resourceUrl([\App\Filament\Resources\MarketResource::class], 'index')
            : null;

        $this->locationTypesUrl = $this->resourceUrl([
            \App\Filament\Resources\LocationTypeResource::class,
            \App\Filament\Resources\MarketLocationTypeResource::class,
        ], 'index');

        $this->spaceTypesUrl = $this->resourceUrl([
            \App\Filament\Resources\SpaceTypeResource::class,
            \App\Filament\Resources\MarketSpaceTypeResource::class,
        ], 'index');

        $this->staffUrl = $this->resourceUrl([
            \App\Filament\Resources\StaffResource::class,
            \App\Filament\Resources\UserResource::class,
        ], 'index');

        $this->tenantUrl = $this->resourceUrl([
            \App\Filament\Resources\TenantResource::class,
        ], 'index');

        $this->permissionsUrl = $this->resourceUrl([
            \App\Filament\Resources\PermissionResource::class,
        ], 'index');

        $this->rolesUrl = $this->resourceUrl([
            \App\Filament\Resources\RoleResource::class,
        ], 'index');

        $this->integrationExchangesUrl = $this->resourceUrl([
            \App\Filament\Resources\IntegrationExchangeResource::class,
            \App\Filament\Resources\IntegrationExchangesResource::class,
        ], 'index');

        try {
            $this->marketMapViewerUrl = route('filament.admin.market-map');
        } catch (\Throwable) {
            $this->marketMapViewerUrl = null;
        }
    }

    /**
     * @param  array<int, class-string>  $candidates
     */
    protected function resourceUrl(array $candidates, string $page = 'index'): ?string
    {
        foreach ($candidates as $class) {
            if (! class_exists($class)) {
                continue;
            }

            if (! method_exists($class, 'getUrl')) {
                continue;
            }

            try {
                /** @var string $url */
                $url = $class::getUrl($page);

                return $url;
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    protected function renderOpenMapButton(): HtmlString
    {
        if (blank($this->marketMapViewerUrl)) {
            return new HtmlString(
                '<div class="text-sm text-gray-500">Маршрут просмотра карты не найден (проверь routes/web.php).</div>'
            );
        }

        if (blank($this->data['map_pdf_path'] ?? null)) {
            return new HtmlString(
                '<div class="text-sm text-gray-500">Сначала загрузите PDF-карту и сохраните настройки.</div>'
            );
        }

        $href = e($this->marketMapViewerUrl);

        // ВАЖНО: tailwind-классы из PHP-строки не попадают в сборку, поэтому
        // используем готовые классы Filament кнопки (они уже в CSS) + inline как страховку.
        return new HtmlString(
            '<a href="' . $href . '" target="_blank" rel="noopener" role="button" ' .
            'class="fi-btn fi-btn-color-primary fi-btn-size-md" ' .
            'style="display:inline-flex;align-items:center;justify-content:center;gap:.5rem;' .
            'padding:.55rem .95rem;border-radius:.75rem;font-weight:600;text-decoration:none;">' .
                '<span class="fi-btn-label">&#1050;&#1072;&#1088;&#1090;&#1072;</span>' .
            '</a>'
        );
    }

    protected function timezoneOptionsRu(): array
    {
        $timezones = timezone_identifiers_list();
        $out = [];

        foreach ($timezones as $tz) {
            $out[$tz] = $this->formatTimezoneLabelRu($tz);
        }

        $preferred = [
            'Europe/Moscow',
            'Asia/Omsk',
            'Asia/Barnaul',
            'Asia/Novosibirsk',
            'Asia/Yekaterinburg',
            'Asia/Krasnoyarsk',
            'Asia/Irkutsk',
            'Asia/Yakutsk',
            'Asia/Vladivostok',
        ];

        uksort($out, function (string $a, string $b) use ($preferred, $out): int {
            $ia = array_search($a, $preferred, true);
            $ib = array_search($b, $preferred, true);

            if ($ia !== false && $ib !== false) {
                return $ia <=> $ib;
            }

            if ($ia !== false) {
                return -1;
            }

            if ($ib !== false) {
                return 1;
            }

            return strcmp($out[$a], $out[$b]);
        });

        return $out;
    }

    protected function formatTimezoneLabelRu(string $timezone): string
    {
        $name = $this->timezoneCityNameRu($timezone);
        $offset = $this->formatUtcOffset($timezone);

        return "{$name} ({$offset})";
    }

    protected function timezoneCityNameRu(string $timezone): string
    {
        $map = [
            'Europe/Moscow' => 'Москва',
            'Asia/Omsk' => 'Омск',
            'Asia/Barnaul' => 'Барнаул',
            'Asia/Novosibirsk' => 'Новосибирск',
            'Asia/Yekaterinburg' => 'Екатеринбург',
            'Asia/Krasnoyarsk' => 'Красноярск',
            'Asia/Irkutsk' => 'Иркутск',
            'Asia/Yakutsk' => 'Якутск',
            'Asia/Vladivostok' => 'Владивосток',
        ];

        if (isset($map[$timezone])) {
            return $map[$timezone];
        }

        if (class_exists(\IntlTimeZone::class)) {
            try {
                $tz = \IntlTimeZone::createTimeZone($timezone);

                if ($tz) {
                    $label = $tz->getDisplayName(false, \IntlTimeZone::DISPLAY_GENERIC_LOCATION, 'ru_RU');

                    if (is_string($label) && $label !== '' && $label !== $timezone) {
                        return $label;
                    }
                }
            } catch (\Throwable) {
                // ignore
            }
        }

        $parts = explode('/', $timezone);
        $city = end($parts) ?: $timezone;

        return str_replace('_', ' ', $city);
    }

    protected function formatUtcOffset(string $timezone): string
    {
        try {
            $tz = new \DateTimeZone($timezone);
            $now = new \DateTimeImmutable('now', $tz);
            $offsetSeconds = $tz->getOffset($now);
        } catch (\Throwable) {
            return 'UTC';
        }

        $sign = $offsetSeconds >= 0 ? '+' : '-';
        $offsetSeconds = abs($offsetSeconds);

        $hours = intdiv($offsetSeconds, 3600);
        $minutes = intdiv($offsetSeconds % 3600, 60);

        if ($minutes === 0) {
            return "UTC{$sign}{$hours}";
        }

        $mm = str_pad((string) $minutes, 2, '0', STR_PAD_LEFT);

        return "UTC{$sign}{$hours}:{$mm}";
    }
}
