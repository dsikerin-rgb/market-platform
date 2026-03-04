<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Support\NotificationDeliveryLogger;
use Illuminate\Notifications\Events\NotificationSent;

class LogNotificationSent
{
    public function __construct(
        private readonly NotificationDeliveryLogger $logger
    ) {
    }

    public function handle(NotificationSent $event): void
    {
        $this->logger->logSent($event);
    }
}

