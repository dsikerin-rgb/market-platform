<?php

declare(strict_types=1);

namespace App\Providers;

use App\Listeners\LogNotificationFailed;
use App\Listeners\LogNotificationSent;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class NotificationAuditServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Event::listen(NotificationSent::class, LogNotificationSent::class);
        Event::listen(NotificationFailed::class, LogNotificationFailed::class);
    }
}

