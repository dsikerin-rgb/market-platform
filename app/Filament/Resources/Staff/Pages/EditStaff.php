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
                ->label('Telegram link')
                ->icon('heroicon-o-link')
                ->color('gray')
                ->tooltip('Generate one-time /start token for this user')
                ->visible(fn () => (bool) $user && ($user->isSuperAdmin() || $user->isMarketAdmin()))
                ->action(function (): void {
                    $payload = app(TelegramChatLinkService::class)->issue($this->record, 20);

                    $deepLink = trim((string) ($payload['deep_link'] ?? ''));
                    $command = trim((string) ($payload['command'] ?? ''));
                    $expiresAt = trim((string) ($payload['expires_at'] ?? ''));

                    $bodyParts = [];
                    if ($deepLink !== '') {
                        $bodyParts[] = 'Link: ' . $deepLink;
                    }
                    if ($command !== '') {
                        $bodyParts[] = 'Command: ' . $command;
                    }
                    if ($expiresAt !== '') {
                        $bodyParts[] = 'Expires at: ' . $expiresAt;
                    }

                    Notification::make()
                        ->title('Telegram connect token generated')
                        ->body(implode("\n", $bodyParts))
                        ->success()
                        ->send();
                }),

            Action::make('telegram_test')
                ->label('Telegram test')
                ->icon('heroicon-o-paper-airplane')
                ->color('gray')
                ->tooltip(fn (): string => blank($this->record->telegram_chat_id)
                    ? 'Fill Telegram (chat_id) first'
                    : 'Send test message to Telegram')
                ->disabled(fn (): bool => blank($this->record->telegram_chat_id))
                ->requiresConfirmation()
                ->modalHeading('Send Telegram test')
                ->modalDescription('A test message will be sent to the staff member chat_id.')
                ->visible(fn () => (bool) $user && ($user->isSuperAdmin() || $user->isMarketAdmin()))
                ->action(function (): void {
                    $chatId = trim((string) ($this->record->telegram_chat_id ?? ''));
                    if ($chatId === '') {
                        Notification::make()
                            ->title('Telegram chat_id is empty')
                            ->warning()
                            ->send();

                        return;
                    }

                    try {
                        $actorName = trim((string) (Filament::auth()->user()?->name ?? 'System'));
                        $this->record->notify(new TelegramTestNotification($actorName));

                        Notification::make()
                            ->title('Telegram test sent')
                            ->body('Check Telegram for the target user.')
                            ->success()
                            ->send();
                    } catch (\Throwable $e) {
                        report($e);

                        Notification::make()
                            ->title('Telegram send failed')
                            ->body('Check bot token and chat_id settings.')
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
