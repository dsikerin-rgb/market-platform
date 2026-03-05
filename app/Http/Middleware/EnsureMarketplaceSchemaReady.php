<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class EnsureMarketplaceSchemaReady
{
    /**
     * @var array<int, string>
     */
    private array $requiredTables = [
        'marketplace_categories',
        'marketplace_products',
        'marketplace_announcements',
        'marketplace_favorites',
        'marketplace_chats',
        'marketplace_chat_messages',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $missing = [];
        foreach ($this->requiredTables as $table) {
            if (! Schema::hasTable($table)) {
                $missing[] = $table;
            }
        }

        if ($missing !== []) {
            return response()->view('marketplace.setup-required', [
                'missingTables' => $missing,
            ], 503);
        }

        return $next($request);
    }
}

