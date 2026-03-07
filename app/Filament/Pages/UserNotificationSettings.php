<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\User;
use App\Support\QrCodeDataUriGenerator;
use App\Support\TelegramChatLinkService;
use App\Support\UserNotificationPreferences;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class UserNotificationSettings extends Page
{
    protected static ?string $title = 'Кабинет уведомлений';

    protected static ?string $slug = 'profile/notifications';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.user-notification-settings';

    /**
     * @var array{channels:list<string>,topics:list<string>}
     */
    public array $data = [
        'channels' => [],
        'topics' => [],
    ];

    public bool $canSelfManage = false;

    public ?User $currentUser = null;

    /**
     * @var array{token:string,expires_at:string,command:string,deep_link:?string,bot_username:?string,qr_svg_data_uri:?string}|null
     */
    public ?array $telegramLinkData = null;

    public static function canAccess(): bool
    {
        return (bool) Filament::auth()->user();
    }

    public function mount(): void
    {
        $user = Filament::auth()->user();
        abort_unless($user instanceof User, 403);

        $this->currentUser = $user;
        $this->canSelfManage = $user->canSelfManageNotificationPreferences();

        $preferences = app(UserNotificationPreferences::class);
        $raw = (array) ($user->notification_preferences ?? []);

        $channels = $preferences->normalizeChannels($raw['channels'] ?? []);
        if ($channels === []) {
            $channels = $preferences->defaultChannelsForUser($user);
        }

        $topics = array_key_exists('topics', $raw)
            ? $preferences->normalizeTopics($raw['topics'])
            : UserNotificationPreferences::defaultTopicsForUser($user);

        $visibleTopics = UserNotificationPreferences::visibleTopicsForUser($user);
        $topics = array_values(array_intersect($topics, $visibleTopics));

        if ($topics === []) {
            $topics = UserNotificationPreferences::defaultTopicsForUser($user);
        }

        $this->form->fill([
            'channels' => $channels,
            'topics' => $topics,
        ]);
    }

    public function generateTelegramConnectLink(): void
    {
        $user = Filament::auth()->user();
        abort_unless($user instanceof User, 403);

        if (! (bool) config('services.telegram.enabled', false)) {
            Notification::make()
                ->title('Telegram отключен')
                ->body('Сначала включите Telegram-транспорт в настройках окружения.')
                ->warning()
                ->send();

            return;
        }

        $payload = app(TelegramChatLinkService::class)->issue($user, 20);
        $deepLink = trim((string) ($payload['deep_link'] ?? ''));
        $payload['qr_svg_data_uri'] = $deepLink !== ''
            ? app(QrCodeDataUriGenerator::class)->generateSvgDataUri($deepLink)
            : null;

        $this->telegramLinkData = $payload;

        Notification::make()
            ->title('Ссылка подключения Telegram создана')
            ->body('Откройте бота, отсканируйте QR-код или отправьте команду /start из блока ниже.')
            ->success()
            ->send();
    }

    public function refreshTelegramStatus(): void
    {
        if (! $this->currentUser instanceof User) {
            return;
        }

        $this->currentUser = $this->currentUser->fresh();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Уведомления и сообщения')
                    ->description($this->canSelfManage
                        ? 'Настройте каналы доставки и выберите события, по которым хотите получать сообщения.'
                        : 'Параметры назначаются администратором. На этой странице можно посмотреть текущую конфигурацию и статус Telegram.')
                    ->schema([
                        Section::make('Каналы доставки')
                            ->description('Кабинет даёт уведомления в колокольчике. Email работает при заполненном email. Telegram станет доступен после привязки chat_id.')
                            ->schema([
                                Forms\Components\CheckboxList::make('channels')
                                    ->label('')
                                    ->options(UserNotificationPreferences::channelLabels())
                                    ->columns(3)
                                    ->required($this->canSelfManage)
                                    ->disabled(! $this->canSelfManage)
                                    ->columnSpanFull(),
                            ]),
                        Section::make('События')
                            ->description($this->securityTopicHelper())
                            ->schema([
                                Forms\Components\CheckboxList::make('topics')
                                    ->label('')
                                    ->options($this->topicOptions())
                                    ->columns(2)
                                    ->required($this->canSelfManage)
                                    ->disabled(! $this->canSelfManage)
                                    ->columnSpanFull(),
                            ]),
                    ])
                    ->columns(1),
            ]);
    }

    public function save(): void
    {
        $user = $this->currentUser;
        abort_unless($user instanceof User, 403);
        abort_unless($this->canSelfManage, 403);

        $state = $this->form->getState();
        $preferences = app(UserNotificationPreferences::class);

        $raw = (array) ($user->notification_preferences ?? []);
        $selfManage = (bool) ($raw['self_manage'] ?? false);
        if ($user->isSuperAdmin() || $user->isMarketAdmin()) {
            $selfManage = true;
        }

        $normalized = $preferences->normalizeForStorage([
            'self_manage' => $selfManage,
            'channels' => $state['channels'] ?? [],
            'topics' => $state['topics'] ?? [],
        ], $selfManage, UserNotificationPreferences::defaultTopicsForUser($user));

        $normalized['topics'] = array_values(array_intersect(
            $normalized['topics'],
            UserNotificationPreferences::visibleTopicsForUser($user),
        ));

        if ($normalized['channels'] === [] || $normalized['topics'] === []) {
            Notification::make()
                ->title('Проверьте настройки')
                ->body('Нужно выбрать хотя бы один канал и одно событие.')
                ->warning()
                ->send();

            return;
        }

        $user->forceFill([
            'notification_preferences' => $normalized,
        ])->save();

        Notification::make()
            ->title('Сохранено')
            ->body('Настройки уведомлений обновлены.')
            ->success()
            ->send();
    }

    public function securityTopicHelper(): string
    {
        if (! $this->currentUser instanceof User) {
            return 'Тема "Безопасность и входы" отвечает за уведомления о входах в админку.';
        }

        if ($this->currentUser->isSuperAdmin()) {
            return 'Для super-admin эта тема показывает, кто входит в админку. Рекомендуется оставить хотя бы канал "В кабинете" включённым.';
        }

        return 'Для обычных пользователей эта тема уведомляет о входе в админку под их учётной записью. По умолчанию она выключена и включается вручную.';
    }

    public function oneCIntegrationsTopicHelper(): string
    {
        return 'Тема "Интеграции 1С" уведомляет super-admin о завершении новых обменов 1С со статусом, сущностью и счётчиками импорта.';
    }

    /**
     * @return list<array{title:string,body:string}>
     */
    public function helperCards(): array
    {
        $cards = [
            [
                'title' => 'Как работает безопасность',
                'body' => $this->securityTopicHelper(),
            ],
            [
                'title' => 'Интеграции 1С',
                'body' => $this->oneCIntegrationsTopicHelper(),
            ],
            [
                'title' => 'Каналы доставки',
                'body' => 'В кабинете уведомления появляются сразу. Email зависит от заполненного адреса. Telegram требует привязки аккаунта к вашему профилю.',
            ],
        ];

        if ($this->canSelfManage) {
            $cards[] = [
                'title' => 'Когда применять изменения',
                'body' => 'Изменения вступают в силу сразу после сохранения. Если включаете Telegram впервые, сначала привяжите чат и затем обновите статус.',
            ];
        }

        return $cards;
    }

    /**
     * @return array<string, string>
     */
    public function topicOptions(): array
    {
        $labels = UserNotificationPreferences::topicLabels();
        $user = $this->currentUser;

        if (! $user instanceof User) {
            return $labels;
        }

        $visible = UserNotificationPreferences::visibleTopicsForUser($user);

        return array_intersect_key($labels, array_flip($visible));
    }
}
