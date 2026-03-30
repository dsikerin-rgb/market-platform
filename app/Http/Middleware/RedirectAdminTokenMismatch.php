<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;

class RedirectAdminTokenMismatch
{
    public function handle(Request $request, Closure $next): mixed
    {
        try {
            return $next($request);
        } catch (TokenMismatchException $exception) {
            if (! $this->isAdminContext($request)) {
                throw $exception;
            }

            $loginUrl = Filament::getLoginUrl() ?? url('/admin/login');

            return redirect()
                ->to($loginUrl)
                ->with('status', 'Сессия истекла, войдите снова.');
        }
    }

    private function isAdminContext(Request $request): bool
    {
        if ($request->is('admin') || $request->is('admin/*')) {
            return true;
        }

        if (! $request->is('livewire/update')) {
            return false;
        }

        $referer = (string) $request->headers->get('referer', '');

        return str_contains($referer, '/admin');
    }
}
