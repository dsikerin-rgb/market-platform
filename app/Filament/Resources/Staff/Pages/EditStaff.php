<?php
# app/Filament/Resources/Staff/Pages/EditStaff.php

namespace App\Filament\Resources\Staff\Pages;

use App\Filament\Resources\Pages\BaseEditRecord;
use App\Filament\Resources\Staff\StaffResource;
use App\Notifications\TelegramConnectLinkNotification;
use App\Notifications\TelegramTestNotification;
use App\Support\QrCodeDataUriGenerator;
use App\Support\StaffConversationService;
use App\Support\TelegramChatLinkService;
use App\Support\UserNotificationPreferences;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\HtmlString;
use Spatie\Permission\Models\Role;

class EditStaff extends BaseEditRecord
{
    protected static string $resource = StaffResource::class;

    public function getBreadcrumbs(): array
    {
        return [
            StaffResource::getUrl('index') => (string) static::$resource::getPluralModelLabel(),
        ];
    }

    public function getSubheading(): string|HtmlString|null
    {
        return null;
    }

    public function getMaxContentWidth(): Width|string|null
    {
        return Width::Full;
    }

    public function getPageClasses(): array
    {
        return [
            ...parent::getPageClasses(),
            'fi-resource-staff-edit-page',
        ];
    }

    /**
     * @var array{token:string,expires_at:string,command:string,deep_link:?string,share_link:?string,qr_svg_data_uri:?string}|null
     */
    public ?array $telegramConnectModalPayload = null;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return $data;
        }

        // Market-level roles must not change employee market assignment.
        if (! $user->isSuperAdmin()) {
            $data['market_id'] = $this->record->market_id;

            // Prevent assigning super-admin via forged request payload.
            if (isset($data['roles']) && is_array($data['roles'])) {
                $superAdminRoleId = Role::query()->where('name', 'super-admin')->value('id');

                if ($superAdminRoleId) {
                    $data['roles'] = array_values(array_filter(
                        $data['roles'],
                        fn ($roleId) => (int) $roleId !== (int) $superAdminRoleId,
                    ));
                }
            }
        }

        if (! $this->canManagePasswordFields()) {
            unset($data['password'], $data['password_confirmation']);
        }

        if (($user->isSuperAdmin() || $user->isMarketAdmin()) && array_key_exists('notification_preferences', $data)) {
            $data = $this->normalizeNotificationPreferences($data);
        } else {
            unset($data['notification_preferences']);
        }

        return $data;
    }

    protected function getHeaderActions(): array
    {
        $user = Filament::auth()->user();

        return [
            Action::make('write_to_staff')
                ->label('Написать')
                ->icon('heroicon-o-chat-bubble-left-right')
                ->color('primary')
                ->size('lg')
                ->outlined()
                ->extraAttributes([
                    'class' => 'staff-card-action staff-card-action--primary',
                ])
                ->visible(fn (): bool => (bool) $user
                    && (method_exists($this->record, 'getKey'))
                    && (int) $this->record->getKey() !== (int) ($user->id ?? 0))
                ->modalHeading('Написать сотруднику')
                ->modalSubmitActionLabel('Отправить')
                ->form([
                    Forms\Components\TextInput::make('subject')
                        ->label('Тема (необязательно)')
                        ->maxLength(255)
                        ->helperText('Если тема пустая, заголовок будет собран из первого сообщения.'),
                    Forms\Components\Textarea::make('body')
                        ->label('Сообщение')
                        ->required()
                        ->rows(4)
                        ->placeholder('Напишите сообщение сотруднику...'),
                ])
                ->action(function (array $data) use ($user) {
                    if (! $user) {
                        return;
                    }

                    $conversation = app(StaffConversationService::class)->startConversation(
                        $user,
                        $this->record,
                        trim((string) ($data['subject'] ?? '')),
                        trim((string) ($data['body'] ?? '')),
                    );

                    return redirect()->to(url('/admin/requests?' . http_build_query([
                        'channel' => 'staff',
                        'conversation_id' => (int) $conversation->id,
                    ])));
                }),

            Action::make('password_settings')
                ->label('Пароль')
                ->icon('heroicon-o-key')
                ->color('gray')
                ->size('lg')
                ->outlined()
                ->extraAttributes([
                    'class' => 'staff-card-action staff-card-action--primary',
                ])
                ->modalHeading('Смена пароля')
                ->modalSubmitActionLabel('Сохранить')
                ->visible(fn (): bool => $this->canManagePasswordFields())
                ->fillForm(fn (): array => [
                    'password' => null,
                    'password_confirmation' => null,
                ])
                ->form([
                    Grid::make(2)
                        ->schema([
                            Forms\Components\TextInput::make('password')
                                ->label('Новый пароль')
                                ->password()
                                ->revealable()
                                ->minLength(8)
                                ->required()
                                ->dehydrated(false),
                            Forms\Components\TextInput::make('password_confirmation')
                                ->label('Подтверждение пароля')
                                ->password()
                                ->revealable()
                                ->required()
                                ->same('password')
                                ->dehydrated(false),
                        ]),
                ])
                ->action(function (array $data): void {
                    $plainPassword = trim((string) ($data['password'] ?? ''));
                    if ($plainPassword === '') {
                        Notification::make()
                            ->title('Пароль не изменен')
                            ->warning()
                            ->send();

                        return;
                    }

                    $this->record->forceFill([
                        'password' => Hash::make($plainPassword),
                    ])->save();

                    Notification::make()
                        ->title('Пароль обновлен')
                        ->success()
                        ->send();
                }),

            Action::make('notification_settings')
                ->label('Уведомления')
                ->icon('heroicon-o-bell')
                ->color('gray')
                ->size('lg')
                ->outlined()
                ->extraAttributes([
                    'class' => 'staff-card-action staff-card-action--secondary',
                ])
                ->modalHeading('Настройки уведомлений')
                ->modalSubmitActionLabel('Сохранить')
                ->modalWidth('3xl')
                ->visible(fn (): bool => (bool) $user && ($user->isSuperAdmin() || $user->isMarketAdmin()))
                ->fillForm(fn (): array => $this->notificationPreferencesFormState())
                ->form([
                    Section::make('Личные настройки')
                        ->schema([
                            Forms\Components\Toggle::make('notification_preferences.self_manage')
                                ->label('Разрешить личные настройки')
                                ->helperText('Пользователь сможет сам менять свои каналы и события в кабинете.')
                                ->default(false),
                        ]),
                    Section::make('Каналы доставки')
                        ->schema([
                            Forms\Components\CheckboxList::make('notification_preferences.channels')
                                ->label('Назначаются администратором')
                                ->options(UserNotificationPreferences::channelLabels())
                                ->columns(3),
                        ]),
                    Section::make('События')
                        ->schema([
                            Forms\Components\CheckboxList::make('notification_preferences.topics')
                                ->label('Назначаются администратором')
                                ->options(UserNotificationPreferences::topicLabels())
                                ->columns(2),
                        ]),
                ])
                ->action(function (array $data): void {
                    $payload = [
                        'roles' => $this->record->roles()->pluck('id')->all(),
                        'notification_preferences' => is_array($data['notification_preferences'] ?? null)
                            ? $data['notification_preferences']
                            : [],
                    ];

                    $normalized = $this->normalizeNotificationPreferences($payload);

                    $this->record->forceFill([
                        'notification_preferences' => $normalized['notification_preferences'] ?? [],
                    ])->save();

                    Notification::make()
                        ->title('Настройки уведомлений сохранены')
                        ->success()
                        ->send();
                }),

            Action::make('telegram_settings')
                ->label('Telegram')
                ->icon('heroicon-o-paper-airplane')
                ->color('gray')
                ->size('lg')
                ->outlined()
                ->extraAttributes([
                    'class' => 'staff-card-action staff-card-action--secondary',
                ])
                ->modalHeading('Telegram')
                ->modalSubmitActionLabel('Сохранить')
                ->modalWidth('3xl')
                ->fillForm(fn (): array => [
                    'telegram_chat_id' => $this->record->telegram_chat_id,
                ])
                ->form([
                    Section::make('')
                        ->schema([
                            Grid::make(2)
                                ->schema([
                                    Forms\Components\TextInput::make('telegram_chat_id')
                                        ->label('Telegram chat_id')
                                        ->placeholder('123456789')
                                        ->helperText('Для уведомлений в Telegram')
                                        ->maxLength(32)
                                        ->regex('/^-?\d+$/')
                                        ->validationMessages([
                                            'regex' => 'Только цифры и знак "-" в начале.',
                                        ]),
                                    Section::make('Статус подключения')
                                        ->schema([
                                            Forms\Components\Placeholder::make('telegram_status')
                                                ->label('Статус')
                                                ->content(function (): string {
                                                    if (blank($this->record->telegram_chat_id)) {
                                                        return '<span class="text-danger font-semibold">● Не подключен</span>';
                                                    }
                                                    $profile = is_array($this->record->telegram_profile ?? null)
                                                        ? $this->record->telegram_profile
                                                        : [];
                                                    $username = trim((string) ($profile['username'] ?? ''));
                                                    $linkedAt = $this->record->telegram_linked_at?->format('d.m.Y H:i');
                                                    $status = '<span class="text-success font-semibold">● Подключен</span>';
                                                    if ($username !== '') {
                                                        $status .= '<br><span class="text-gray-600">@' . e($username) . '</span>';
                                                    }
                                                    if ($linkedAt !== null) {
                                                        $status .= '<br><span class="text-gray-500 text-sm">' . e($linkedAt) . '</span>';
                                                    }
                                                    return $status;
                                                }),
                                        ])
                                        ->compact()
                                        ->collapsed(),
                                    Section::make('QR-подключение')
                                        ->schema([
                                            Forms\Components\Placeholder::make('telegram_connect_qr_preview')
                                                ->label('')
                                                ->content(fn () => $this->telegramConnectQrPreviewHtml()),
                                        ])
                                        ->compact()
                                        ->collapsed(),
                                ]),
                        ])
                        ->compact(),
                    Section::make('Действия')
                        ->schema([
                            \Filament\Schemas\Components\Actions::make([
                                \Filament\Actions\Action::make('telegram_connect_link')
                                    ->label('Сгенерировать ссылку')
                                    ->icon('heroicon-o-link')
                                    ->color('gray')
                                    ->modalHeading('Ссылка подключения')
                                    ->modalSubmitActionLabel('Сгенерировать')
                                    ->modalWidth('lg')
                                    ->fillForm(function (): array {
                                        $this->telegramConnectModalPayload = $this->issueTelegramConnectPayload();
                                        return ['delivery_channels' => $this->defaultTelegramConnectDeliveryChannels()];
                                    })
                                    ->form([
                                        \Filament\Forms\Components\CheckboxList::make('delivery_channels')
                                            ->label('Куда отправить')
                                            ->options(fn (): array => $this->telegramConnectDeliveryOptions())
                                            ->default(fn (): array => $this->defaultTelegramConnectDeliveryChannels())
                                            ->columns(1)
                                            ->helperText('QR и ссылка показаны выше.'),
                                    ])
                                    ->action(function (array $data): void {
                                        $payload = is_array($this->telegramConnectModalPayload)
                                            ? $this->telegramConnectModalPayload
                                            : $this->issueTelegramConnectPayload();

                                        $deepLink = trim((string) ($payload['deep_link'] ?? ''));
                                        $command = trim((string) ($payload['command'] ?? ''));
                                        $expiresAt = trim((string) ($payload['expires_at'] ?? ''));
                                        $recipientLabel = $this->telegramConnectRecipientLabel();
                                        $shareLink = trim((string) ($payload['share_link'] ?? ''));

                                        $requestedChannelsRaw = is_array($data['delivery_channels'] ?? null)
                                            ? $data['delivery_channels']
                                            : [];
                                        $deliveryChannels = $this->normalizeTelegramConnectDeliveryChannels($requestedChannelsRaw);
                                        $mailRequested = in_array('mail', $requestedChannelsRaw, true);
                                        $mailMissing = $mailRequested && blank($this->record->email);
                                        $deliveryFailed = false;

                                        if ($deliveryChannels !== []) {
                                            try {
                                                $actorName = trim((string) (\Filament\Facades\Filament::auth()->user()?->name ?? 'Система'));
                                                $this->record->notify(new TelegramConnectLinkNotification(
                                                    recipientLabel: $recipientLabel,
                                                    issuedBy: $actorName,
                                                    deepLink: $deepLink,
                                                    command: $command,
                                                    expiresAt: $expiresAt !== '' ? $expiresAt : null,
                                                    shareLink: $shareLink !== '' ? $shareLink : null,
                                                    channels: $deliveryChannels,
                                                ));
                                            } catch (\Throwable $e) {
                                                report($e);
                                                $deliveryFailed = true;
                                            }
                                        }

                                        $bodyParts = ['Получатель: ' . $recipientLabel];
                                        if ($expiresAt !== '') {
                                            $bodyParts[] = 'Действует до: ' . $expiresAt;
                                        }
                                        if ($deliveryChannels !== []) {
                                            $bodyParts[] = 'Каналы: ' . implode(', ', array_map(
                                                fn (string $channel): string => $this->telegramConnectChannelLabel($channel),
                                                $deliveryChannels,
                                            ));
                                        } else {
                                            $bodyParts[] = 'Автоотправка не выбрана.';
                                        }
                                        if ($mailMissing) {
                                            $bodyParts[] = 'Email не заполнен.';
                                        }
                                        if ($deepLink === '') {
                                            $bodyParts[] = 'QR недоступен.';
                                        }

                                        $feedback = \Filament\Notifications\Notification::make()
                                            ->title($deliveryFailed
                                                ? 'Ошибка автоотправки'
                                                : 'Ссылка сгенерирована')
                                            ->body(implode("\n", $bodyParts));

                                        if ($deliveryFailed) {
                                            $feedback->warning();
                                        } else {
                                            $feedback->success();
                                        }

                                        $feedback->send();
                                        $this->telegramConnectModalPayload = null;
                                    }),
                                \Filament\Actions\Action::make('telegram_binding_info')
                                    ->label('Проверить привязку')
                                    ->icon('heroicon-o-identification')
                                    ->color('gray')
                                    ->disabled(fn (): bool => blank($this->record->telegram_chat_id))
                                    ->action(function (): void {
                                        $chatId = trim((string) ($this->record->telegram_chat_id ?? ''));
                                        $profile = is_array($this->record->telegram_profile ?? null)
                                            ? $this->record->telegram_profile
                                            : [];
                                        $username = trim((string) ($profile['username'] ?? ''));
                                        $firstName = trim((string) ($profile['first_name'] ?? ''));
                                        $lastName = trim((string) ($profile['last_name'] ?? ''));
                                        $displayName = trim($firstName . ' ' . $lastName);
                                        $telegramUserId = trim((string) ($profile['id'] ?? ''));
                                        $linkedAt = $this->record->telegram_linked_at?->format('d.m.Y H:i');

                                        $lines = [];
                                        if ($username !== '') {
                                            $lines[] = '@' . $username;
                                        }
                                        if ($displayName !== '') {
                                            $lines[] = $displayName;
                                        }
                                        if ($telegramUserId !== '') {
                                            $lines[] = 'ID: ' . $telegramUserId;
                                        }
                                        if ($linkedAt !== null) {
                                            $lines[] = 'Привязано: ' . $linkedAt;
                                        }

                                        \Filament\Notifications\Notification::make()
                                            ->title('Telegram привязан')
                                            ->body(implode("\n", $lines))
                                            ->success()
                                            ->send();
                                    }),
                                \Filament\Actions\Action::make('telegram_test')
                                    ->label('Тест')
                                    ->icon('heroicon-o-paper-airplane')
                                    ->color('gray')
                                    ->disabled(fn (): bool => blank($this->record->telegram_chat_id))
                                    ->requiresConfirmation()
                                    ->modalHeading('Тест Telegram')
                                    ->modalDescription('Отправить тестовое сообщение?')
                                    ->modalSubmitActionLabel('Отправить')
                                    ->action(function (): void {
                                        $chatId = trim((string) ($this->record->telegram_chat_id ?? ''));
                                        if ($chatId === '') {
                                            \Filament\Notifications\Notification::make()
                                                ->title('chat_id не заполнен')
                                                ->warning()
                                                ->send();
                                            return;
                                        }
                                        try {
                                            $actorName = trim((string) (\Filament\Facades\Filament::auth()->user()?->name ?? 'System'));
                                            $this->record->notify(new TelegramTestNotification($actorName));
                                            \Filament\Notifications\Notification::make()
                                                ->title('Сообщение отправлено')
                                                ->success()
                                                ->send();
                                        } catch (\Throwable $e) {
                                            report($e);
                                            \Filament\Notifications\Notification::make()
                                                ->title('Ошибка отправки')
                                                ->body('Проверьте токен бота и chat_id.')
                                                ->danger()
                                                ->send();
                                        }
                                    }),
                                \Filament\Actions\Action::make('telegram_block_reset')
                                    ->label('Сбросить Telegram')
                                    ->icon('heroicon-o-x-circle')
                                    ->color('danger')
                                    ->requiresConfirmation()
                                    ->modalHeading('Сбросить привязку?')
                                    ->modalDescription('chat_id и данные аккаунта будут удалены.')
                                    ->modalSubmitActionLabel('Сбросить')
                                    ->disabled(fn (): bool => blank($this->record->telegram_chat_id))
                                    ->action(function (): void {
                                        $this->record->forceFill([
                                            'telegram_chat_id' => null,
                                            'telegram_profile' => null,
                                            'telegram_linked_at' => null,
                                        ])->save();
                                        \Filament\Notifications\Notification::make()
                                            ->title('Telegram сброшен')
                                            ->success()
                                            ->send();
                                    }),
                            ])->alignCenter(),
                        ])
                        ->compact(),
                ])
                ->action(function (array $data): void {
                    $chatId = trim((string) ($data['telegram_chat_id'] ?? ''));
                    $chatId = $chatId !== '' ? $chatId : null;

                    $payload = ['telegram_chat_id' => $chatId];
                    if ($chatId === null) {
                        $payload['telegram_profile'] = null;
                        $payload['telegram_linked_at'] = null;
                    }

                    $this->record->forceFill($payload)->save();

                    Notification::make()
                        ->title($chatId === null
                            ? 'Telegram очищен'
                            : 'Telegram сохранён')
                        ->success()
                        ->send();
                }),

            DeleteAction::make()
                ->label('Удалить сотрудника')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->size('lg')
                ->outlined()
                ->extraAttributes([
                    'class' => 'staff-card-action staff-card-action--danger',
                ])
                ->visible(fn (): bool => StaffResource::canDelete($this->record)),
        ];
    }

    /**
     * @return array{token:string,expires_at:string,command:string,deep_link:?string,share_link:?string,qr_svg_data_uri:?string}
     */
    private function issueTelegramConnectPayload(): array
    {
        $payload = app(TelegramChatLinkService::class)->issue($this->record, 20);

        $deepLink = trim((string) ($payload['deep_link'] ?? ''));
        $shareLink = null;
        if ($deepLink !== '') {
            $shareText = 'Подключите Telegram в Market Platform';
            $shareLink = 'https://t.me/share/url?url=' . rawurlencode($deepLink)
                . '&text=' . rawurlencode($shareText);
        }

        $payload['share_link'] = $shareLink;
        $payload['qr_svg_data_uri'] = $deepLink !== ''
            ? app(QrCodeDataUriGenerator::class)->generateSvgDataUri($deepLink)
            : null;

        return $payload;
    }

    private function telegramConnectQrPreviewHtml(): HtmlString
    {
        $payload = is_array($this->telegramConnectModalPayload)
            ? $this->telegramConnectModalPayload
            : $this->issueTelegramConnectPayload();

        $this->telegramConnectModalPayload = $payload;

        $deepLink = trim((string) ($payload['deep_link'] ?? ''));
        $command = trim((string) ($payload['command'] ?? ''));
        $expiresAt = trim((string) ($payload['expires_at'] ?? ''));
        $qrDataUri = trim((string) ($payload['qr_svg_data_uri'] ?? ''));

        $html = '<div class="space-y-2 text-sm">';

        if ($deepLink !== '') {
            $html .= '<div>Ссылка: <a href="' . e($deepLink) . '" target="_blank" rel="noopener noreferrer" class="underline">' . e($deepLink) . '</a></div>';
        } else {
            $html .= '<div class="text-amber-600 dark:text-amber-400">QR и deeplink недоступны: не задан TELEGRAM_BOT_USERNAME.</div>';
        }

        if ($command !== '') {
            $html .= '<div>Команда: <code class="rounded bg-gray-100 px-1 py-0.5 dark:bg-gray-800">' . e($command) . '</code></div>';
        }

        if ($expiresAt !== '') {
            $html .= '<div class="text-gray-500 dark:text-gray-400">Действует до: ' . e($expiresAt) . '</div>';
        }

        if ($qrDataUri !== '') {
            $html .= '<div class="pt-1"><div class="mb-1 text-gray-500 dark:text-gray-400">Сканируйте камерой телефона:</div>'
                . '<div class="inline-flex rounded-lg border border-gray-200 bg-white p-2 dark:border-gray-700 dark:bg-gray-950">'
                . '<img src="' . e($qrDataUri) . '" alt="QR-код подключения Telegram" style="width: 220px; height: 220px; max-width: 100%; display: block;" loading="lazy" />'
                . '</div></div>';
        }

        $html .= '</div>';

        return new HtmlString($html);
    }

    /**
     * @return array<string, string>
     */
    private function telegramConnectDeliveryOptions(): array
    {
        $options = [
            'database' => 'Внутреннее уведомление (колокольчик)',
        ];

        $email = trim((string) ($this->record->email ?? ''));
        if ($email !== '') {
            $options['mail'] = 'Email сотрудника (' . $email . ')';
        }

        return $options;
    }

    /**
     * @return list<string>
     */
    private function defaultTelegramConnectDeliveryChannels(): array
    {
        return array_keys($this->telegramConnectDeliveryOptions());
    }

    /**
     * @param  mixed  $rawChannels
     * @return list<string>
     */
    private function normalizeTelegramConnectDeliveryChannels(mixed $rawChannels): array
    {
        if (! is_array($rawChannels)) {
            return [];
        }

        $allowed = array_keys($this->telegramConnectDeliveryOptions());
        $channels = [];

        foreach ($rawChannels as $channel) {
            if (! is_string($channel)) {
                continue;
            }

            $channel = trim($channel);
            if ($channel === '' || ! in_array($channel, $allowed, true)) {
                continue;
            }

            $channels[] = $channel;
        }

        return array_values(array_unique($channels));
    }

    private function telegramConnectRecipientLabel(): string
    {
        $name = trim((string) ($this->record->name ?? ''));
        $email = trim((string) ($this->record->email ?? ''));

        if ($name !== '' && $email !== '') {
            return $name . ' <' . $email . '>';
        }
        if ($name !== '') {
            return $name;
        }
        if ($email !== '') {
            return $email;
        }

        return 'ID #' . (int) ($this->record->id ?? 0);
    }

    private function telegramConnectChannelLabel(string $channel): string
    {
        return match ($channel) {
            'database' => 'колокольчик',
            'mail' => 'email',
            default => $channel,
        };
    }

    private function canManagePasswordFields(): bool
    {
        $actor = Filament::auth()->user();

        if (! $actor) {
            return false;
        }

        if ($actor->isSuperAdmin()) {
            return true;
        }

        if (! $actor->isMarketAdmin()) {
            return false;
        }

        if (! method_exists($this->record, 'hasRole')) {
            return false;
        }

        if ($this->record->hasRole('super-admin')) {
            return false;
        }

        if ((int) $this->record->id === (int) $actor->id) {
            return true;
        }

        return ! $this->record->hasRole('market-admin');
    }

    private function notificationPreferencesFormState(): array
    {
        $raw = (array) ($this->record->notification_preferences ?? []);
        $normalized = app(UserNotificationPreferences::class)->normalizeForStorage(
            $raw,
            (bool) ($raw['self_manage'] ?? false),
        );

        return [
            'notification_preferences' => [
                'self_manage' => (bool) ($normalized['self_manage'] ?? false),
                'channels' => array_values((array) ($normalized['channels'] ?? [])),
                'topics' => array_values((array) ($normalized['topics'] ?? [])),
            ],
        ];
    }

    private function normalizeNotificationPreferences(array $data): array
    {
        $preferences = app(UserNotificationPreferences::class);

        $roleIds = array_values(array_filter(
            (array) ($data['roles'] ?? []),
            static fn ($value): bool => is_numeric($value),
        ));

        $roleNames = $roleIds === []
            ? []
            : Role::query()->whereIn('id', $roleIds)->pluck('name')->all();

        $mustSelfManage = in_array('super-admin', $roleNames, true)
            || in_array('market-admin', $roleNames, true);

        $existing = (array) ($this->record->notification_preferences ?? []);
        $raw = is_array($data['notification_preferences'] ?? null)
            ? $data['notification_preferences']
            : [];

        $raw['self_manage'] = $mustSelfManage
            || (bool) ($raw['self_manage'] ?? $existing['self_manage'] ?? false);

        $data['notification_preferences'] = $preferences->normalizeForStorage(
            $raw,
            (bool) $raw['self_manage'],
            UserNotificationPreferences::defaultTopicsForRoleNames($roleNames)
        );

        return $data;
    }
}
