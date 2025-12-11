<?php

namespace App\Http\Controllers\Auth;

use App\Models\Market;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class MarketRegistrationController
{
    public function create(): View
    {
        $timezones = array_combine(timezone_identifiers_list(), timezone_identifiers_list());

        return view('auth.register-market', [
            'timezones' => $timezones,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(
            [
                'market_name' => ['required', 'string', 'max:255'],
                'market_code' => ['nullable', 'string', 'max:255'],
                'market_address' => ['nullable', 'string', 'max:255'],
                'market_timezone' => ['required', 'string'],
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                'password' => ['required', 'string', 'min:8', 'confirmed'],
            ],
            [
                'market_name.required' => 'Название рынка обязательно для заполнения.',
                'market_timezone.required' => 'Выберите часовой пояс рынка.',
                'name.required' => 'Укажите имя пользователя.',
                'email.required' => 'Укажите email.',
                'email.email' => 'Введите корректный email.',
                'email.unique' => 'Пользователь с таким email уже зарегистрирован.',
                'password.required' => 'Укажите пароль.',
                'password.min' => 'Пароль должен содержать не менее 8 символов.',
                'password.confirmed' => 'Пароль и подтверждение не совпадают.',
            ],
        );

        $user = DB::transaction(function () use ($data) {
            $market = Market::create([
                'name' => $data['market_name'],
                'code' => $data['market_code'] ?? null,
                'address' => $data['market_address'] ?? null,
                'timezone' => $data['market_timezone'],
                'is_active' => true,
            ]);

            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'market_id' => $market->id,
            ]);

            $user->assignRole('market-admin');

            return $user;
        });

        Auth::login($user);

        return redirect('/admin');
    }
}
