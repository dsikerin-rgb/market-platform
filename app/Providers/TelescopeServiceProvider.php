<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\TelescopeApplicationServiceProvider;

class TelescopeServiceProvider extends TelescopeApplicationServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Telescope::night();

        $this->hideSensitiveRequestDetails();

        $isLocal = $this->app->environment('local');

        // В non-local окружениях логируем только «сигнальные» события,
        // чтобы не раздувать БД (особенно при SQLite).
        Telescope::filter(function (IncomingEntry $entry) use ($isLocal) {
            return $isLocal
                || $entry->isReportableException()
                || $entry->isFailedRequest()
                || $entry->isFailedJob()
                || $entry->isScheduledTask()
                || $entry->hasMonitoredTag();
        });
    }

    /**
     * Prevent sensitive request details from being logged by Telescope.
     */
    protected function hideSensitiveRequestDetails(): void
    {
        if ($this->app->environment('local')) {
            return;
        }

        Telescope::hideRequestParameters(['_token']);

        Telescope::hideRequestHeaders([
            'cookie',
            'authorization',
            'x-csrf-token',
            'x-xsrf-token',
        ]);
    }

    /**
     * Override default Telescope auth behavior.
     *
     * По умолчанию Telescope разрешает доступ всем в local окружении.
     * Нам нужна единая политика (local/staging/prod): доступ только super-admin.
     */
    protected function authorization(): void
    {
        Telescope::auth(function ($request): bool {
            $user = $request->user();

            if (! $user) {
                return false;
            }

            // Центральное правило: только super-admin.
            if ($user instanceof User && method_exists($user, 'isSuperAdmin')) {
                return $user->isSuperAdmin();
            }

            // Defensive fallbacks.
            if (method_exists($user, 'hasRole')) {
                return (bool) $user->hasRole('super-admin');
            }

            return false;
        });
    }

    /**
     * Register the Telescope gate.
     *
     * Оставляем gate как источник истины для политики доступа.
     */
    protected function gate(): void
    {
        Gate::define('viewTelescope', function ($user = null): bool {
            if (! $user) {
                return false;
            }

            if ($user instanceof User && method_exists($user, 'isSuperAdmin')) {
                return $user->isSuperAdmin();
            }

            if (method_exists($user, 'hasRole')) {
                return (bool) $user->hasRole('super-admin');
            }

            return false;
        });
    }
}
