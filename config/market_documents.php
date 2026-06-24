<?php

declare(strict_types=1);

return [
    'disk' => env('MARKET_DOCUMENTS_DISK', 'public'),
    'directory' => env('MARKET_DOCUMENTS_DIRECTORY', 'market-documents'),
    'trash_retention_days' => (int) env('MARKET_DOCUMENTS_TRASH_RETENTION_DAYS', 7),
];
