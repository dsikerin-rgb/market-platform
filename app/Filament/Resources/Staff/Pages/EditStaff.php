<?php

namespace App\Filament\Resources\Staff\Pages;

use App\Filament\Resources\Pages\BaseEditRecord;
use App\Filament\Resources\Staff\StaffResource;
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
                ->visible(fn () => (bool) $user && ($user->isSuperAdmin() || $user->isMarketAdmin()))
                ->action(function (): void {
                    $payload = app(TelegramChatLinkService::class)->issue($this->record, 20);

                    $deepLink = trim((string) ($payload['deep_link'] ?? ''));
                    $command = trim((string) ($payload['command'] ?? ''));
                    $expiresAt = trim((string) ($payload['expires_at'] ?? ''));
                    $shareLink = '';
                    if ($deepLink !== '') {
                        $shareText = 'Подключите Telegram в Market Platform';
                        $shareLink = 'https://t.me/share/url?url=' . rawurlencode($deepLink)
                            . '&text=' . rawurlencode($shareText);
                    }

                    $bodyParts = [];
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

                    Notification::make()
                        ->title('Токен подключения Telegram сгенерирован')
                        ->body(implode("\n", $bodyParts))
                        ->success()
                        ->send();
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
