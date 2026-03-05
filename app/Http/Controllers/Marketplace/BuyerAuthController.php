<?php

declare(strict_types=1);

namespace App\Http\Controllers\Marketplace;

use App\Models\User;
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

        if ($user instanceof User && method_exists($user, 'isBuyer') && $user->isBuyer()) {
            return redirect()->route('marketplace.buyer.dashboard', ['marketSlug' => $market->slug]);
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

        $email = Str::lower(trim((string) $validated['email']));

        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->first();

        if (! $user || ! Hash::check((string) $validated['password'], (string) $user->password)) {
            return back()->withInput($request->only('email'))->withErrors([
                'email' => 'Неверный email или пароль.',
            ]);
        }

        if (! method_exists($user, 'isBuyer') || ! $user->isBuyer()) {
            return back()->withInput($request->only('email'))->withErrors([
                'email' => 'Этот аккаунт не является аккаунтом покупателя.',
            ]);
        }

        if ((int) ($user->market_id ?? 0) !== (int) $market->id) {
            return back()->withInput($request->only('email'))->withErrors([
                'email' => 'Этот аккаунт зарегистрирован на другой ярмарке.',
            ]);
        }

        Auth::login($user, true);
        $request->session()->regenerate();

        return redirect()->route('marketplace.buyer.dashboard', ['marketSlug' => $market->slug]);
    }

    public function showRegister(Request $request, string $marketSlug): View|RedirectResponse
    {
        $market = $this->resolveMarketOrFail($marketSlug);
        $user = $request->user();

        if ($user instanceof User && method_exists($user, 'isBuyer') && $user->isBuyer()) {
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

        return redirect()->route('marketplace.buyer.dashboard', ['marketSlug' => $market->slug])
            ->with('success', 'Аккаунт покупателя создан.');
    }

    public function logout(Request $request, string $marketSlug): RedirectResponse
    {
        $market = $this->resolveMarketOrFail($marketSlug);

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('marketplace.home', ['marketSlug' => $market->slug]);
    }
}
