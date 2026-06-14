<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class TrackAdminUserPresence
{
    public function handle(Request $request, Closure $next): Response
    {
        $this->touchLastSeen();

        return $next($request);
    }

    private function touchLastSeen(): void
    {
        $user = Auth::user();

        if (! $user) {
            return;
        }

        static $hasColumn = null;
        $hasColumn ??= Schema::hasColumn('users', 'last_seen_at');

        if (! $hasColumn) {
            return;
        }

        $lastSeenAt = $user->last_seen_at;
        if ($lastSeenAt && $lastSeenAt->greaterThan(now()->subMinute())) {
            return;
        }

        DB::table('users')
            ->where('id', $user->getAuthIdentifier())
            ->update(['last_seen_at' => now()]);
    }
}
