<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\User;
use App\Notifications\UserLoggedInNotification;
use App\Support\UserNotificationPreferences;
use Filament\Facades\Filament;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class NotifySuperAdminsAboutUserLogin
{
    public function __construct(
        private readonly Request $request,
    ) {
    }

    public function handle(Login $event): void
    {
        $actor = $event->user;

        if (! $actor instanceof User) {
            return;
        }

        if (! $this->isAdminPanelLoginRequest()) {
            return;
        }

        if ($this->isImpersonationLoginRequest()) {
            return;
        }

        if ($this->isDuplicateLoginNotification($actor)) {
            return;
        }

        $notification = $this->makeNotification($actor);

        if (! $actor->isSuperAdmin() && $this->canReceiveSecurityNotifications($actor)) {
            $this->safeNotify($actor, $notification);
        }

        $superAdmins = User::query()
            ->whereHas('roles', fn ($query) => $query->where('name', 'super-admin'))
            ->whereKeyNot($actor->getKey())
            ->get();

        if ($superAdmins->isEmpty()) {
            return;
        }

        foreach ($superAdmins as $superAdmin) {
            if (! $this->canReceiveSecurityNotifications($superAdmin)) {
                continue;
            }

            $this->safeNotify($superAdmin, $notification);
        }
    }

    private function safeNotify(User $recipient, UserLoggedInNotification $notification): void
    {
        try {
            $recipient->notify($notification);
        } catch (Throwable $e) {
            Log::warning('Login notification failed; request continues.', [
                'recipient_id' => (int) $recipient->getKey(),
                'recipient_class' => $recipient::class,
                'notification' => $notification::class,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function makeNotification(User $actor): UserLoggedInNotification
    {
        return new UserLoggedInNotification(
            actor: $actor,
            ip: trim((string) ($this->request->ip() ?? '')),
            userAgent: trim((string) ($this->request->userAgent() ?? '')),
        );
    }

    private function isAdminPanelLoginRequest(): bool
    {
        $path = trim($this->request->path(), '/');

        if ($path !== '' && ($path === 'admin' || str_starts_with($path, 'admin/'))) {
            return true;
        }

        $routeName = (string) ($this->request->route()?->getName() ?? '');

        if ($routeName !== '' && str_starts_with($routeName, 'filament.admin.')) {
            return true;
        }

        $panelPath = trim((string) (Filament::getPanel('admin')?->getPath() ?? 'admin'), '/');

        if ($panelPath === '') {
            return false;
        }

        $referer = trim((string) $this->request->headers->get('referer', ''));

        if ($referer === '') {
            return false;
        }

        $refererPath = trim((string) parse_url($referer, PHP_URL_PATH), '/');

        return $refererPath === $panelPath || str_starts_with($refererPath, $panelPath . '/');
    }

    private function isImpersonationLoginRequest(): bool
    {
        $routeName = (string) ($this->request->route()?->getName() ?? '');

        if ($routeName === 'cabinet.impersonate.consume') {
            return true;
        }

        $path = trim($this->request->path(), '/');

        return $path === 'cabinet/impersonate' || str_starts_with($path, 'cabinet/impersonate/');
    }

    private function isDuplicateLoginNotification(User $actor): bool
    {
        $parts = [
            'login-notify',
            (string) $actor->getKey(),
            trim((string) ($this->request->ip() ?? '')),
            trim((string) ($this->request->userAgent() ?? '')),
            trim((string) $this->request->headers->get('referer', '')),
            trim((string) $this->request->path()),
            (string) floor(time() / 5),
        ];

        $key = 'auth:' . sha1(implode('|', $parts));

        return ! Cache::add($key, true, now()->addSeconds(5));
    }

    private function canReceiveSecurityNotifications(User $user): bool
    {
        return app(UserNotificationPreferences::class)->isTopicEnabled(
            $user,
            UserNotificationPreferences::TOPIC_SECURITY,
        );
    }
}
