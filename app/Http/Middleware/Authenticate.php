<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;

class Authenticate extends Middleware
{
    protected function redirectTo(Request $request): ?string
    {
        if ($request->expectsJson()) {
            return null;
        }

        if ($request->is('cabinet/*') || $request->is('cabinet')) {
            return route('cabinet.login');
        }

        if ($request->is('admin/*')) {
            return route('filament.admin.auth.login');
        }

        return route('login');
    }
}
