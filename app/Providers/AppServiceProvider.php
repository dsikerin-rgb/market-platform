<?php
# app/Providers/AppServiceProvider.php

namespace App\Providers;

use App\Listeners\NotifySuperAdminsAboutUserLogin;
use App\Models\IntegrationExchange;
use App\Models\Task;
use App\Observers\IntegrationExchangeObserver;
use App\Policies\TaskPolicy;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Task::class, TaskPolicy::class);
        IntegrationExchange::observe(IntegrationExchangeObserver::class);

        Event::listen(Login::class, NotifySuperAdminsAboutUserLogin::class);
    }
}
