@extends('marketplace.layout')

@section('title', 'Вход')

@section('content')
    <section class="mp-card" style="max-width:640px;margin:0 auto;">
        <h1 class="mp-page-title" style="font-size:30px;">Вход в аккаунт</h1>
        <p class="mp-page-sub">
            Одна учётная запись для покупок на маркетплейсе и работы в кабинете арендатора.
            Если у аккаунта есть доступ продавца, после входа откроется кабинет арендатора.
        </p>

        <div style="margin-top:18px;border:1px solid #d7e7f8;border-radius:16px;padding:16px;background:#f8fbff;">
            <form method="post" action="{{ route('marketplace.login.submit', ['marketSlug' => $market->slug]) }}" style="display:grid;gap:10px;">
                @csrf

                <label style="display:grid;gap:6px;">
                    <span class="mp-muted">Email</span>
                    <input
                        type="email"
                        name="email"
                        value="{{ old('email') }}"
                        required
                        autocomplete="email"
                        style="border:1px solid #cbdcf3;border-radius:12px;padding:11px 12px;"
                    >
                </label>

                <label style="display:grid;gap:6px;">
                    <span class="mp-muted">Пароль</span>
                    <input
                        type="password"
                        name="password"
                        required
                        autocomplete="current-password"
                        style="border:1px solid #cbdcf3;border-radius:12px;padding:11px 12px;"
                    >
                </label>

                <button class="mp-btn mp-btn-brand" type="submit">Войти</button>
            </form>

            <p class="mp-muted" style="margin:12px 0 0;">
                Нет аккаунта покупателя?
                <a href="{{ route('marketplace.register', ['marketSlug' => $market->slug]) }}" style="color:#0a84d6;font-weight:700;">
                    Зарегистрироваться
                </a>
            </p>
        </div>
    </section>
@endsection
