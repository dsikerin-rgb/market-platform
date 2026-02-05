<?php

# app/Http/Middleware/SplitSessionCookieByPath.php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class SplitSessionCookieByPath
{
    /**
     * Разносим cookie сессии для разных зон сайта:
     * - /cabinet/* → SESSION_COOKIE_CABINET (или market_cabinet_session)
     * - /admin/*   → SESSION_COOKIE_ADMIN   (или market_admin_session)
     *
     * ВАЖНО: этот middleware должен стоять ДО StartSession.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $path = (string) $request->path(); // без ведущего "/"

        if ($path === 'cabinet' || str_starts_with($path, 'cabinet/')) {
            config([
                'session.cookie' => (string) (env('SESSION_COOKIE_CABINET') ?: 'market_cabinet_session'),
            ]);
        } elseif ($path === 'admin' || str_starts_with($path, 'admin/')) {
            config([
                'session.cookie' => (string) (env('SESSION_COOKIE_ADMIN') ?: 'market_admin_session'),
            ]);
        }

        return $next($request);
    }
}
