<?php

declare(strict_types=1);

return [
    'home' => [
        // Fast rollback toggle: false returns sanitary_day cards into main feed.
        'hide_sanitary_in_feed' => env('MARKETPLACE_HOME_HIDE_SANITARY_IN_FEED', true),

        // Fast rollback toggle: false hides the dedicated nearest sanitary warning block.
        'sanitary_warning_enabled' => env('MARKETPLACE_HOME_SANITARY_WARNING_ENABLED', true),
    ],
];
