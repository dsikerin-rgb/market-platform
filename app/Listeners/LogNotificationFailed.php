<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Support\NotificationDeliveryLogger;
use Illuminate\Notifications\Events\NotificationFailed;

class LogNotificationFailed
{
    public function __construct(
        private readonly NotificationDeliveryLogger $logger
    ) {
    }

    public function handle(NotificationFailed $event): void
    {
        $this->logger->logFailed($event);
    }
}

