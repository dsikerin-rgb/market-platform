<?php

namespace App\Http\Controllers\Cabinet;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class CabinetAuthController extends Controller
{
    public function showLogin(): View
    {
        return view('cabinet.auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $remember = $request->boolean('remember');

        if (! Auth::attempt($credentials, $remember)) {
            return back()
                ->withInput($request->only('email'))
                ->withErrors(['email' => 'Неверный email или пароль.']);
        }

        // на всякий случай фиксируем сессию после успешного входа
        $request->session()->regenerate();

        return redirect()->route('cabinet.dashboard');
    }

    public function logout(Request $request): RedirectResponse
    {
        // выходим из guard web
        Auth::guard('web')->logout();

        // сбрасываем сессию и CSRF токен
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // ВАЖНО: после выхода — в кабинетный login, не в общий /login
        return redirect()->route('cabinet.login');
    }
}
