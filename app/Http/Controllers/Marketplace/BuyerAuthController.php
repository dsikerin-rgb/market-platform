<?php

declare(strict_types=1);

namespace App\Http\Controllers\Marketplace;

use App\Models\Market;
use App\Models\User;
use App\Services\Auth\PortalAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;

class BuyerAuthController extends BaseMarketplaceController
{
    public function showLogin(Request $request, string $marketSlug): View|RedirectResponse
    {
        $market = $this->resolveMarketOrFail($marketSlug);
        $user = $request->user();
        $access = app(PortalAccessService::class);

        if ($user instanceof User) {
            $mode = $this->resolvePreferredMode($request, $user, $market, $access);

            if ($mode !== null) {
                $request->session()->put(PortalAccessService::SESSION_ACTIVE_MODE, $mode);

                return $this->redirectForMode($market->slug, $mode);
            }
        }

        return view('marketplace.auth.login', array_merge(
            $this->sharedViewData($request, $market),
            ['marketSlug' => $market->slug],
        ));
    }

    public function login(Request $request, string $marketSlug): RedirectResponse
    {
        $market = $this->resolveMarketOrFail($marketSlug);
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);
        $access = app(PortalAccessService::class);

        $email = Str::lower(trim((string) $validated['email']));

        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->first();

        if (! $user || ! Hash::check((string) $validated['password'], (string) $user->password)) {
            return back()->withInput($request->only('email'))->withErrors([
                'email' => 'Неверный email или пароль.',
            ]);
        }

        if (! $access->isInMarket($user, $market)) {
            return back()->withInput($request->only('email'))->withErrors([
                'email' => 'Этот аккаунт зарегистрирован на другой ярмарке.',
            ]);
        }

        $mode = $access->defaultMode($user, $market);
        if ($mode === null) {
            return back()->withInput($request->only('email'))->withErrors([
                'email' => 'Для этого аккаунта вход в маркетплейс недоступен.',
            ]);
        }

        Auth::login($user, true);
        $request->session()->regenerate();
        $request->session()->put(PortalAccessService::SESSION_ACTIVE_MODE, $mode);

        return $this->redirectForMode($market->slug, $mode);
    }

    public function showRegister(Request $request, string $marketSlug): View|RedirectResponse
    {
        $market = $this->resolveMarketOrFail($marketSlug);
        $user = $request->user();
        $access = app(PortalAccessService::class);

        if ($user instanceof User && $access->canUseMarketplaceBuyer($user, $market)) {
            $request->session()->put(PortalAccessService::SESSION_ACTIVE_MODE, PortalAccessService::MODE_BUYER);

            return redirect()->route('marketplace.buyer.dashboard', ['marketSlug' => $market->slug]);
        }

        return view('marketplace.auth.register', array_merge(
            $this->sharedViewData($request, $market),
            ['marketSlug' => $market->slug],
        ));
    }

    public function register(Request $request, string $marketSlug): RedirectResponse
    {
        $market = $this->resolveMarketOrFail($marketSlug);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $user = User::query()->create([
            'name' => trim((string) $validated['name']),
            'email' => Str::lower(trim((string) $validated['email'])),
            'password' => (string) $validated['password'],
            'market_id' => (int) $market->id,
            'tenant_id' => null,
        ]);

        $buyerRole = Role::findOrCreate('buyer', 'web');
        $user->syncRoles([$buyerRole->name]);

        Auth::login($user, true);
        $request->session()->regenerate();
        $request->session()->put(PortalAccessService::SESSION_ACTIVE_MODE, PortalAccessService::MODE_BUYER);

        return redirect()->route('marketplace.buyer.dashboard', ['marketSlug' => $market->slug])
            ->with('success', 'Аккаунт покупателя создан.');
    }

    public function logout(Request $request, string $marketSlug): RedirectResponse
    {
        $market = $this->resolveMarketOrFail($marketSlug);

        Auth::guard('web')->logout();
        $request->session()->forget(PortalAccessService::SESSION_ACTIVE_MODE);
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('marketplace.home', ['marketSlug' => $market->slug]);
    }

    private function resolvePreferredMode(Request $request, User $user, Market $market, PortalAccessService $access): ?string
    {
        $sessionMode = (string) $request->session()->get(PortalAccessService::SESSION_ACTIVE_MODE, '');
        if ($sessionMode !== '') {
            return $this->sanitizeAllowedMode($sessionMode, $user, $market, $access);
        }

        return $access->defaultMode($user, $market);
    }

    private function sanitizeAllowedMode(string $mode, User $user, Market $market, PortalAccessService $access): ?string
    {
        if ($mode === PortalAccessService::MODE_SELLER && $access->canUseSellerCabinet($user) && $access->isInMarket($user, $market)) {
            return PortalAccessService::MODE_SELLER;
        }

        if ($mode === PortalAccessService::MODE_BUYER && $access->canUseMarketplaceBuyer($user, $market)) {
            return PortalAccessService::MODE_BUYER;
        }

        return null;
    }

    private function redirectForMode(string $marketSlug, string $mode): RedirectResponse
    {
        if ($mode === PortalAccessService::MODE_SELLER) {
            return redirect()->route('cabinet.dashboard');
        }

        return redirect()->route('marketplace.buyer.dashboard', ['marketSlug' => $marketSlug]);
    }
}
