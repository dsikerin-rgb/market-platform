<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        /**
         * IMPORTANT:
         * По умолчанию Horizon разрешает доступ в local-окружении.
         * Нам нужно единое правило для всех окружений: только через gate viewHorizon.
         */
        Horizon::auth(function ($request): bool {
            return Gate::check('viewHorizon', [$request->user()]);
        });

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function ($user = null): bool {
            if (! $user) {
                return false;
            }

            // Prefer central policy on the User model (single source of truth).
            if ($user instanceof User && method_exists($user, 'canAccessHorizon')) {
                return $user->canAccessHorizon();
            }

            // Fallbacks (defensive) for edge cases / legacy users.
            if (method_exists($user, 'hasRole') && $user->hasRole('super-admin')) {
                return true;
            }

            if (isset($user->is_super_admin)) {
                return (bool) $user->is_super_admin;
            }

            return false;
        });
    }
}
