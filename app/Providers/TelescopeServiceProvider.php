<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\TelescopeApplicationServiceProvider;

class TelescopeServiceProvider extends TelescopeApplicationServiceProvider
{
    /**
     * Cache key that controls whether Telescope recording is enabled in non-local environments.
     *
     * Value: UNIX timestamp (int) until which recording is enabled.
     * TTL:  should match the "until" timestamp (auto-disable).
     */
    public const CACHE_KEY_ENABLED_UNTIL = 'ops:telescope:enabled_until';

    /**
     * Default time window (minutes) for temporary enablement.
     */
    public const DEFAULT_ENABLE_MINUTES = 30;

    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Telescope::night();

        $this->hideSensitiveRequestDetails();

        $isLocal = $this->app->environment('local');
        $opsEnabled = $isLocal || $this->isTemporarilyEnabled();

        /**
         * Important:
         * - In local: always record.
         * - In non-local: record only when explicitly enabled (TTL in cache).
         *
         * This keeps Telescope installed and accessible to super-admins,
         * while preventing background recording overhead on production by default.
         */
        if ($opsEnabled) {
            Telescope::startRecording();
        } else {
            Telescope::stopRecording();
        }

        // In non-local environments, when enabled, record everything (for diagnosis).
        // When disabled, keep the filter narrow (defensive; recording is stopped anyway).
        Telescope::filter(function (IncomingEntry $entry) use ($isLocal, $opsEnabled) {
            if ($isLocal || $opsEnabled) {
                return true;
            }

            // В non-local окружениях (когда Telescope "выключен") логируем только «сигнальные» события,
            // чтобы не раздувать БД (особенно при SQLite).
            return $entry->isReportableException()
                || $entry->isFailedRequest()
                || $entry->isFailedJob()
                || $entry->isScheduledTask()
                || $entry->hasMonitoredTag();
        });
    }

    /**
     * Returns the time until which Telescope recording is enabled (non-local), or null if disabled.
     */
    public static function enabledUntil(): ?Carbon
    {
        $ts = Cache::get(self::CACHE_KEY_ENABLED_UNTIL);

        return $ts ? Carbon::createFromTimestamp((int) $ts) : null;
    }

    /**
     * Enable Telescope recording for a limited time window.
     * Intended to be called from ops UI action (with confirmation).
     */
    public static function enableForMinutes(int $minutes = self::DEFAULT_ENABLE_MINUTES): Carbon
    {
        $minutes = max(1, min(24 * 60, $minutes)); // 1 minute .. 24 hours

        $until = now()->addMinutes($minutes);

        // Store "until" timestamp with TTL that expires at the same moment.
        Cache::put(self::CACHE_KEY_ENABLED_UNTIL, $until->getTimestamp(), $until);

        return $until;
    }

    /**
     * Disable Telescope recording immediately.
     */
    public static function disable(): void
    {
        Cache::forget(self::CACHE_KEY_ENABLED_UNTIL);
    }

    /**
     * Determine whether Telescope is temporarily enabled (non-local).
     */
    protected function isTemporarilyEnabled(): bool
    {
        $until = self::enabledUntil();

        return $until !== null && $until->isFuture();
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
