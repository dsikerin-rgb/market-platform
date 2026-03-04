<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\User;
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
            : UserNotificationPreferences::TOPICS;

        if ($topics === []) {
            $topics = UserNotificationPreferences::TOPICS;
        }

        $this->form->fill([
            'channels' => $channels,
            'topics' => $topics,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Уведомления и сообщения')
                    ->description($this->canSelfManage
                        ? 'Выберите каналы доставки и события, которые хотите получать.'
                        : 'Настройки назначаются администратором рынка. Вы можете просматривать текущие параметры.')
                    ->schema([
                        Forms\Components\CheckboxList::make('channels')
                            ->label('Каналы доставки')
                            ->options(UserNotificationPreferences::channelLabels())
                            ->columns(3)
                            ->required($this->canSelfManage)
                            ->disabled(! $this->canSelfManage)
                            ->helperText('Email работает при заполненном email, Telegram — при подключенном chat_id.')
                            ->columnSpanFull(),

                        Forms\Components\CheckboxList::make('topics')
                            ->label('События')
                            ->options(UserNotificationPreferences::topicLabels())
                            ->columns(2)
                            ->required($this->canSelfManage)
                            ->disabled(! $this->canSelfManage)
                            ->columnSpanFull(),
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
        ], $selfManage);

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
}
