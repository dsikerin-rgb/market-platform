<?php

namespace App\Http\Controllers\Cabinet;

use App\Http\Controllers\Controller;
use App\Models\Market;
use App\Models\User;
use App\Services\Auth\PortalAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\View\View;

class CabinetAuthController extends Controller
{
    public function showLogin(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        $access = app(PortalAccessService::class);

        if ($user && $access->canUseSellerCabinet($user)) {
            $request->session()->put(PortalAccessService::SESSION_ACTIVE_MODE, PortalAccessService::MODE_SELLER);

            return redirect()->route('cabinet.dashboard');
        }

        if ($user && $access->canUseMarketplaceBuyer($user, $access->resolveUserMarket($user))) {
            $marketSlug = $access->resolveUserMarketRouteKey($user);
            if ($marketSlug !== null) {
                $request->session()->put(PortalAccessService::SESSION_ACTIVE_MODE, PortalAccessService::MODE_BUYER);

                return redirect()->route('marketplace.buyer.dashboard', ['marketSlug' => $marketSlug]);
            }
        }

        if ($user && method_exists($user, 'hasAnyRole') && $user->hasAnyRole([
            'super-admin',
            'market-admin',
            'market-manager',
            'market-operator',
        ])) {
            return redirect('/admin');
        }

        return view('cabinet.auth.login', [
            'marketName' => $this->resolveLoginMarketName($request, $user),
        ]);
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);
        $access = app(PortalAccessService::class);

        $remember = $request->boolean('remember');
        $email = Str::lower(trim((string) ($credentials['email'] ?? '')));
        $throttleKey = $email . '|' . $request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            return back()
                ->withInput($request->only('email'))
                ->withErrors([
                    'email' => 'Слишком много попыток входа. Повторите через ' . $seconds . ' сек.',
                ]);
        }

        $user = User::query()
            ->with('tenant:id,market_id')
            ->whereRaw('LOWER(email) = ?', [$email])
            ->first();

        if (! $user || ! Hash::check((string) $credentials['password'], (string) $user->password)) {
            RateLimiter::hit($throttleKey, 60);

            return back()
                ->withInput($request->only('email'))
                ->withErrors(['email' => 'Неверный email или пароль.']);
        }

        if (! $access->canUseSellerCabinet($user)) {
            RateLimiter::hit($throttleKey, 60);

            return back()
                ->withInput($request->only('email'))
                ->withErrors([
                    'email' => 'Для этого аккаунта кабинет арендатора недоступен.',
                ]);
        }

        Auth::login($user, $remember);
        RateLimiter::clear($throttleKey);
        $request->session()->regenerate();
        $request->session()->put(PortalAccessService::SESSION_ACTIVE_MODE, PortalAccessService::MODE_SELLER);

        return redirect()->intended(route('cabinet.dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->forget(PortalAccessService::SESSION_ACTIVE_MODE);
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('cabinet.login');
    }

    private function resolveLoginMarketName(Request $request, ?User $currentUser): string
    {
        $marketId = null;

        if ($currentUser && $currentUser->market_id) {
            $marketId = (int) $currentUser->market_id;
        }

        if (! $marketId) {
            $oldEmail = Str::lower(trim((string) $request->old('email', '')));

            if ($oldEmail !== '') {
                $candidate = User::query()
                    ->with('tenant:id,market_id')
                    ->whereRaw('LOWER(email) = ?', [$oldEmail])
                    ->first();

                if ($candidate) {
                    $marketId = (int) ($candidate->market_id ?: ($candidate->tenant->market_id ?? 0));
                }
            }
        }

        if (! $marketId) {
            $marketId = (int) (
                Market::query()
                    ->where('is_active', true)
                    ->orderBy('id')
                    ->value('id') ?? 0
            );
        }

        if ($marketId > 0) {
            $name = (string) (
                Market::query()
                    ->whereKey($marketId)
                    ->value('name') ?? ''
            );

            if (trim($name) !== '') {
                return $name;
            }
        }

        return (string) config('app.name', 'Market Platform');
    }
}
