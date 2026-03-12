<?php

namespace App\Filament\Resources\Staff\Pages;

use App\Filament\Resources\Pages\BaseEditRecord;
use App\Filament\Resources\Staff\StaffResource;
use App\Notifications\TelegramConnectLinkNotification;
use App\Notifications\TelegramTestNotification;
use App\Support\QrCodeDataUriGenerator;
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
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\HtmlString;
use Spatie\Permission\Models\Role;

class EditStaff extends BaseEditRecord
{
    protected static string $resource = StaffResource::class;

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
            Action::make('password_settings')
                ->label('Пароль')
                ->icon('heroicon-o-key')
                ->color('gray')
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

            Action::make('telegram_settings')
                ->label('Настройки Telegram')
                ->icon('heroicon-o-chat-bubble-left-right')
                ->color('gray')
                ->modalHeading('Telegram')
                ->modalSubmitActionLabel('Сохранить')
                ->visible(false)
                ->fillForm(fn (): array => [
                    'telegram_chat_id' => $this->record->telegram_chat_id,
                ])
                ->form([
                    Forms\Components\TextInput::make('telegram_chat_id')
                        ->label('Telegram (chat_id)')
                        ->placeholder('например: 123456789')
                        ->helperText('Нужен для доставки уведомлений в Telegram.')
                        ->maxLength(32)
                        ->regex('/^-?\d+$/')
                        ->validationMessages([
                            'regex' => 'Используйте только цифры и, при необходимости, знак "-" в начале.',
                        ]),
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
                            ? 'Telegram (chat_id) очищен'
                            : 'Telegram (chat_id) сохранен')
                        ->success()
                        ->send();
                }),

            Action::make('notification_settings')
                ->label('Уведомления')
                ->icon('heroicon-o-bell')
                ->color('gray')
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

            ActionGroup::make([
                Action::make('telegram_block_settings')
                    ->label('Настройки')
                    ->icon('heroicon-o-cog-6-tooth')
                    ->action(fn (): mixed => $this->mountAction('telegram_settings')),
                Action::make('telegram_block_connect')
                    ->label('Telegram ссылка')
                    ->icon('heroicon-o-link')
                    ->action(fn (): mixed => $this->mountAction('telegram_connect_link')),
                Action::make('telegram_block_binding')
                    ->label('Проверить привязку')
                    ->icon('heroicon-o-identification')
                    ->action(fn (): mixed => $this->mountAction('telegram_binding_info')),
                Action::make('telegram_block_test')
                    ->label('Telegram тест')
                    ->icon('heroicon-o-paper-airplane')
                    ->disabled(fn (): bool => blank($this->record->telegram_chat_id))
                    ->action(fn (): mixed => $this->mountAction('telegram_test')),
                Action::make('telegram_block_reset')
                    ->label('Сбросить Telegram')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->disabled(fn (): bool => blank($this->record->telegram_chat_id))
                    ->action(fn (): mixed => $this->mountAction('telegram_unlink')),
            ])
                ->label('Telegram')
                ->icon('heroicon-o-chat-bubble-left-right')
                ->color('gray')
                ->visible(fn (): bool => (bool) $user && ($user->isSuperAdmin() || $user->isMarketAdmin())),

            Action::make('telegram_connect_link')
                ->label('Telegram ссылка')
                ->icon('heroicon-o-link')
                ->color('gray')
                ->tooltip('Сгенерировать одноразовую ссылку /start для сотрудника')
                ->modalHeading('Ссылка подключения Telegram')
                ->modalSubmitActionLabel('Сгенерировать')
                ->visible(false)
                ->fillForm(function (): array {
                    $this->telegramConnectModalPayload = $this->issueTelegramConnectPayload();

                    return [
                        'delivery_channels' => $this->defaultTelegramConnectDeliveryChannels(),
                    ];
                })
                ->form([
                    \Filament\Forms\Components\CheckboxList::make('delivery_channels')
                        ->label('Отправить сотруднику')
                        ->options(fn (): array => $this->telegramConnectDeliveryOptions())
                        ->default(fn (): array => $this->defaultTelegramConnectDeliveryChannels())
                        ->columns(1)
                        ->helperText('Ссылка и QR показаны в блоке ниже.'),

                    \Filament\Forms\Components\Placeholder::make('telegram_connect_qr_preview')
                        ->label('Быстрое подключение (QR)')
                        ->content(fn () => $this->telegramConnectQrPreviewHtml()),
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
                            $actorName = trim((string) (Filament::auth()->user()?->name ?? 'Система'));
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
                        $bodyParts[] = 'Доставлено по каналам: ' . implode(', ', array_map(
                            fn (string $channel): string => $this->telegramConnectChannelLabel($channel),
                            $deliveryChannels,
                        ));
                    } else {
                        $bodyParts[] = 'Автоотправка не выбрана, передайте ссылку вручную.';
                    }
                    if ($mailMissing) {
                        $bodyParts[] = 'Канал Email пропущен: у сотрудника не заполнен email.';
                    }
                    if ($deepLink === '') {
                        $bodyParts[] = 'QR недоступен: проверьте TELEGRAM_BOT_USERNAME и очистите кэш конфигурации.';
                    }

                    $feedback = Notification::make()
                        ->title($deliveryFailed
                            ? 'Ссылка сгенерирована, но автоотправка завершилась с ошибкой'
                            : 'Ссылка подключения Telegram сгенерирована')
                        ->body(implode("\n", $bodyParts));

                    if ($deliveryFailed) {
                        $feedback->warning();
                    } else {
                        $feedback->success();
                    }

                    $feedback->send();
                    $this->telegramConnectModalPayload = null;
                }),

            Action::make('telegram_binding_info')
                ->label('Проверить привязку')
                ->icon('heroicon-o-identification')
                ->color('gray')
                ->tooltip('Показать, какой Telegram-аккаунт привязан к сотруднику')
                ->visible(false)
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
                    $linkedAt = $this->record->telegram_linked_at?->format('Y-m-d H:i:s');

                    $lines = ['chat_id: ' . $chatId];
                    if ($username !== '') {
                        $lines[] = 'Аккаунт: @' . $username;
                    }
                    if ($displayName !== '') {
                        $lines[] = 'Имя в Telegram: ' . $displayName;
                    }
                    if ($telegramUserId !== '') {
                        $lines[] = 'Telegram user id: ' . $telegramUserId;
                    }
                    if ($linkedAt !== null) {
                        $lines[] = 'Привязано: ' . $linkedAt;
                    }

                    Notification::make()
                        ->title('Текущая Telegram-привязка')
                        ->body(implode("\n", $lines))
                        ->success()
                        ->send();
                }),

            Action::make('telegram_unlink')
                ->label('Сбросить Telegram')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->tooltip('Очистить Telegram-привязку сотрудника')
                ->requiresConfirmation()
                ->modalHeading('Сбросить привязку Telegram?')
                ->modalDescription('Будут очищены chat_id и информация о связанном Telegram-аккаунте.')
                ->visible(false)
                ->disabled(fn (): bool => blank($this->record->telegram_chat_id))
                ->action(function (): void {
                    $this->record->forceFill([
                        'telegram_chat_id' => null,
                        'telegram_profile' => null,
                        'telegram_linked_at' => null,
                    ])->save();

                    Notification::make()
                        ->title('Telegram-привязка сброшена')
                        ->success()
                        ->send();
                }),

            Action::make('telegram_test')
                ->label('Telegram тест')
                ->icon('heroicon-o-paper-airplane')
                ->color('gray')
                ->tooltip(fn (): string => blank($this->record->telegram_chat_id)
                    ? 'Сначала заполните Telegram (chat_id)'
                    : 'Отправить тестовое сообщение в Telegram')
                ->disabled(fn (): bool => blank($this->record->telegram_chat_id))
                ->requiresConfirmation()
                ->modalHeading('Отправить Telegram тест')
                ->modalDescription('Тестовое сообщение будет отправлено по chat_id сотрудника.')
                ->visible(false)
                ->action(function (): void {
                    $chatId = trim((string) ($this->record->telegram_chat_id ?? ''));
                    if ($chatId === '') {
                        Notification::make()
                            ->title('У сотрудника не заполнен Telegram (chat_id)')
                            ->warning()
                            ->send();

                        return;
                    }

                    try {
                        $actorName = trim((string) (Filament::auth()->user()?->name ?? 'System'));
                        $this->record->notify(new TelegramTestNotification($actorName));

                        Notification::make()
                            ->title('Тестовое сообщение отправлено')
                            ->body('Проверьте Telegram у выбранного сотрудника.')
                            ->success()
                            ->send();
                    } catch (\Throwable $e) {
                        report($e);

                        Notification::make()
                            ->title('Не удалось отправить в Telegram')
                            ->body('Проверьте токен бота и chat_id сотрудника.')
                            ->danger()
                            ->send();
                    }
                }),

            DeleteAction::make()
                ->label('Удалить сотрудника')
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
