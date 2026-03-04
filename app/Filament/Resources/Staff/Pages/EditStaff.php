<?php

namespace App\Filament\Resources\Staff\Pages;

use App\Filament\Resources\Pages\BaseEditRecord;
use App\Filament\Resources\Staff\StaffResource;
use App\Notifications\TelegramConnectLinkNotification;
use App\Notifications\TelegramTestNotification;
use App\Support\TelegramChatLinkService;
use App\Support\UserNotificationPreferences;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Spatie\Permission\Models\Role;

class EditStaff extends BaseEditRecord
{
    protected static string $resource = StaffResource::class;

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

        if ($user->isSuperAdmin() || $user->isMarketAdmin()) {
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
            Action::make('telegram_connect_link')
                ->label('Telegram ссылка')
                ->icon('heroicon-o-link')
                ->color('gray')
                ->tooltip('Сгенерировать одноразовую ссылку /start для сотрудника')
                ->modalHeading('Ссылка подключения Telegram')
                ->modalSubmitActionLabel('Сгенерировать')
                ->visible(fn () => (bool) $user && ($user->isSuperAdmin() || $user->isMarketAdmin()))
                ->form([
                    \Filament\Forms\Components\CheckboxList::make('delivery_channels')
                        ->label('Отправить сотруднику')
                        ->options(fn (): array => $this->telegramConnectDeliveryOptions())
                        ->default(fn (): array => $this->defaultTelegramConnectDeliveryChannels())
                        ->columns(1)
                        ->helperText('Ссылка всё равно будет показана в уведомлении после генерации.'),
                ])
                ->action(function (array $data): void {
                    $payload = app(TelegramChatLinkService::class)->issue($this->record, 20);

                    $deepLink = trim((string) ($payload['deep_link'] ?? ''));
                    $command = trim((string) ($payload['command'] ?? ''));
                    $expiresAt = trim((string) ($payload['expires_at'] ?? ''));
                    $recipientLabel = $this->telegramConnectRecipientLabel();
                    $shareLink = '';
                    if ($deepLink !== '') {
                        $shareText = 'Подключите Telegram в Market Platform';
                        $shareLink = 'https://t.me/share/url?url=' . rawurlencode($deepLink)
                            . '&text=' . rawurlencode($shareText);
                    }

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

                    $bodyParts = [];
                    $bodyParts[] = 'Получатель: ' . $recipientLabel;
                    if ($deepLink !== '') {
                        $bodyParts[] = 'Ссылка: ' . $deepLink;
                    }
                    if ($command !== '') {
                        $bodyParts[] = 'Команда: ' . $command;
                    }
                    if ($expiresAt !== '') {
                        $bodyParts[] = 'Действует до: ' . $expiresAt;
                    }
                    if ($shareLink !== '') {
                        $bodyParts[] = 'Поделиться: ' . $shareLink;
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
                }),

            Action::make('telegram_binding_info')
                ->label('Проверить привязку')
                ->icon('heroicon-o-identification')
                ->color('gray')
                ->tooltip('Показать, какой Telegram-аккаунт привязан к сотруднику')
                ->visible(fn () => (bool) $user && ($user->isSuperAdmin() || $user->isMarketAdmin()))
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
                ->visible(fn () => (bool) $user && ($user->isSuperAdmin() || $user->isMarketAdmin()))
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
                ->visible(fn () => (bool) $user && ($user->isSuperAdmin() || $user->isMarketAdmin()))
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
                ->visible(fn () => (bool) $user && $user->isSuperAdmin()),
        ];
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
            (bool) $raw['self_manage']
        );

        return $data;
    }
}
